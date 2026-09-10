<?php

declare(strict_types=1);

namespace Goletter\Server\Job;

use Goletter\Server\Service\QueueService;
use Hyperf\AsyncQueue\Job;
use Hyperf\AsyncQueue\JobInterface;
use Hyperf\Context\ApplicationContext;
use RuntimeException;
use Throwable;

/**
 * Drains one job from a per-key Redis waiting list, then enqueues itself again if more remain.
 *
 * Used by QueueService::pushSerial() so callers can enqueue jobs one-by-one while keeping order.
 */
class SerialJob extends Job
{
    public function __construct(
        public string $key,
        public string $queue = QueueService::QUEUE_DEFAULT,
    ) {
    }

    public function handle(): void
    {
        /** @var QueueService $queueService */
        $queueService = ApplicationContext::getContainer()->get(QueueService::class);
        $waitingKey = $queueService->serialWaitingKey($this->key);
        $redis = $queueService->redis($this->queue);

        $payload = $redis->lIndex($waitingKey, 0);
        if ($payload === false || $payload === null || $payload === '') {
            return;
        }

        $job = unserialize((string) $payload);
        if (! $job instanceof JobInterface) {
            $redis->lPop($waitingKey);
            $this->continueIfNeeded($queueService, $redis, $waitingKey);
            throw new RuntimeException(sprintf(
                'Serial waiting list "%s" contains a non-JobInterface payload.',
                $this->key
            ));
        }

        try {
            $job->handle();
        } catch (Throwable $e) {
            // Drop the failed head so later jobs for this key are not blocked forever.
            $redis->lPop($waitingKey);
            $this->continueIfNeeded($queueService, $redis, $waitingKey);
            throw $e;
        }

        $redis->lPop($waitingKey);
        $this->continueIfNeeded($queueService, $redis, $waitingKey);
    }

    private function continueIfNeeded(QueueService $queueService, mixed $redis, string $waitingKey): void
    {
        if ((int) $redis->lLen($waitingKey) > 0) {
            $queueService->push(new self($this->key, $this->queue), $this->queue, 0);
        }
    }
}
