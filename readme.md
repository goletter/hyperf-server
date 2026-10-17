# goletter/hyperf-server

Hyperf API 服务基础组件：路由、中间件、异步队列封装（含任务链）、模型 Cast 等。

## 安装

```bash
composer require goletter/hyperf-server
```

## 功能概览

| 模块 | 说明 |
|------|------|
| `Router` | 扩展 Hyperf Router，提供 `apiResource` |
| Middleware | CORS、Trace、Locale、幂等、响应格式化、模型绑定等 |
| `QueueService` | 异步队列推送 / 批量 / 任务链 / **按 key 串行**，自动透传 `trace_id` |
| Casts | `Encrypted`、`JsonArrayCast`、`ResourceUrl` 等 |
| `MakeModuleCommand` | 模块脚手架命令 |

## 队列 QueueService

依赖项目已配置 `hyperf/async-queue`（可选配合 [`goletter/hyperf-queue`](../hyperf-queue) 的毫秒池）。

```php
use Goletter\Server\Service\QueueService;
use Hyperf\Context\ApplicationContext;

/** @var QueueService $queue */
$queue = ApplicationContext::getContainer()->get(QueueService::class);
```

### 推送 / 延迟 / 批量

```php
// 立即推送
$queue->push(new SendNotifyJob($id));

// 延迟推送（delay 单位与驱动一致：default 一般为秒，ms 池为毫秒）
$queue->delay(new SendNotifyJob($id), 5);
$queue->push(new SendNotifyJob($id), 'ms', 200);

// 批量并行入队（可被多个 worker 同时消费）
$queue->pushBatch([
    new JobA($id),
    new JobB($id),
]);
```

当 Context 中存在 `trace_id` 时，`push` 会自动包装为 `TraceableJob`，在 `handle()` 内恢复链路上下文。

### 任务链 chain

按顺序执行：上一步成功后才入队下一步；任一步抛异常则**中断后续**。

```php
$queue->chain([
    new CreateOrderJob($dto),
    new DeductStockJob($dto),
    new SendNotifyJob($dto),
]);

// 步与步之间延迟（default 池：秒）
$queue->chain([
    new Step1Job($id),
    new Step2Job($id),
], QueueService::QUEUE_DEFAULT, 2);

// ms 池：步间 200ms（需配置 RedisMsDriver）
$queue->chain([
    new Step1Job($id),
    new Step2Job($id),
], 'ms', 200);

// 每步失败最多执行 3 次（间隔同 $delay），仍失败则中断；返回链 ID
$chainId = $queue->chain([
    new Step1Job($id),
    new Step2Job($id),
], 'default', 5, 3);

// 带 key：同 key 的多条链按入队顺序排队（一条跑完再跑下一条），不同 key 并发
// chain($key, $jobs, $queue = null, $delay = 0, $chainDelay = 0, $maxAttempts = 1)
$queue->chain("user:{$userId}", [
    new Step1Job($id),
    new Step2Job($id),
], null, 10);

// 步间隔 10 秒；本链结束后隔 60 秒再开始同 key 的下一条链
$queue->chain("user:{$userId}", [
    new CreateOrderJob($dto),
    new DeductStockJob($dto),
], null, 10, 60);

// 整链步间隔 10 秒；扣库存成功后隔 60 秒再发通知，扣库存失败重试也是 60
$queue->chain("user:{$userId}", [
    new CreateOrderJob($dto),
    ['job' => new DeductStockJob($dto), 'delay' => 60],
    new SendNotifyJob($dto),
], null, 10);

// 再加上每步失败最多执行 3 次
$queue->chain("user:{$userId}", [
    new CreateOrderJob($dto),
    new DeductStockJob($dto),
], null, 10, 60, 3);
```

带 key 时：

- 与同 key 的 `pushSerial` 共用一条队列，按入队顺序执行
- 某条链失败只移走它自己的剩余步骤，同 key 后面的链继续执行；`retryChain` 重跑时追加到该 key 队尾
- 一条链结束（全部成功或失败中断）后等待 `$chainDelay` 再开始同 key 的下一条链，不使用 `$delay`；默认 0 立即开始

实现要点：

- `$delay` 是整链每一步的间隔；某一步写成 `['job' => new DeductStockJob($dto), 'delay' => 60]` 时只覆盖该步（含它的失败重试），其余步骤仍用 `$delay`。最后一步结束之后仍按 `$chainDelay` 开始下一条链
- 所有步骤一次性原子写入 Redis 列表 `queue-serial:{chain:xxx}:waiting`，由 `SerialJob` 逐步执行，复用串行队列的 runner 租约、续租和巡检
- worker 被杀、投递失败后，租约到期由巡检进程 `queue-serial-recover` 自动续跑；被恢复的那一步可能重复执行，步骤需幂等
- 某步失败（达到 `$maxAttempts`）后链中断，剩余步骤（含失败那步）转入失败列表，默认保留 7 天（`queue_serial.chain.failed_ttl_seconds`）
- 修复问题后可从失败的那一步重跑，已成功的步骤不会再执行：

```php
$queue->failedChains();            // 失败链列表：id / queue / steps / error / failed_at
$queue->retryChain($chainId);      // 从失败那步重跑（该步执行次数重新计算）
$queue->forgetFailedChain($chainId);
```

```bash
php bin/hyperf.php queue:chain-failed                 # 列表
php bin/hyperf.php queue:chain-failed --retry=chain:xxx
php bin/hyperf.php queue:chain-failed --retry-all
php bin/hyperf.php queue:chain-failed --forget=chain:xxx
```

- 队列消息只带链 ID，长链也不会让每条消息越来越大
- 与 `pushBatch` 不同：`chain` 串行，`pushBatch` 并行
- 链内 Job 须可序列化（勿放入 Container、闭包、PDO 等）
- 旧版本入队的 `ChainJob` 仍可正常消费

### 按 key 串行 pushSerial（陆续入队）

适合任务**一个个进来**、事先不知道完整列表的场景。同一 `key` 下 FIFO 串行；不同 `key` 互不阻塞。

```php
// 用户 A 陆续提交 —— 按提交顺序执行
$queue->pushSerial('user:1001', new ProcessTaskJob($task1));
$queue->pushSerial('user:1001', new ProcessTaskJob($task2));

// 间隔 5 秒；失败最多共执行 3 次（含首次），未耗尽则按 delay 重试同一条
$queue->pushSerial('user:1001', new ProcessTaskJob($task3), 'default', 5, 3);

// ms 池：步间 / 重试间隔 200ms
$queue->pushSerial('user:1001', new ProcessTaskJob($task4), 'ms', 200, 3);

// 用户 B 不受 A 阻塞
$queue->pushSerial('user:1002', new ProcessTaskJob($taskX));

$queue->serialWaitingCount('user:1001');
```

| API | 何时用 |
|-----|--------|
| `chain([...])` / `chain($key, [...])` | 步骤已知，一次性投递；失败**中断**后续；带 key 时同 key 的链排队 |
| `pushSerial($key, $job, $queue, $delay, $maxAttempts)` | 陆续入队；同 key FIFO；失败按 delay 重试 |
| `pushBatch([...])` | 全部并行，无顺序 |

实现要点：

- 任务写入 Redis List `queue-serial:{key}:waiting`；同 key 由 runner 租约 `queue-serial:{key}:runner` 保证同一时刻只有一个 `SerialJob` 在跑（入队与抢租约在同一段 Lua 里，原子）
- 每次执行都会轮换租约令牌，被 reload 回来的旧 `SerialJob` 或重复投递的副本直接空转，不会重复执行
- 执行期间每 1/3 租约周期自动续租；worker 被杀导致 runner 丢失时，租约到期后由下一次 `pushSerial` 或巡检进程重新拉起
- `$delay`：成功后到下一条的间隔；失败且未达上限时，也是**重试同一条**的间隔
- `$maxAttempts`：本条最多执行次数（默认 `1` = 失败不重试，直接丢弃并继续下一条）
- 重试耗尽后丢弃本条并继续后续，避免堵死同 key
- 外层 `SerialJob` 不依赖 async-queue 重试（避免双调度）
- **全局并发**：默认最多 32 个 `SerialJob` 同时跑（跨 key），减轻「Too many open files」；满了的 runner 进入 FIFO 等待队列，名额释放时按顺序直接分配，不空转、不饿死
- **单步最长执行时间** `max_run_seconds`（默认 3600）：超过后停止续租，由巡检接手重跑该步（原执行可能仍在跑，步骤需幂等）
- **异常中断上限** `max_lost_runs`（默认 3）：同一步因 worker 被杀或超时被接管达到该次数，按失败处理（`pushSerial` 丢弃该条，任务链进入失败列表），避免无限重跑

```bash
php bin/hyperf.php vendor:publish goletter/hyperf-server
```

`config/autoload/queue_serial.php`：

```php
return [
    'max_concurrent' => 32,       // 0 = 不限制
    'slot_wait_seconds' => 3600,  // 排队等名额时保留执行权的时长
    'slot_lease_seconds' => 600,
    'runner_lease_seconds' => 300,
    'max_run_seconds' => 3600,    // 0 = 不限制
    'max_lost_runs' => 3,
    'recover' => [
        'enable' => true,         // 随服务启动 queue-serial-recover 巡检进程
        'interval' => 30,
        'idle_seconds' => 60,
    ],
    'chain' => [
        'failed_ttl_seconds' => 604800, // 失败链保留时长，0 = 永久
    ],
];
```

手动巡检：

```bash
php bin/hyperf.php queue:serial-recover            # 全部队列
php bin/hyperf.php queue:serial-recover --queue=ms
```

数据量大时建议同时：调低 `async_queue.*.concurrent.limit`、提高进程 `ulimit -n`、日志尽量打 stdout/stderr（避免每条日志抢文件句柄）。
### 队列是否空闲

```php
$status = $queue->getAsyncQueueCompleted('default');
// ['queue' => 'default', 'completed' => bool, 'working' => int, 'backlog' => int, 'failed' => int]
```

会统计 `waiting`、`delayed` 以及 Redis `reserved`（官方 `info()` 不含 reserved）。

## 路由 apiResource

```php
use Goletter\Server\Router\Router;

Router::apiResource('users', App\Controller\UserController::class);
// GET/POST /users
// GET/PUT|PATCH/DELETE /users/{user}
```

## License

MIT
