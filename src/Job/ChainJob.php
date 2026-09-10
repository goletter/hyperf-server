<?php

declare(strict_types=1);

namespace Goletter\Server\Job;

use Goletter\Server\Service\QueueService;
use Hyperf\AsyncQueue\Job;
use Hyperf\AsyncQueue\JobInterface;
use Hyperf\Context\ApplicationContext;
use InvalidArgumentException;

/**
 * Sequential job chain: run the current step, then enqueue the remainder on success.
 *
 * Failure stops the chain (remaining jobs are not pushed). The wrapper itself does
 * not retry (maxAttempts = 0) to avoid double-advancing the chain.
 */
class ChainJob extends Job
{
    /**
     * @param JobInterface[] $jobs
     */
    public function __construct(
        public array $jobs,
        public string $queue = QueueService::QUEUE_DEFAULT,
        public int $delay = 0,
    ) {
        $this->jobs = array_values($jobs);
        foreach ($this->jobs as $index => $job) {
            if (! $job instanceof JobInterface) {
                throw new InvalidArgumentException(sprintf(
                    'Chain job at index %d must implement %s.',
                    $index,
                    JobInterface::class
                ));
            }
        }
    }

    public function handle(): void
    {
        if ($this->jobs === []) {
            return;
        }

        $current = array_shift($this->jobs);
        $current->handle();

        if ($this->jobs === []) {
            return;
        }

        ApplicationContext::getContainer()
            ->get(QueueService::class)
            ->push(new self($this->jobs, $this->queue, $this->delay), $this->queue, $this->delay);
    }
}
