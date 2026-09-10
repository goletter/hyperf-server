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

        [$job, $delay] = $this->unpack((string) $payload);
        if (! $job instanceof JobInterface) {
            $redis->lPop($waitingKey);
            $this->continueIfNeeded($queueService, $redis, $waitingKey, 0);
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
            $this->continueIfNeeded($queueService, $redis, $waitingKey, $delay);
            throw $e;
        }

        $redis->lPop($waitingKey);
        $this->continueIfNeeded($queueService, $redis, $waitingKey, $delay);
    }

    /**
     * @return array{0: mixed, 1: int}
     */
    private function unpack(string $payload): array
    {
        $data = unserialize($payload);
        if ($data instanceof JobInterface) {
            return [$data, 0];
        }
        if (is_array($data) && isset($data['job'])) {
            return [$data['job'], max(0, (int) ($data['delay'] ?? 0))];
        }

        return [$data, 0];
    }

    private function continueIfNeeded(QueueService $queueService, mixed $redis, string $waitingKey, int $delay): void
    {
        if ((int) $redis->lLen($waitingKey) > 0) {
            $queueService->push(new self($this->key, $this->queue), $this->queue, $delay);
        }
    }
}
