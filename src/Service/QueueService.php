<?php

declare(strict_types=1);

namespace Goletter\Server\Service;

use Goletter\Server\Job\TraceableJob;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\DriverInterface;
use Hyperf\AsyncQueue\JobInterface;
use Hyperf\Context\Context;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Redis\RedisFactory;

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
     * 批量推送任务
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
        $pool = (string) ($queueConfig['redis']['pool'] ?? 'default');
        $redis = $this->redisFactory->get($pool);
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