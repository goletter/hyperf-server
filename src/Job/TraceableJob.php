<?php

declare(strict_types=1);

namespace Goletter\Server\Job;

use Hyperf\AsyncQueue\Job;
use Hyperf\AsyncQueue\JobInterface;
use Hyperf\Context\Context;
use ReflectionObject;

class TraceableJob extends Job
{
    public function __construct(
        private readonly JobInterface $job,
        private readonly string $traceId
    ) {
        $this->syncMaxAttempts();
    }

    public function handle()
    {
        $previousTraceId = (string) Context::get('trace_id', '');
        Context::set('trace_id', $this->traceId);

        try {
            return $this->job->handle();
        } finally {
            Context::set('trace_id', $previousTraceId);
        }
    }

    private function syncMaxAttempts(): void
    {
        $reflection = new ReflectionObject($this->job);
        if (! $reflection->hasProperty('maxAttempts')) {
            return;
        }

        $property = $reflection->getProperty('maxAttempts');
        $property->setAccessible(true);
        $this->maxAttempts = (int) $property->getValue($this->job);
    }
}
