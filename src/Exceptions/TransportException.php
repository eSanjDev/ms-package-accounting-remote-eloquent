<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Exceptions;

use Throwable;

/**
 * A transport-level failure: could not reach the server, an unexpected status,
 * a malformed response, or a misconfigured gRPC stack.
 */
final class TransportException extends RemoteEloquentException
{
    /**
     * Whether the statement may already have reached the server.
     *
     * False only when the failure provably happened before anything was sent —
     * the one case where replaying a write on another transport cannot apply it
     * twice. See {@see \Esanj\RemoteEloquent\Transport\FallbackPolicy}.
     */
    private bool $dispatched = true;

    public static function connectionFailed(string $transport, string $reason, ?Throwable $previous = null): self
    {
        return new self(
            "Could not reach the Accounting service over {$transport}: {$reason}",
            previous: $previous,
            context: ['transport' => $transport, 'reason' => $reason],
        );
    }

    public static function unexpectedStatus(string $transport, int $status, string $body): self
    {
        return new self(
            "The Accounting service returned an unexpected {$transport} status [{$status}].",
            code: $status,
            context: ['transport' => $transport, 'status' => $status, 'body' => $body],
        );
    }

    public static function unauthenticated(string $transport): self
    {
        return new self(
            "Authentication with the Accounting service failed over {$transport}.",
            code: 401,
            context: ['transport' => $transport],
        );
    }

    public static function requestFailed(string $transport, Throwable $previous): self
    {
        return new self(
            "The {$transport} request to the Accounting service failed: {$previous->getMessage()}",
            previous: $previous,
            context: ['transport' => $transport, 'reason' => $previous->getMessage()],
        );
    }

    public static function grpcUnavailable(string $reason): self
    {
        $exception = new self(
            "The gRPC transport is unavailable: {$reason}",
            context: ['reason' => $reason],
        );

        // Thrown while building the client — the statement never left the process.
        $exception->dispatched = false;

        return $exception;
    }

    /**
     * Every transport in the fallback chain failed. The primary failure is kept
     * as the previous exception; the message names what each attempt hit.
     *
     * @param  array<string, string>  $attempts  driver name => failure message, in the order tried.
     */
    public static function allTransportsFailed(string $primary, array $attempts, ?Throwable $previous = null): self
    {
        $summary = implode('; ', array_map(
            static fn (string $driver, string $reason): string => "{$driver}: {$reason}",
            array_keys($attempts),
            array_values($attempts),
        ));

        return new self(
            "Every transport failed for this statement — {$summary}",
            code: (int) ($previous?->getCode() ?? 0),
            previous: $previous,
            context: ['transport' => $primary, 'attempts' => $attempts],
        );
    }

    public function wasDispatched(): bool
    {
        return $this->dispatched;
    }
}
