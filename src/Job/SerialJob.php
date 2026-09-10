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
 * On business-job failure: retry the same head after its delay until maxAttempts is reached,
 * then drop the head and continue with the next job.
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

        $raw = $redis->lIndex($waitingKey, 0);
        if ($raw === false || $raw === null || $raw === '') {
            return;
        }

        $raw = (string) $raw;
        $entry = $this->unpack($raw);
        $job = $entry['job'];
        $delay = $entry['delay'];
        $attempts = $entry['attempts'];
        $maxAttempts = $entry['maxAttempts'];

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
            $attempts++;
            if ($attempts < $maxAttempts) {
                // Keep head; bump attempts; retry same job after delay.
                $redis->lSet($waitingKey, 0, serialize([
                    'job' => $job,
                    'delay' => $delay,
                    'attempts' => $attempts,
                    'maxAttempts' => $maxAttempts,
                ]));
                $queueService->push(new self($this->key, $this->queue), $this->queue, $delay);
                return;
            }

            // Exhausted: drop and move on.
            $redis->lPop($waitingKey);
            $this->continueIfNeeded($queueService, $redis, $waitingKey, $delay);
            throw $e;
        }

        $redis->lPop($waitingKey);
        $this->continueIfNeeded($queueService, $redis, $waitingKey, $delay);
    }

    /**
     * @return array{job: mixed, delay: int, attempts: int, maxAttempts: int}
     */
    private function unpack(string $payload): array
    {
        $data = unserialize($payload);
        if ($data instanceof JobInterface) {
            return [
                'job' => $data,
                'delay' => 0,
                'attempts' => 0,
                'maxAttempts' => 1,
            ];
        }
        if (is_array($data) && isset($data['job'])) {
            return [
                'job' => $data['job'],
                'delay' => max(0, (int) ($data['delay'] ?? 0)),
                'attempts' => max(0, (int) ($data['attempts'] ?? 0)),
                'maxAttempts' => max(1, (int) ($data['maxAttempts'] ?? 1)),
            ];
        }

        return [
            'job' => $data,
            'delay' => 0,
            'attempts' => 0,
            'maxAttempts' => 1,
        ];
    }

    private function continueIfNeeded(QueueService $queueService, mixed $redis, string $waitingKey, int $delay): void
    {
        if ((int) $redis->lLen($waitingKey) > 0) {
            $queueService->push(new self($this->key, $this->queue), $this->queue, $delay);
        }
    }
}
