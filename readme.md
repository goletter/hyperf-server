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
```

实现要点：

- 入口只入队一个 `ChainJob`；当前步在同一次消费中执行，成功后再 `push` 剩余链
- 与 `pushBatch` 不同：`chain` 串行，`pushBatch` 并行
- `ChainJob` 本身不重试（`maxAttempts = 0`），避免失败重试导致链被重复推进
- 链内 Job 须可序列化（勿放入 Container、闭包、PDO 等）

### 按 key 串行 pushSerial（陆续入队）

适合任务**一个个进来**、事先不知道完整列表的场景。同一 `key` 下 FIFO 串行；不同 `key` 互不阻塞。

```php
// 用户 A 陆续提交 —— 按提交顺序执行
$queue->pushSerial('user:1001', new ProcessTaskJob($task1));
$queue->pushSerial('user:1001', new ProcessTaskJob($task2));
$queue->pushSerial('user:1001', new ProcessTaskJob($task3));

// 用户 B 不受 A 阻塞，可并行
$queue->pushSerial('user:1002', new ProcessTaskJob($taskX));

// 查看某 key 仍在等待（含正在执行的头部）的数量
$queue->serialWaitingCount('user:1001');
```

| API | 何时用 |
|-----|--------|
| `chain([...])` | 步骤已知，一次性投递整条链；失败**中断**后续 |
| `pushSerial($key, $job)` | 任务陆续到达；同 key 排队；失败**跳过该步**继续后续 |
| `pushBatch([...])` | 全部并行，无顺序 |

实现要点：

- 任务先写入 Redis List `{queue-serial}:{key}:waiting`
- 仅当列表从空变为 1 时启动一个 `SerialJob` runner
- runner 执行头部任务后 `LPOP`，若还有剩余再入队下一个 `SerialJob`
- 当前步失败会丢弃该步并继续，避免堵死同 key 队列

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
