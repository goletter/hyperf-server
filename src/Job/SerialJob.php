<?php

declare(strict_types=1);

namespace Goletter\Server\Job;

use Goletter\Server\Service\QueueService;
use Hyperf\AsyncQueue\Job;
use Hyperf\AsyncQueue\JobInterface;
use Hyperf\Context\ApplicationContext;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Engine\Channel;
use RuntimeException;
use Throwable;

/**
 * Drains one job from a per-key Redis waiting list, then hands the runner lease to the next SerialJob if more remain.
 *
 * Only the SerialJob whose token matches the per-key runner lease may execute; the token rotates on every run,
 * so stale copies (re-queued from timeout / failed lists, duplicated deliveries) become no-ops.
 *
 * On business-job failure: retry the same head after its delay until maxAttempts is reached,
 * then drop the head and continue with the next job — or, for chain steps (stopOnFailure), move the remaining
 * steps to the failed list so the chain can be retried from the failed step.
 *
 * A global concurrency slot prevents too many serial keys from running at once (FD / resource storm).
 */
class SerialJob extends Job
{
    public function __construct(
        public string $key,
        public string $queue = QueueService::QUEUE_DEFAULT,
        public string $token = '',
    ) {
    }

    public function handle(): void
    {
        /** @var QueueService $queueService */
        $queueService = ApplicationContext::getContainer()->get(QueueService::class);

        $runToken = $queueService->newSerialToken();
        if (! $queueService->claimSerialRunner($this->key, $this->token, $runToken, $this->queue)) {
            return;
        }

        $slot = $queueService->acquireSerialSlot($this->queue);
        if ($slot === null) {
            // Too many SerialJobs running — back off without touching the waiting head.
            $busyDelay = $queueService->serialBusyDelay($this->queue);
            $this->handOver($queueService, $runToken, 'keep', $busyDelay);
            return;
        }

        $renewer = $this->startRenewer($queueService, $runToken, $slot);
        try {
            $this->process($queueService, $runToken);
        } finally {
            $renewer?->close();
            $queueService->releaseSerialSlot($slot, $this->queue);
        }
    }

    private function process(QueueService $queueService, string $runToken): void
    {
        $raw = $queueService->redis($this->queue)->lIndex($queueService->serialWaitingKey($this->key), 0);
        if ($raw === false || $raw === null || $raw === '') {
            $this->handOver($queueService, $runToken, 'keep', 0);
            return;
        }

        $entry = $this->unpack((string) $raw);
        $job = $entry['job'];
        $delay = $entry['delay'];
        $attempts = $entry['attempts'];
        $maxAttempts = $entry['maxAttempts'];

        if (! $job instanceof JobInterface) {
            $this->handOver($queueService, $runToken, $entry['stopOnFailure'] ? 'clear' : 'pop', 0);
            throw new RuntimeException(sprintf(
                'Serial waiting list "%s" contains a non-JobInterface payload.',
                $this->key
            ));
        }

        try {
            $job->handle();
        } catch (Throwable $e) {
            ++$attempts;
            if ($attempts < $maxAttempts) {
                $this->handOver($queueService, $runToken, 'replace', $delay, serialize([
                    'job' => $job,
                    'delay' => $delay,
                    'attempts' => $attempts,
                    'maxAttempts' => $maxAttempts,
                    'stopOnFailure' => $entry['stopOnFailure'],
                ]));
                return;
            }

            if (! $entry['stopOnFailure']) {
                $this->handOver($queueService, $runToken, 'pop', $delay);
                throw $e;
            }

            $result = $this->handOver($queueService, $runToken, 'fail', 0, serialize([
                'job' => $job,
                'delay' => $delay,
                'attempts' => 0,
                'maxAttempts' => $maxAttempts,
                'stopOnFailure' => true,
            ]));
            if ($result !== -1) {
                try {
                    $queueService->recordFailedChain($this->key, $this->queue, $e);
                } catch (Throwable) {
                }
            }
            throw $e;
        }

        $this->handOver($queueService, $runToken, 'pop', $delay);
    }

    /**
     * Atomically advance the waiting list and, if anything is left, pass the lease to the next SerialJob.
     */
    private function handOver(QueueService $queueService, string $runToken, string $mode, int $delay, string $headPayload = ''): int
    {
        $nextToken = $queueService->newSerialToken();
        $result = $queueService->advanceSerial(
            $this->key,
            $runToken,
            $nextToken,
            $mode,
            $queueService->driverDelayToMs($this->queue, $delay),
            $headPayload,
            $this->queue
        );

        if ($result === 1) {
            $queueService->startSerialRunner($this->key, $this->queue, $nextToken, $delay);
        }

        return $result;
    }

    /**
     * Keep the runner lease and concurrency slot alive while a long business job is running.
     */
    private function startRenewer(QueueService $queueService, string $runToken, ?string $slot): ?Channel
    {
        if (! Coroutine::inCoroutine()) {
            return null;
        }

        $interval = max(1.0, $queueService->serialRunnerLeaseMs() / 3000);
        $channel = new Channel(1);

        Coroutine::create(function () use ($queueService, $runToken, $slot, $channel, $interval) {
            while (true) {
                $channel->pop($interval);
                if ($channel->isClosing()) {
                    return;
                }
                try {
                    if (! $queueService->renewSerialRunner($this->key, $runToken, $this->queue)) {
                        return;
                    }
                    $queueService->renewSerialSlot($slot, $this->queue);
                } catch (Throwable) {
                }
            }
        });

        return $channel;
    }

    /**
     * @return array{job: mixed, delay: int, attempts: int, maxAttempts: int, stopOnFailure: bool}
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
                'stopOnFailure' => false,
            ];
        }
        if (is_array($data) && isset($data['job'])) {
            return [
                'job' => $data['job'],
                'delay' => max(0, (int) ($data['delay'] ?? 0)),
                'attempts' => max(0, (int) ($data['attempts'] ?? 0)),
                'maxAttempts' => max(1, (int) ($data['maxAttempts'] ?? 1)),
                'stopOnFailure' => (bool) ($data['stopOnFailure'] ?? false),
            ];
        }

        return [
            'job' => $data,
            'delay' => 0,
            'attempts' => 0,
            'maxAttempts' => 1,
            'stopOnFailure' => false,
        ];
    }
}
