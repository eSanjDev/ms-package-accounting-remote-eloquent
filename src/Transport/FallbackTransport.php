<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Transport;

use Closure;
use Esanj\RemoteEloquent\Contracts\TransportInterface;
use Esanj\RemoteEloquent\DTOs\QueryResult;
use Esanj\RemoteEloquent\Exceptions\TransportException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs a statement on its primary transport and, when that transport itself
 * fails, replays it on the next one in the chain — REST takes over for gRPC and
 * the other way round.
 *
 * {@see FallbackPolicy} owns the decision of what may be replayed: a server
 * verdict (rejected SQL, denied table) is thrown straight through instead of
 * being asked again over another pipe. When every transport in the chain fails,
 * the primary failure is rethrown wrapped in a {@see TransportException} that
 * names each attempt.
 *
 * Transports are resolved lazily, so a chain that mentions gRPC costs nothing
 * until REST actually breaks.
 */
final class FallbackTransport implements TransportInterface
{
    /**
     * @param  string  $primary  The driver every statement is tried on first.
     * @param  list<string>  $chain  Drivers to fall back to, in order. Never empty, never contains $primary.
     * @param  Closure(string): TransportInterface  $resolver  Builds the transport for a driver name, on demand.
     * @param  array{enabled?: bool, channel?: string|null}  $log
     */
    public function __construct(
        private readonly string $primary,
        private readonly array $chain,
        private readonly Closure $resolver,
        private readonly FallbackPolicy $policy,
        private readonly array $log = [],
    ) {}

    public function runQuery(string $sql, array $bindings): QueryResult
    {
        $drivers = [$this->primary, ...$this->chain];

        /** @var array<string, string> $failures */
        $failures = [];
        $firstFailure = null;

        foreach ($drivers as $index => $driver) {
            try {
                return ($this->resolver)($driver)->runQuery($sql, $bindings);
            } catch (Throwable $e) {
                if (! $this->policy->shouldFallBack($e, $sql)) {
                    throw $e;
                }

                $failures[$driver] = $e->getMessage();
                $firstFailure ??= $e;

                $next = $drivers[$index + 1] ?? null;

                if ($next !== null) {
                    $this->report($driver, $next, $e);
                }
            }
        }

        throw TransportException::allTransportsFailed($this->primary, $failures, $firstFailure);
    }

    /**
     * A silent fallback hides an outage, so every handover is logged.
     */
    private function report(string $failed, string $next, Throwable $e): void
    {
        if (($this->log['enabled'] ?? true) === false) {
            return;
        }

        $channel = $this->log['channel'] ?? null;
        $channel = is_string($channel) && $channel !== '' ? $channel : null;

        Log::channel($channel)->warning(
            "remote-eloquent: the [{$failed}] transport failed, retrying on [{$next}].",
            ['transport' => $failed, 'fallback' => $next, 'reason' => $e->getMessage()],
        );
    }
}