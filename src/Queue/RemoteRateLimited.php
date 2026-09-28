<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Queue;

use Closure;
use Esanj\RemoteEloquent\Exceptions\RateLimitedException;
use Esanj\RemoteEloquent\Idempotency\OperationContext;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as QueuedJob;
use Illuminate\Queue\Jobs\SyncJob;
use Throwable;

/**
 * Job middleware: a 429 puts the job back on the queue, it does not fail it.
 */
final class RemoteRateLimited
{
    /**
     * @param  int  $fallbackSeconds  used when the server sent no Retry-After
     * @param  int  $maxSeconds  a ceiling, so a wrong header cannot park a job for a day
     */
    public function __construct(
        private readonly int $fallbackSeconds = 60,
        private readonly int $maxSeconds = 3600,
    ) {
    }

    public function handle(object $job, Closure $next): mixed
    {
        $this->bindIdempotencyScope($job);

        try {
            return $next($job);
        } catch (RateLimitedException $exception) {
            $queued = $job->job ?? null;

            if (! $queued instanceof QueuedJob || $queued instanceof SyncJob || ! method_exists($job, 'release')) {
                throw $exception;
            }

            $job->release($this->delayFor($exception));

            return null;
        }
    }

    /**
     * Tie this run's Idempotency-Keys to the job rather than to the process.
     */
    private function bindIdempotencyScope(object $job): void
    {
        $scope = $this->scopeFor($job);

        if ($scope === null) {
            return;
        }

        try {
            $operations = Container::getInstance()->make(OperationContext::class);
        } catch (Throwable) {
            return;
        }

        if ($operations instanceof OperationContext) {
            $operations->useScope($scope);
        }
    }

    private function scopeFor(object $job): ?string
    {
        $queued = $job->job ?? null;

        if (! $queued instanceof QueuedJob || $queued instanceof SyncJob) {
            return null;
        }

        $uuid = $queued->uuid();

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    /**
     * How long to wait: the server's own answer, clamped.
     */
    private function delayFor(RateLimitedException $exception): int
    {
        $retryAfter = $exception->retryAfter();

        $seconds = $retryAfter > 0 ? $retryAfter : $this->fallbackSeconds;

        return max(1, min($seconds, $this->maxSeconds));
    }
}
