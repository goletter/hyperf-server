<?php

declare(strict_types=1);

namespace Goletter\Server\Job;

/**
 * 声明任务默认投递的队列（async_queue 配置中的 key，如 default / ms）。
 * QueueService 未显式传入 $queue 时使用。
 */
interface QueueableJob
{
    public function queue(): string;
}
