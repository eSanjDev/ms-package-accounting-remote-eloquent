<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Transport;

/**
 * One successful answer from the account service, decoded.
 */
final class RemoteResponse
{
    /** @var array<string, string> */
    public readonly array $headers;

    /**
     * @param  array<string, mixed>  $body     the decoded envelope: data, meta, schema_version
     * @param  array<string, string|array<int, string>>  $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $body = [],
        array $headers = [],
        public readonly ?RateLimitSnapshot $rateLimit = null,
    ) {
        $flat = [];

        foreach ($headers as $name => $value) {
            $flat[strtolower((string) $name)] = is_array($value)
                ? (string) ($value[0] ?? '')
                : (string) $value;
        }

        $this->headers = $flat;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string|array<int, string>>  $headers
     */
    public static function make(int $status, array $body = [], array $headers = []): self
    {
        return new self($status, $body, $headers, RateLimitSnapshot::fromHeaders($headers));
    }

    /**
     * A 204 and friends: a real answer that carries no envelope.
     *
     * @param  array<string, string|array<int, string>>  $headers
     */
    public static function empty(int $status = 204, array $headers = []): self
    {
        return self::make($status, [], $headers);
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return $this->body;
    }

    /**
     * The envelope's "data", whatever shape the endpoint documents.
     */
    public function data(): mixed
    {
        return $this->body['data'] ?? null;
    }

    /**
     * data as a list of records — query(), and nothing else.
     *
     * @return array<int, array<string, mixed>>
     */
    public function records(): array
    {
        $data = $this->data();

        if (! is_array($data)) {
            return [];
        }

        return array_values(array_filter($data, 'is_array'));
    }

    /**
     * data as a single record — find(), create(), update(), restore(), action().
     *
     * @return array<string, mixed>|null
     */
    public function record(): ?array
    {
        $data = $this->data();

        return is_array($data) && ! array_is_list($data) ? $data : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        $meta = $this->body['meta'] ?? [];

        return is_array($meta) ? $meta : [];
    }

    public function metaValue(string $key, mixed $default = null): mixed
    {
        return $this->meta()[$key] ?? $default;
    }

    /**
     * The full row count, and only when with_total asked for it.
     */
    public function total(): ?int
    {
        $total = $this->metaValue('total');

        return is_numeric($total) ? (int) $total : null;
    }

    public function hasMore(): bool
    {
        return (bool) $this->metaValue('has_more', false);
    }

    public function limit(): ?int
    {
        $limit = $this->metaValue('limit');

        return is_numeric($limit) ? (int) $limit : null;
    }

    public function offset(): ?int
    {
        $offset = $this->metaValue('offset');

        return is_numeric($offset) ? (int) $offset : null;
    }

    /**
     * The aggregate value — data.value — for count/min/max/sum/avg.
     */
    public function value(): mixed
    {
        $data = $this->data();

        return is_array($data) ? ($data['value'] ?? null) : $data;
    }

    /**
     * "users@3": the schema this answer was produced against.
     */
    public function schemaVersion(): ?string
    {
        $header = $this->header('x-schema-version');

        if ($header !== null && $header !== '') {
            return $header;
        }

        $body = $this->body['schema_version'] ?? null;

        return is_string($body) && $body !== '' ? $body : null;
    }

    public function permissionsVersion(): ?string
    {
        $header = $this->header('x-permissions-version');

        if ($header !== null && $header !== '') {
            return $header;
        }

        $body = $this->body['permissions_version'] ?? null;

        return is_scalar($body) && (string) $body !== '' ? (string) $body : null;
    }

    /**
     * The X-Request-Id of this call — the one string to quote in a bug report.
     */
    public function requestId(): ?string
    {
        $header = $this->header('x-request-id');

        if ($header !== null && $header !== '') {
            return $header;
        }

        $body = $this->body['request_id'] ?? null;

        return is_string($body) && $body !== '' ? $body : null;
    }

    public function rateLimit(): RateLimitSnapshot
    {
        return $this->rateLimit ?? RateLimitSnapshot::fromHeaders($this->headers);
    }

    /**
     * Set when this endpoint is on its way out; Sunset says when it goes.
     */
    public function deprecation(): ?string
    {
        $value = $this->header('deprecation');

        return $value !== null && $value !== '' ? $value : null;
    }

    public function sunset(): ?string
    {
        $value = $this->header('sunset');

        return $value !== null && $value !== '' ? $value : null;
    }

    public function isDeprecated(): bool
    {
        return $this->deprecation() !== null;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function isEmpty(): bool
    {
        return $this->body === [];
    }
}
