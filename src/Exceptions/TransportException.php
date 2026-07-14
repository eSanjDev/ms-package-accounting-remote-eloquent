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

    public static function grpcUnavailable(string $reason): self
    {
        return new self(
            "The gRPC transport is unavailable: {$reason}",
            context: ['reason' => $reason],
        );
    }
}