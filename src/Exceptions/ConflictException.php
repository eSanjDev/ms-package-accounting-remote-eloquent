<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * 409 stale_record, idempotency_mismatch or idempotency_in_progress.
 */
final class ConflictException extends RemoteEloquentException implements HttpExceptionInterface
{
    private const USER_MESSAGE = 'This record changed while you were working on it, so nothing was saved. Refresh and try again.';

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function staleRecord(
        string $resource,
        string|int|null $id = null,
        ?string $requestId = null,
        array $context = [],
    ): self {
        return new self(
            sprintf('The "%s" record was modified by someone else since it was read; the update was not applied.', $resource),
            'stale_record',
            409,
            $requestId,
            $context + ['resource' => $resource, 'id' => $id],
        );
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function idempotencyMismatch(
        string $resource,
        string $operation,
        ?string $requestId = null,
        array $context = [],
    ): self {
        return new self(
            sprintf(
                'The Idempotency-Key sent for this %s on "%s" was already used with a different body. A key belongs to exactly one payload — generate a new one per logical operation, not per retry of a changed one.',
                $operation,
                $resource
            ),
            'idempotency_mismatch',
            409,
            $requestId,
            $context + ['resource' => $resource, 'operation' => $operation],
        );
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function idempotencyInProgress(
        string $resource,
        string $operation,
        ?string $requestId = null,
        array $context = [],
    ): self {
        return new self(
            sprintf(
                'An identical %s on "%s" is still running on the account service. It was not sent a second time; read the record back in a moment to see how the first one ended.',
                $operation,
                $resource
            ),
            'idempotency_in_progress',
            409,
            $requestId,
            $context + ['resource' => $resource, 'operation' => $operation],
        );
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function fromServer(
        string $code,
        string $message,
        ?string $requestId = null,
        array $context = [],
    ): self {
        return new self(
            $message !== '' ? $message : self::USER_MESSAGE,
            $code !== '' ? $code : 'stale_record',
            409,
            $requestId,
            $context,
        );
    }

    public function getStatusCode(): int
    {
        return 409;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }

    protected function responseStatus(): int
    {
        return 409;
    }

    protected function userMessage(): string
    {
        return self::USER_MESSAGE;
    }
}
