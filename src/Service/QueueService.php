<?php

declare(strict_types=1);

namespace Goletter\Server\Service;

use Goletter\Server\BaseService;
use Goletter\Server\Job\QueueableJob;
use Goletter\Server\Job\SerialJob;
use Goletter\Server\Job\TraceableJob;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\DriverInterface;
use Hyperf\AsyncQueue\JobInterface;
use Hyperf\Context\Context;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Redis\RedisFactory;
use Hyperf\Redis\RedisProxy;
use InvalidArgumentException;
use Throwable;

class QueueService extends BaseService
{
    const QUEUE_DEFAULT = 'default';

    public function __construct(
        private DriverFactory $driverFactory,
        private ConfigInterface $config,
        private RedisFactory $redisFactory,
    ) {
    }

    /**
     * 获取队列驱动
     */
    protected function getDriver(string $queue = self::QUEUE_DEFAULT): DriverInterface
    {
        return $this->driverFactory->get($queue);
    }

    /**
     * 解析投递队列：显式传入优先，其次 Job 声明的队列（QueueableJob），最后 default。
     */
    public function resolveQueue(JobInterface $jobObj, ?string $queue = null): string
    {
        if ($queue !== null && $queue !== '') {
            return $queue;
        }

        if ($jobObj instanceof QueueableJob) {
            return $jobObj->queue();
        }

        return self::QUEUE_DEFAULT;
    }

    /**
     * 推送任务到指定队列
     */
    public function push(JobInterface $jobObj, ?string $queue = null, int $delay = 0): bool
    {
        $queue = $this->resolveQueue($jobObj, $queue);

        return $this->getDriver($queue)->push($this->withTrace($jobObj), $delay);
    }

    /**
     * 延迟推送任务
     */
    public function delay(JobInterface $jobObj, int $delay, ?string $queue = null): bool
    {
        return $this->push($jobObj, $queue, $delay);
    }

    /**
     * 批量推送任务（并行入队，可同时被多个 worker 消费）
     */
    public function pushBatch(array $jobs, ?string $queue = null): array
    {
        $results = [];
        foreach ($jobs as $job) {
            $results[] = $this->push($job, $queue, 0);
        }
        return $results;
    }

    /**
     * 任务链：按顺序执行，上一步成功后才执行下一步；某步失败（重试耗尽）则中断，
     * 剩余步骤转入失败列表，可用 retryChain() 从失败的那一步重跑。
     *
     * 步骤整体存入 Redis，复用串行队列的 runner 租约与巡检：worker 被杀、投递失败后会自动续跑。
     * 被恢复的那一步可能重复执行，步骤需幂等。
     *
     * $delay：每步成功后到下一步的间隔，失败重试的间隔也用它（单位与队列驱动一致：default 秒，ms 池毫秒）。
     * $maxAttempts：每步最多执行次数（含首次），默认 1 = 失败即中断。
     *
     * @param JobInterface[] $jobs
     * @return string 链 ID（串行 key）
     */
    public function chain(array $jobs, ?string $queue = null, int $delay = 0, int $maxAttempts = 1): string
    {
        if ($jobs === []) {
            throw new InvalidArgumentException('Job chain must contain at least one job.');
        }

        $jobs = array_values($jobs);
        foreach ($jobs as $index => $job) {
            if (! $job instanceof JobInterface) {
                throw new InvalidArgumentException(sprintf(
                    'Chain job at index %d must implement %s.',
                    $index,
                    JobInterface::class
                ));
            }
        }

        $queue = $this->resolveQueue($jobs[0], $queue);
        $key = 'chain:' . $this->newSerialToken();

        $payloads = [];
        foreach ($jobs as $job) {
            $payloads[] = serialize([
                'job' => $this->withTrace($job),
                'delay' => max(0, $delay),
                'attempts' => 0,
                'maxAttempts' => max(1, $maxAttempts),
                'stopOnFailure' => true,
            ]);
        }

        $this->enqueueSerial($key, $queue, $payloads);

        return $key;
    }

    /**
     * 按 key 串行入队：可多次、陆续调用；同一 key 下任务按 FIFO 执行，不同 key 互不影响。
     *
     * 同一 key 同一时刻只有一个持有 runner 租约的 SerialJob；入队与抢租约在同一段 Lua 中完成。
     * runner 丢失（进程被杀、投递失败）后租约到期，由下一次 pushSerial 或巡检（recoverSerial）重新拉起。
     *
     * $delay：本条结束后（成功，或失败且仍要重试/推进下一条）再隔多久调度（单位与驱动一致）。
     * $maxAttempts：本条最多执行次数（含首次）。失败未达上限时按 $delay 重试同一条；耗尽后丢弃并继续下一条。
     */
    public function pushSerial(
        string $key,
        JobInterface $job,
        ?string $queue = null,
        int $delay = 0,
        int $maxAttempts = 1,
    ): bool {
        $key = trim($key);
        if ($key === '') {
            throw new InvalidArgumentException('Serial queue key must not be empty.');
        }

        $queue = $this->resolveQueue($job, $queue);

        $this->enqueueSerial($key, $queue, [serialize([
            'job' => $this->withTrace($job),
            'delay' => max(0, $delay),
            'attempts' => 0,
            'maxAttempts' => max(1, $maxAttempts),
        ])]);

        return true;
    }

    /**
     * 原子地追加条目并尝试抢 runner 租约，抢到则投递 runner。
     *
     * @param string[] $payloads
     */
    private function enqueueSerial(string $key, string $queue, array $payloads): void
    {
        $token = $this->newSerialToken();
        $redis = $this->redis($queue);

        // 先登记再入队：进程在两步之间挂掉也能被巡检发现；空列表的登记会在 idle_seconds 后被清理
        $redis->zAdd($this->serialRegistryKey($queue), time(), $key);

        $script = <<<'LUA'
for i = 3, #ARGV do
  redis.call('RPUSH', KEYS[1], ARGV[i])
end
if redis.call('SET', KEYS[2], ARGV[1], 'NX', 'PX', ARGV[2]) then
  return 1
end
return 0
LUA;

        $acquired = (int) $redis->eval(
            $script,
            array_merge(
                [
                    $this->serialWaitingKey($key),
                    $this->serialRunnerKey($key),
                    $token,
                    (string) $this->serialRunnerLeaseMs(),
                ],
                $payloads
            ),
            2
        );

        if ($acquired === 1) {
            $this->startSerialRunner($key, $queue, $token);
        }
    }

    /**
     * 失败的任务链列表（最近失败的在前）。已过期（failed_ttl_seconds）的记录会被顺带清理。
     *
     * @return array<int, array{id: string, queue: string, steps: int, error: string, exception: string, failed_at: string}>
     */
    public function failedChains(?string $queue = null, int $limit = 100): array
    {
        $result = [];
        foreach ($this->serialQueues($queue) as $name) {
            $redis = $this->redis($name);
            $registryKey = $this->chainFailedRegistryKey($name);
            $infoKey = $this->chainFailedInfoKey($name);

            $ids = (array) $redis->zRevRange($registryKey, 0, max(1, $limit) - 1);
            foreach ($ids as $id) {
                $id = (string) $id;
                $steps = (int) $redis->lLen($this->serialFailedKey($id));
                if ($steps === 0) {
                    $redis->zRem($registryKey, $id);
                    $redis->hDel($infoKey, $id);
                    continue;
                }

                $info = json_decode((string) $redis->hGet($infoKey, $id), true) ?: [];
                $result[] = [
                    'id' => $id,
                    'queue' => $name,
                    'steps' => $steps,
                    'error' => (string) ($info['error'] ?? ''),
                    'exception' => (string) ($info['exception'] ?? ''),
                    'failed_at' => (string) ($info['failed_at'] ?? ''),
                ];
            }
        }

        return $result;
    }

    /**
     * 从失败的那一步重新执行任务链（该步执行次数重新计算）。
     *
     * @return bool false 表示没有找到该失败链，或该链已在执行中
     */
    public function retryChain(string $chainId, ?string $queue = null): bool
    {
        $queue ??= $this->findFailedChainQueue($chainId);
        if ($queue === null) {
            return false;
        }

        $redis = $this->redis($queue);
        $token = $this->newSerialToken();
        $redis->zAdd($this->serialRegistryKey($queue), time(), $chainId);

        $script = <<<'LUA'
if redis.call('EXISTS', KEYS[3]) == 0 then
  return -1
end
if redis.call('EXISTS', KEYS[1]) == 1 then
  return -2
end
redis.call('RENAME', KEYS[3], KEYS[1])
redis.call('PERSIST', KEYS[1])
if redis.call('SET', KEYS[2], ARGV[1], 'NX', 'PX', ARGV[2]) then
  return 1
end
return 0
LUA;

        $result = (int) $redis->eval(
            $script,
            [
                $this->serialWaitingKey($chainId),
                $this->serialRunnerKey($chainId),
                $this->serialFailedKey($chainId),
                $token,
                (string) $this->serialRunnerLeaseMs(),
            ],
            3
        );

        if ($result < 0) {
            return false;
        }

        $redis->zRem($this->chainFailedRegistryKey($queue), $chainId);
        $redis->hDel($this->chainFailedInfoKey($queue), $chainId);

        if ($result === 1) {
            $this->startSerialRunner($chainId, $queue, $token);
        }

        return true;
    }

    /**
     * 删除失败任务链的记录与剩余步骤。
     */
    public function forgetFailedChain(string $chainId, ?string $queue = null): bool
    {
        $queue ??= $this->findFailedChainQueue($chainId);
        if ($queue === null) {
            return false;
        }

        $redis = $this->redis($queue);
        $redis->del($this->serialFailedKey($chainId));
        $redis->hDel($this->chainFailedInfoKey($queue), $chainId);

        return (int) $redis->zRem($this->chainFailedRegistryKey($queue), $chainId) > 0;
    }

    /**
     * @internal used by SerialJob
     */
    public function recordFailedChain(string $chainId, string $queue, Throwable $e): void
    {
        $redis = $this->redis($queue);
        $redis->hSet($this->chainFailedInfoKey($queue), $chainId, json_encode([
            'error' => mb_substr($e->getMessage(), 0, 1000),
            'exception' => $e::class,
            'failed_at' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE));
        $redis->zAdd($this->chainFailedRegistryKey($queue), time(), $chainId);
    }

    private function findFailedChainQueue(string $chainId): ?string
    {
        foreach ($this->serialQueues(null) as $name) {
            if ($this->redis($name)->zScore($this->chainFailedRegistryKey($name), $chainId) !== false) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function serialQueues(?string $queue): array
    {
        if ($queue !== null) {
            return [$queue];
        }

        return array_map('strval', array_keys((array) $this->config->get('async_queue', [])));
    }

    /**
     * 查看某 key 串行等待队列长度（含正在执行的那条，执行中仍占 list 头部）。
     */
    public function serialWaitingCount(string $key, string $queue = self::QUEUE_DEFAULT): int
    {
        return (int) $this->redis($queue)->lLen($this->serialWaitingKey($key));
    }

    /**
     * 巡检：为「等待列表非空但 runner 租约已失效」的 key 重新拉起 runner，并清理空闲的 key。
     *
     * 可在多个实例上同时运行：租约用 SET NX 抢占，不会产生重复 runner。
     *
     * @param null|string $queue null 表示 async_queue 中配置的全部队列
     * @param int $idleSeconds 等待列表为空且超过该时长没有入队的 key 才从登记表移除
     * @return array<string, array{checked: int, recovered: int, removed: int}>
     */
    public function recoverSerial(?string $queue = null, int $idleSeconds = 60): array
    {
        $queues = $this->serialQueues($queue);

        $recoverScript = <<<'LUA'
if redis.call('LLEN', KEYS[1]) == 0 then
  return 0
end
if redis.call('SET', KEYS[2], ARGV[1], 'NX', 'PX', ARGV[2]) then
  return 1
end
return 0
LUA;

        $removeScript = <<<'LUA'
local score = redis.call('ZSCORE', KEYS[1], ARGV[1])
if score and tonumber(score) == tonumber(ARGV[2]) then
  return redis.call('ZREM', KEYS[1], ARGV[1])
end
return 0
LUA;

        $stats = [];
        foreach ($queues as $name) {
            $name = (string) $name;
            $redis = $this->redis($name);
            $registryKey = $this->serialRegistryKey($name);
            $result = ['checked' => 0, 'recovered' => 0, 'removed' => 0];
            $now = time();

            $cursor = null;
            do {
                $members = $redis->zScan($registryKey, $cursor, null, 200);
                if (! is_array($members)) {
                    break;
                }

                foreach ($members as $key => $score) {
                    $key = (string) $key;
                    ++$result['checked'];

                    if ((int) $redis->lLen($this->serialWaitingKey($key)) === 0) {
                        if ($now - (int) $score >= $idleSeconds) {
                            $result['removed'] += (int) $redis->eval(
                                $removeScript,
                                [$registryKey, $key, (string) $score],
                                1
                            );
                        }
                        continue;
                    }

                    $token = $this->newSerialToken();
                    $acquired = (int) $redis->eval(
                        $recoverScript,
                        [
                            $this->serialWaitingKey($key),
                            $this->serialRunnerKey($key),
                            $token,
                            (string) $this->serialRunnerLeaseMs(),
                        ],
                        2
                    );

                    if ($acquired === 1) {
                        $this->startSerialRunner($key, $name, $token);
                        ++$result['recovered'];
                    }
                }
            } while ($cursor);

            $stats[$name] = $result;
        }

        return $stats;
    }

    /**
     * @internal used by SerialJob
     */
    public function serialWaitingKey(string $key): string
    {
        // {key} 是 Redis Cluster hash tag，保证同一 key 的 waiting / runner 在同一槽位，Lua 才能同时操作
        return 'queue-serial:{' . $key . '}:waiting';
    }

    /**
     * 当前持有执行权的 SerialJob 令牌（带过期时间的租约）。
     *
     * @internal used by SerialJob
     */
    public function serialRunnerKey(string $key): string
    {
        return 'queue-serial:{' . $key . '}:runner';
    }

    /**
     * 失败任务链的剩余步骤（队头为失败的那一步）。
     */
    public function serialFailedKey(string $key): string
    {
        return 'queue-serial:{' . $key . '}:failed';
    }

    /**
     * 失败任务链登记表（zset，score 为失败时间）。
     */
    public function chainFailedRegistryKey(string $queue): string
    {
        return 'queue-serial:failed:' . $queue;
    }

    /**
     * 失败任务链的错误信息（hash，field 为链 ID）。
     */
    public function chainFailedInfoKey(string $queue): string
    {
        return 'queue-serial:failed-info:' . $queue;
    }

    /**
     * 失败任务链保留时长（秒），0 = 永久保留。
     */
    public function chainFailedTtlSeconds(): int
    {
        return max(0, (int) ($this->config->get('queue_serial.chain.failed_ttl_seconds') ?? 604800));
    }

    /**
     * 串行 key 登记表（zset，score 为最后一次入队时间），供巡检遍历。
     */
    public function serialRegistryKey(string $queue): string
    {
        return 'queue-serial:keys:' . $queue;
    }

    /**
     * Global SerialJob concurrency key (cross all serial keys).
     *
     * @internal used by SerialJob
     */
    public function serialConcurrentKey(): string
    {
        return 'queue-serial:concurrent';
    }

    /**
     * 0 = unlimited. Caps how many SerialJob handlers may run at once (all keys).
     */
    public function serialMaxConcurrent(): int
    {
        return max(0, (int) ($this->config->get('queue_serial.max_concurrent') ?? 32));
    }

    /**
     * 并发名额已满时的退避时间，按队列驱动的单位返回（default 为秒，ms 池为毫秒）。
     */
    public function serialBusyDelay(string $queue = self::QUEUE_DEFAULT): int
    {
        $ms = $this->config->get('queue_serial.busy_delay_ms');
        if ($ms === null) {
            // 兼容旧配置 busy_delay（秒）
            $ms = (int) ($this->config->get('queue_serial.busy_delay') ?? 1) * 1000;
        }

        return max(1, $this->msToDriverDelay($queue, max(1, (int) $ms)));
    }

    public function serialSlotLeaseSeconds(): int
    {
        return max(30, (int) ($this->config->get('queue_serial.slot_lease_seconds') ?? 600));
    }

    /**
     * runner 租约时长（毫秒）。执行期间每 1/3 周期续租一次；worker 被杀后最多这么久会被重新拉起。
     */
    public function serialRunnerLeaseMs(): int
    {
        return max(30, (int) ($this->config->get('queue_serial.runner_lease_seconds') ?? 300)) * 1000;
    }

    /**
     * 该队列驱动的 delay 是否以毫秒为单位（goletter/hyperf-queue 的 RedisMsDriver）。
     */
    public function isMillisecondQueue(string $queue): bool
    {
        $driver = (string) ($this->config->get('async_queue.' . $queue . '.driver') ?? '');

        return $driver !== '' && is_a($driver, 'Goletter\Queue\Driver\RedisMsDriver', true);
    }

    public function driverDelayToMs(string $queue, int $delay): int
    {
        $delay = max(0, $delay);

        return $this->isMillisecondQueue($queue) ? $delay : $delay * 1000;
    }

    public function msToDriverDelay(string $queue, int $ms): int
    {
        $ms = max(0, $ms);

        return $this->isMillisecondQueue($queue) ? $ms : (int) ceil($ms / 1000);
    }

    /**
     * 校验并轮换 runner 令牌：只有令牌匹配的 SerialJob 才能执行。
     *
     * 每次执行都换新令牌，被重新投递回来的旧 SerialJob（timeout / failed 列表 reload）会直接失效。
     *
     * @internal used by SerialJob
     */
    public function claimSerialRunner(string $key, string $token, string $newToken, string $queue = self::QUEUE_DEFAULT): bool
    {
        if ($token === '') {
            return false;
        }

        $script = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
  redis.call('SET', KEYS[1], ARGV[2], 'PX', ARGV[3])
  return 1
end
return 0
LUA;

        return (int) $this->redis($queue)->eval(
            $script,
            [$this->serialRunnerKey($key), $token, $newToken, (string) $this->serialRunnerLeaseMs()],
            1
        ) === 1;
    }

    /**
     * @internal used by SerialJob
     */
    public function renewSerialRunner(string $key, string $token, string $queue = self::QUEUE_DEFAULT): bool
    {
        $script = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
  return redis.call('PEXPIRE', KEYS[1], ARGV[2])
end
return 0
LUA;

        return (int) $this->redis($queue)->eval(
            $script,
            [$this->serialRunnerKey($key), $token, (string) $this->serialRunnerLeaseMs()],
            1
        ) === 1;
    }

    /**
     * 原子地推进串行队列，并决定是否需要投递下一个 runner。
     *
     * $mode：pop 弹出队头；keep 保留队头；replace 用 $headPayload 覆盖队头（失败重试）；
     *        clear 清空整个列表；fail 用 $headPayload 覆盖队头后把整个列表移到失败列表（任务链中断，可重跑）。
     * 返回：-1 已失去执行权；0 队列已空、租约已释放；1 需以 $nextToken 投递下一个 runner。
     *
     * @internal used by SerialJob
     */
    public function advanceSerial(
        string $key,
        string $token,
        string $nextToken,
        string $mode,
        int $nextDelayMs = 0,
        string $headPayload = '',
        string $queue = self::QUEUE_DEFAULT,
    ): int {
        $script = <<<'LUA'
if redis.call('GET', KEYS[2]) ~= ARGV[1] then
  return -1
end
if ARGV[3] == 'pop' then
  redis.call('LPOP', KEYS[1])
elseif ARGV[3] == 'replace' and redis.call('LLEN', KEYS[1]) > 0 then
  redis.call('LSET', KEYS[1], 0, ARGV[5])
elseif ARGV[3] == 'clear' then
  redis.call('DEL', KEYS[1])
elseif ARGV[3] == 'fail' and redis.call('LLEN', KEYS[1]) > 0 then
  if ARGV[5] ~= '' then
    redis.call('LSET', KEYS[1], 0, ARGV[5])
  end
  redis.call('RENAME', KEYS[1], KEYS[3])
  if tonumber(ARGV[6]) > 0 then
    redis.call('EXPIRE', KEYS[3], ARGV[6])
  end
end
if redis.call('LLEN', KEYS[1]) == 0 then
  redis.call('DEL', KEYS[2])
  return 0
end
redis.call('SET', KEYS[2], ARGV[2], 'PX', ARGV[4])
return 1
LUA;

        return (int) $this->redis($queue)->eval(
            $script,
            [
                $this->serialWaitingKey($key),
                $this->serialRunnerKey($key),
                $this->serialFailedKey($key),
                $token,
                $nextToken,
                $mode,
                (string) ($this->serialRunnerLeaseMs() + max(0, $nextDelayMs)),
                $headPayload,
                (string) $this->chainFailedTtlSeconds(),
            ],
            3
        );
    }

    /**
     * 投递持有 $token 的 runner；投递失败时释放租约，让下一次 pushSerial 或巡检重新拉起。
     *
     * @internal used by SerialJob
     */
    public function startSerialRunner(string $key, string $queue, string $token, int $delay = 0): void
    {
        try {
            $this->push(new SerialJob($key, $queue, $token), $queue, $delay);
        } catch (Throwable $e) {
            $this->releaseSerialRunner($key, $token, $queue);
            throw $e;
        }
    }

    /**
     * @internal used by SerialJob
     */
    public function releaseSerialRunner(string $key, string $token, string $queue = self::QUEUE_DEFAULT): void
    {
        $script = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
  return redis.call('DEL', KEYS[1])
end
return 0
LUA;

        $this->redis($queue)->eval($script, [$this->serialRunnerKey($key), $token], 1);
    }

    /**
     * @internal used by SerialJob
     */
    public function newSerialToken(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * Acquire a global execution slot. Returns token on success, null when busy.
     *
     * @internal used by SerialJob
     */
    public function acquireSerialSlot(string $queue = self::QUEUE_DEFAULT): ?string
    {
        $max = $this->serialMaxConcurrent();
        if ($max <= 0) {
            return '';
        }

        $token = $this->newSerialToken();
        $now = time();
        $expiredBefore = $now - $this->serialSlotLeaseSeconds();

        $script = <<<'LUA'
redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', ARGV[1])
redis.call('ZADD', KEYS[1], ARGV[2], ARGV[3])
if redis.call('ZCARD', KEYS[1]) > tonumber(ARGV[4]) then
  redis.call('ZREM', KEYS[1], ARGV[3])
  return 0
end
return 1
LUA;

        $ok = (int) $this->redis($queue)->eval(
            $script,
            [
                $this->serialConcurrentKey(),
                (string) $expiredBefore,
                (string) $now,
                $token,
                (string) $max,
            ],
            1
        );

        return $ok === 1 ? $token : null;
    }

    /**
     * 续租全局并发名额，避免长任务的名额被 slot_lease_seconds 提前回收。
     *
     * @internal used by SerialJob
     */
    public function renewSerialSlot(?string $token, string $queue = self::QUEUE_DEFAULT): void
    {
        if ($token === null || $token === '') {
            return;
        }
        $this->redis($queue)->zAdd($this->serialConcurrentKey(), ['XX'], time(), $token);
    }

    /**
     * @internal used by SerialJob
     */
    public function releaseSerialSlot(?string $token, string $queue = self::QUEUE_DEFAULT): void
    {
        if ($token === null || $token === '') {
            return;
        }
        $this->redis($queue)->zRem($this->serialConcurrentKey(), $token);
    }

    /**
     * @internal used by SerialJob
     */
    public function redis(string $queue = self::QUEUE_DEFAULT): RedisProxy
    {
        $queueConfig = $this->config->get('async_queue.' . $queue) ?? [];
        $pool = (string) ($queueConfig['redis']['pool'] ?? 'default');

        return $this->redisFactory->get($pool);
    }

    /**
     *  异步队列是否已全部处理完（无等待、无延时、无执行中）。
     *  Hyperf RedisDriver::info() 不含 reserved；任务被 consumer 取出后在 reserved zset，waiting 可能为 0。
     * @param string $queue
     * @return array
     */
    public function getAsyncQueueCompleted(string $queue = self::QUEUE_DEFAULT): array
    {
        $info = $this->getDriver($queue)->info();
        $queueConfig = $this->config->get('async_queue.' . $queue) ?? [];
        $channel = (string) ($queueConfig['channel'] ?? 'queue');
        $redis = $this->redis($queue);
        $reserved = (int) $redis->zCard($channel . ':reserved');

        $waiting = (int) $info['waiting'];
        $delayed = (int) $info['delayed'];
        $failed = (int) $info['failed'];
        $backlog = $waiting + $delayed;
        $working = $reserved;
        $completed = $backlog === 0 && $working === 0;

        return ['queue' => $queue, 'completed' => $completed, 'working' => $working, 'backlog' => $backlog, 'failed' => $failed];
    }

    private function withTrace(JobInterface $jobObj): JobInterface
    {
        if ($jobObj instanceof TraceableJob) {
            return $jobObj;
        }

        $traceId = (string) Context::get('trace_id', '');
        if ($traceId === '') {
            return $jobObj;
        }

        return new TraceableJob($jobObj, $traceId);
    }
}
