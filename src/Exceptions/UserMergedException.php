<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * 404 user_merged — the record asked for now lives under another id.
 */
final class UserMergedException extends RemoteEloquentException implements HttpExceptionInterface
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        string $message,
        string $errorCode = 'user_merged',
        int $status = 404,
        ?string $requestId = null,
        array $context = [],
        public readonly string|int|null $mergedInto = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $status, $requestId, $context, $previous);
    }

    /**
     * The id this record was merged into, when the server named one.
     */
    public function mergedInto(): string|int|null
    {
        return $this->mergedInto;
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function into(
        string $resource,
        string|int $id,
        string|int $mergedInto,
        ?string $requestId = null,
        array $context = [],
    ): self {
        return new self(
            sprintf(
                'The "%s" record [%s] was merged into [%s]. Store the new id: anything still pointing at the old one resolves through a redirect that will not last forever.',
                $resource,
                (string) $id,
                (string) $mergedInto
            ),
            'user_merged',
            404,
            $requestId,
            $context + ['resource' => $resource, 'id' => $id],
            $mergedInto,
        );
    }

    /**
     * Followed once and merged again.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function chained(
        string $resource,
        string|int $id,
        string|int $mergedInto,
        ?string $requestId = null,
        array $context = [],
    ): self {
        return new self(
            sprintf(
                'The "%s" record [%s] was merged into [%s], which has itself been merged. The chain is followed once only; re-read it from the account service and store the current id.',
                $resource,
                (string) $id,
                (string) $mergedInto
            ),
            'user_merged',
            404,
            $requestId,
            $context + ['resource' => $resource, 'id' => $id],
            $mergedInto,
        );
    }

    /**
     * A merge with no target to follow.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function withoutTarget(
        string $resource,
        string|int $id,
        ?string $requestId = null,
        array $context = [],
    ): self {
        return new self(
            sprintf(
                'The "%s" record [%s] reports as merged but the account service named no target, so there is nothing to follow.',
                $resource,
                (string) $id
            ),
            'user_merged',
            404,
            $requestId,
            $context + ['resource' => $resource, 'id' => $id],
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
        return 'This account was merged into another one. Reload the page to continue with the current account.';
    }

    /**
     * @return array<string, mixed>
     */
    protected function responseDetails(): array
    {
        return $this->mergedInto !== null
            ? ['merged_into' => $this->mergedInto]
            : [];
    }
}
