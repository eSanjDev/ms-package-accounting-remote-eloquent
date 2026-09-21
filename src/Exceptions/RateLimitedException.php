<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * 429 rate_limited.
 */
final class RateLimitedException extends RemoteEloquentException implements HttpExceptionInterface
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        string $message,
        string $errorCode = 'rate_limited',
        int $status = 429,
        ?string $requestId = null,
        array $context = [],
        public readonly int $retryAfterSeconds = 0,
        public readonly string $rateLimitBucket = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $status, $requestId, $context, $previous);
    }

    /**
     * Seconds to wait before this call may be made again.
     */
    public function retryAfter(): int
    {
        return max(0, $this->retryAfterSeconds);
    }

    /**
     * The limit that was hit.
     */
    public function bucket(): string
    {
        return $this->rateLimitBucket;
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function fromServer(
        int $retryAfter,
        string $bucket = '',
        ?string $requestId = null,
        array $context = [],
        string $message = '',
    ): self {
        $retryAfter = max(0, $retryAfter);

        return new self(
            $message !== '' ? $message : self::describe($retryAfter, $bucket),
            'rate_limited',
            429,
            $requestId,
            $context + ['bucket' => $bucket],
            $retryAfter,
            $bucket,
        );
    }

    public function getStatusCode(): int
    {
        return 429;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->responseHeaders();
    }

    protected function responseStatus(): int
    {
        return 429;
    }

    protected function userMessage(): string
    {
        return $this->retryAfter() > 0
            ? sprintf('Too many requests. Please try again in %d seconds.', $this->retryAfter())
            : 'Too many requests. Please try again in a moment.';
    }

    /**
     * @return array<string, string>
     */
    protected function responseHeaders(): array
    {
        return $this->retryAfter() > 0
            ? ['Retry-After' => (string) $this->retryAfter()]
            : [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function responseDetails(): array
    {
        $details = ['retry_after' => $this->retryAfter()];

        if ($this->rateLimitBucket !== '') {
            $details['bucket'] = $this->rateLimitBucket;
        }

        return $details;
    }

    private static function describe(int $retryAfter, string $bucket): string
    {
        $limit = $bucket !== ''
            ? sprintf('The account service rate limit [%s] was reached', $bucket)
            : 'The account service rate limit was reached';

        return $retryAfter > 0
            ? sprintf('%s. This call was NOT re-sent; retry after %d seconds.', $limit, $retryAfter)
            : sprintf('%s. This call was NOT re-sent.', $limit);
    }
}
