<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace Goletter\Server\Service;

use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\DriverInterface;
use Hyperf\AsyncQueue\JobInterface;

class QueueService extends Service
{
    private DriverFactory $driverFactory;

    const QUEUE_DEFAULT = 'default';

    public function __construct(DriverFactory $driverFactory)
    {
        $this->driverFactory = $driverFactory;
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
        return $this->getDriver($queue)->push($jobObj, $delay);
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
}