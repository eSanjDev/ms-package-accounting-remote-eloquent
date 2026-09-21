<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class RemoteTimeoutException extends RemoteEloquentException implements HttpExceptionInterface
{
    /**
     * The server gave up evaluating the query.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function queryTimeout(
        string $resource,
        int $status = 503,
        ?string $requestId = null,
        array $context = [],
    ): self {
        return new self(
            sprintf(
                'The account service timed out evaluating this "%s" query. Narrow it — fewer rows, a smaller page, a filter on an indexed field — or move deep paging to chunkById().',
                $resource
            ),
            'query_timeout',
            $status,
            $requestId,
            $context + ['resource' => $resource],
        );
    }

    /**
     * Our own clock ran out first; what happened on the other side is unknown.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function deadlineExceeded(
        float $seconds,
        array $context = [],
        ?Throwable $previous = null,
    ): self {
        return new self(
            sprintf(
                'The account service did not answer within %.1fs (esanj.remote_eloquent.rest.timeout). Whether it applied anything is unknown.',
                $seconds
            ),
            'query_timeout',
            0,
            null,
            $context + ['timeout' => $seconds],
            $previous,
        );
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function fromServer(
        string $message,
        int $status = 503,
        ?string $requestId = null,
        array $context = [],
    ): self {
        return new self(
            $message !== '' ? $message : 'The account service timed out handling this request.',
            'query_timeout',
            $status,
            $requestId,
            $context,
        );
    }

    public function getStatusCode(): int
    {
        return 503;
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
        return 503;
    }

    protected function userMessage(): string
    {
        return 'The account service is taking longer than expected and is briefly unavailable. Please try again in a moment.';
    }
}
