<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Transport;

/**
 * What the account service said about this application's remaining budget.
 */
final class RateLimitSnapshot
{
    public function __construct(
        public readonly ?int $limit = null,
        public readonly ?int $remaining = null,
        public readonly ?int $reset = null,
        public readonly string $bucket = '',
        public readonly ?int $retryAfter = null,
    ) {
    }

    /**
     * An absent snapshot: the server published no rate-limit headers at all.
     */
    public static function none(): self
    {
        return new self();
    }

    /**
     * Build from response headers, however the client cased them.
     *
     * @param  array<string, string|array<int, string>>  $headers
     */
    public static function fromHeaders(array $headers): self
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = is_array($value)
                ? (string) ($value[0] ?? '')
                : (string) $value;
        }

        $limit = self::intHeader($normalized, 'ratelimit-limit', 'x-ratelimit-limit');
        $remaining = self::intHeader($normalized, 'ratelimit-remaining', 'x-ratelimit-remaining');
        $reset = self::intHeader($normalized, 'ratelimit-reset', 'x-ratelimit-reset');
        $retryAfter = self::intHeader($normalized, 'retry-after');

        $bucket = $normalized['x-ratelimit-bucket'] ?? '';

        return new self($limit, $remaining, $reset, $bucket, $retryAfter);
    }

    public function limit(): ?int
    {
        return $this->limit;
    }

    public function remaining(): ?int
    {
        return $this->remaining;
    }

    /**
     * Seconds until the window resets, as the server reported it.
     */
    public function reset(): ?int
    {
        return $this->reset;
    }

    public function bucket(): string
    {
        return $this->bucket;
    }

    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /**
     * How long to wait before the same bucket is worth trying again.
     */
    public function waitSeconds(int $default = 1): int
    {
        foreach ([$this->retryAfter, $this->reset] as $candidate) {
            if ($candidate !== null && $candidate > 0) {
                return $candidate;
            }
        }

        return max(0, $default);
    }

    /**
     * True when the server said, on THIS response, that nothing is left.
     */
    public function isExhausted(): bool
    {
        return $this->remaining !== null && $this->remaining <= 0;
    }

    public function isEmpty(): bool
    {
        return $this->limit === null
            && $this->remaining === null
            && $this->reset === null
            && $this->retryAfter === null
            && $this->bucket === '';
    }

    /**
     * @return array<string, scalar|null>
     */
    public function toArray(): array
    {
        return [
            'limit' => $this->limit,
            'remaining' => $this->remaining,
            'reset' => $this->reset,
            'bucket' => $this->bucket,
            'retry_after' => $this->retryAfter,
        ];
    }

    /**
     * @param  array<string, string>  $headers
     */
    private static function intHeader(array $headers, string ...$names): ?int
    {
        foreach ($names as $name) {
            $value = $headers[$name] ?? '';

            if ($value !== '' && preg_match('/^-?\d+$/', trim($value)) === 1) {
                return (int) trim($value);
            }
        }

        return null;
    }
}
