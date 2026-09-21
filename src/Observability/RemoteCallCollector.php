<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Observability;

use Psr\Log\LoggerInterface;

/**
 * Every remote call this request made, and a warning when there are too many.
 */
final class RemoteCallCollector
{
    /** @var array<int, array<string, mixed>> */
    private array $calls = [];

    private int $countedCalls = 0;

    private float $totalDurationMs = 0.0;

    private bool $warned = false;

    public function __construct(
        private readonly ?LoggerInterface $logger = null,
        private readonly int $warningThreshold = 20,
        private readonly int $keep = 200,
    ) {
    }

    /**
     * Record one completed call — successful or not.
     *
     * @param  array<string, scalar|null>  $extra
     */
    public function record(
        string $transport,
        string $operation,
        string $resource,
        string $method,
        string $path,
        ?int $status,
        float $durationMs,
        ?string $requestId = null,
        ?string $errorCode = null,
        bool $retry = false,
        array $extra = [],
    ): void {
        $this->totalDurationMs += $durationMs;

        $call = [
            'transport' => $transport,
            'operation' => $operation,
            'resource' => $resource,
            'method' => $method,
            'path' => $path,
            'status' => $status,
            'duration_ms' => round($durationMs, 2),
            'request_id' => $requestId,
            'error_code' => $errorCode,
            'retry' => $retry,
        ] + $extra;

        // Bounded on purpose: a runaway N+1 must not turn a slow page into an out-of-memory one.
        if (count($this->calls) < $this->keep) {
            $this->calls[] = $call;
        }

        $this->countedCalls++;

        $this->warnIfNoisy();
    }

    /**
     * How many calls this request has made, including any dropped from calls().
     */
    public function count(): int
    {
        return $this->countedCalls;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function totalDurationMs(): float
    {
        return round($this->totalDurationMs, 2);
    }

    public function warningThreshold(): int
    {
        return $this->warningThreshold;
    }

    public function hasWarned(): bool
    {
        return $this->warned;
    }

    /**
     * Calls grouped by "resource.operation", most repeated first.
     *
     * @return array<string, int>
     */
    public function hotspots(): array
    {
        $groups = [];

        foreach ($this->calls as $call) {
            $key = $call['resource'] . '.' . $call['operation'];
            $groups[$key] = ($groups[$key] ?? 0) + 1;
        }

        arsort($groups);

        return $groups;
    }

    /**
     * Start a new request's worth of measurements.
     */
    public function flush(): void
    {
        $this->calls = [];
        $this->countedCalls = 0;
        $this->totalDurationMs = 0.0;
        $this->warned = false;
    }

    private function warnIfNoisy(): void
    {
        if ($this->warned
            || $this->logger === null
            || $this->warningThreshold <= 0
            || $this->countedCalls <= $this->warningThreshold) {
            return;
        }

        $this->warned = true;

        $hotspots = $this->hotspots();

        $this->logger->warning(
            sprintf(
                'Remote Eloquent made %d calls to the account service in one request (threshold %d). This is usually an N+1 that reads like ordinary Eloquent: load the ids first and fetch them in one whereIn().',
                $this->countedCalls,
                $this->warningThreshold
            ),
            [
                'calls' => $this->countedCalls,
                'threshold' => $this->warningThreshold,
                'duration_ms' => $this->totalDurationMs(),
                'hotspots' => array_slice($hotspots, 0, 5, true),
            ]
        );
    }
}
