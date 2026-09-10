<?php

declare(strict_types=1);

namespace Goletter\Server\Service;

use Goletter\Server\Job\ChainJob;
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

class QueueService extends Service
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
     * 推送任务到指定队列
     */
    public function push(JobInterface $jobObj, string $queue = self::QUEUE_DEFAULT, int $delay = 0): bool
    {
        return $this->getDriver($queue)->push($this->withTrace($jobObj), $delay);
    }

    /**
     * 延迟推送任务
     */
    public function delay(JobInterface $jobObj, int $delay, string $queue = self::QUEUE_DEFAULT): bool
    {
        return $this->push($jobObj, $queue, $delay);
    }

    /**
     * 批量推送任务（并行入队，可同时被多个 worker 消费）
     */
    public function pushBatch(array $jobs, string $queue = self::QUEUE_DEFAULT): array
    {
        $results = [];
        foreach ($jobs as $job) {
            $results[] = $this->push($job, $queue, 0);
        }
        return $results;
    }

    /**
     * 任务链：按顺序执行，上一步成功后才入队下一步；任一步失败则中断后续。
     *
     * $delay 单位与对应队列驱动一致（default 一般为秒，ms 池为毫秒）。
     *
     * @param JobInterface[] $jobs
     */
    public function chain(array $jobs, string $queue = self::QUEUE_DEFAULT, int $delay = 0): bool
    {
        if ($jobs === []) {
            throw new InvalidArgumentException('Job chain must contain at least one job.');
        }

        return $this->push(new ChainJob(array_values($jobs), $queue, max(0, $delay)), $queue, 0);
    }

    /**
     * 按 key 串行入队：可多次、陆续调用；同一 key 下任务按 FIFO 执行，不同 key 互不影响。
     *
     * $delay：本条结束后（成功，或失败且仍要重试/推进下一条）再隔多久调度（单位与驱动一致）。
     * $maxAttempts：本条最多执行次数（含首次）。失败未达上限时按 $delay 重试同一条；耗尽后丢弃并继续下一条。
     */
    public function pushSerial(
        string $key,
        JobInterface $job,
        string $queue = self::QUEUE_DEFAULT,
        int $delay = 0,
        int $maxAttempts = 1,
    ): bool {
        $key = trim($key);
        if ($key === '') {
            throw new InvalidArgumentException('Serial queue key must not be empty.');
        }

        $waitingKey = $this->serialWaitingKey($key);
        $payload = serialize([
            'job' => $this->withTrace($job),
            'delay' => max(0, $delay),
            'attempts' => 0,
            'maxAttempts' => max(1, $maxAttempts),
        ]);
        $len = (int) $this->redis($queue)->rPush($waitingKey, $payload);

        // 只有从空列表推进到 1 时启动 runner，保证同一 key 同时只有一条执行链；首个立即执行
        if ($len === 1) {
            return $this->push(new SerialJob($key, $queue), $queue, 0);
        }

        return true;
    }

    /**
     * 查看某 key 串行等待队列长度（不含正在执行的那条，执行中仍占 list 头部）。
     */
    public function serialWaitingCount(string $key, string $queue = self::QUEUE_DEFAULT): int
    {
        return (int) $this->redis($queue)->lLen($this->serialWaitingKey($key));
    }

    /**
     * @internal used by SerialJob
     */
    public function serialWaitingKey(string $key): string
    {
        // hash tag keeps related keys on one Redis Cluster slot
        return '{queue-serial}:' . $key . ':waiting';
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
