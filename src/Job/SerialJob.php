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
 * then drop the head and continue with the next job — or, for chain steps (stopOnFailure), move that chain's
 * remaining steps to its failed list so it can be retried from the failed step; other chains on the key continue.
 *
 * A global concurrency slot prevents too many serial keys from running at once (FD / resource storm).
 */
class SerialJob extends Job
{
    // Declared with defaults (not constructor-promoted) so messages serialized by older versions,
    // which lack newer properties, still unserialize into initialized properties.
    public string $key = '';

    public string $queue = QueueService::QUEUE_DEFAULT;

    public string $token = '';

    /** Concurrency slot already granted by the FIFO slot queue. */
    public string $slot = '';

    public function __construct(
        string $key,
        string $queue = QueueService::QUEUE_DEFAULT,
        string $token = '',
        string $slot = '',
    ) {
        $this->key = $key;
        $this->queue = $queue;
        $this->token = $token;
        $this->slot = $slot;
    }

    public function handle(): void
    {
        /** @var QueueService $queueService */
        $queueService = ApplicationContext::getContainer()->get(QueueService::class);

        $runToken = $queueService->newSerialToken();
        if (! $queueService->claimSerialRunner($this->key, $this->token, $runToken, $this->queue)) {
            // A granted slot must be handed on, otherwise it stays occupied until it expires.
            $queueService->releaseSerialSlot($this->slot, $this->queue);
            return;
        }

        $slot = $this->slot !== '' ? $queueService->adoptSerialSlot($this->slot, $this->queue) : null;
        $slot ??= $queueService->acquireSerialSlot($this->queue, $this->key, $runToken);
        if ($slot === null) {
            // Parked in the FIFO slot queue; it will be re-dispatched with a slot when one frees up.
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
            $e = new RuntimeException(sprintf(
                'Serial waiting list "%s" contains a non-JobInterface payload.',
                $this->key
            ));
            $entry['stopOnFailure']
                ? $this->failChain($queueService, $runToken, $entry, $e, '')
                : $this->handOver($queueService, $runToken, 'pop', 0);
            throw $e;
        }

        // runs 记录开始次数，attempts 记录正常失败次数；差值就是 worker 被杀 / 超时被接管的次数
        $lostRuns = $entry['runs'] - $attempts;
        if ($lostRuns >= $queueService->serialMaxLostRuns()) {
            $this->exhausted($queueService, $runToken, $entry, $job, $delay, new RuntimeException(sprintf(
                'Serial job on "%s" aborted %d time(s): worker lost or exceeded max_run_seconds.',
                $this->key,
                $lostRuns
            )));
        }

        $entry['runs']++;
        if (! $queueService->markSerialStart($this->key, $runToken, serialize(['job' => $job] + $entry), $this->queue)) {
            return;
        }

        try {
            $job->handle();
        } catch (Throwable $e) {
            ++$attempts;
            if ($attempts < $maxAttempts) {
                $this->handOver($queueService, $runToken, 'replace', $delay, serialize(
                    ['job' => $job, 'attempts' => $attempts] + $entry
                ));
                return;
            }

            $this->exhausted($queueService, $runToken, $entry, $job, $delay, $e);
        }

        // 一条链的最后一步之后按 chainDelay 等待，再开始同 key 的下一条链
        $isLastStep = $entry['stopOnFailure'] && $entry['step'] >= $entry['steps'] - 1;
        $this->handOver($queueService, $runToken, 'pop', $isLastStep ? $entry['chainDelay'] : $delay);
    }

    /**
     * No attempts left: drop the entry (pushSerial) or stop the chain (chain), then rethrow.
     */
    private function exhausted(QueueService $queueService, string $runToken, array $entry, JobInterface $job, int $delay, Throwable $e): never
    {
        $entry['stopOnFailure']
            ? $this->failChain($queueService, $runToken, $entry, $e, serialize(
                ['job' => $job, 'attempts' => 0, 'runs' => 0] + $entry
            ))
            : $this->handOver($queueService, $runToken, 'pop', $delay);

        throw $e;
    }

    /**
     * Move this chain's remaining steps to its failed list; later chains on the same key keep running.
     */
    private function failChain(QueueService $queueService, string $runToken, array $entry, Throwable $e, string $headPayload): void
    {
        $chainId = $entry['chainId'] !== '' ? $entry['chainId'] : $this->key;
        $remaining = $entry['steps'] > 0 ? $entry['steps'] - $entry['step'] : PHP_INT_MAX;

        try {
            $queueService->recordFailedChain($chainId, $this->key, $this->queue, $e);
        } catch (Throwable) {
        }

        $this->handOver($queueService, $runToken, 'fail', $entry['chainDelay'], $headPayload, $chainId, $remaining);
    }

    /**
     * Atomically advance the waiting list and, if anything is left, pass the lease to the next SerialJob.
     */
    private function handOver(
        QueueService $queueService,
        string $runToken,
        string $mode,
        int $delay,
        string $headPayload = '',
        string $chainId = '',
        int $failCount = 1,
    ): int {
        $nextToken = $queueService->newSerialToken();
        $result = $queueService->advanceSerial(
            $this->key,
            $runToken,
            $nextToken,
            $mode,
            $queueService->driverDelayToMs($this->queue, $delay),
            $headPayload,
            $this->queue,
            $chainId,
            $failCount
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
        $maxRun = $queueService->serialMaxRunSeconds();
        $deadline = $maxRun > 0 ? microtime(true) + $maxRun : null;
        $channel = new Channel(1);

        Coroutine::create(function () use ($queueService, $runToken, $slot, $channel, $interval, $deadline) {
            while (true) {
                $channel->pop($interval);
                if ($channel->isClosing()) {
                    return;
                }
                // Stop renewing a step that runs too long; the lease expires and recover takes over.
                if ($deadline !== null && microtime(true) >= $deadline) {
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
     * @return array{job: mixed, delay: int, attempts: int, maxAttempts: int, stopOnFailure: bool, chainId: string, step: int, steps: int, chainDelay: int, runs: int}
     */
    private function unpack(string $payload): array
    {
        $data = unserialize($payload);
        if (! is_array($data) || ! isset($data['job'])) {
            $data = ['job' => $data];
        }

        return [
            'job' => $data['job'],
            'delay' => max(0, (int) ($data['delay'] ?? 0)),
            'attempts' => max(0, (int) ($data['attempts'] ?? 0)),
            'maxAttempts' => max(1, (int) ($data['maxAttempts'] ?? 1)),
            'stopOnFailure' => (bool) ($data['stopOnFailure'] ?? false),
            'chainId' => (string) ($data['chainId'] ?? ''),
            'step' => max(0, (int) ($data['step'] ?? 0)),
            'steps' => max(0, (int) ($data['steps'] ?? 0)),
            'chainDelay' => max(0, (int) ($data['chainDelay'] ?? 0)),
            'runs' => max(0, (int) ($data['runs'] ?? 0)),
        ];
    }
}
