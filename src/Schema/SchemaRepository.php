<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Schema;

use Esanj\RemoteEloquent\Contracts\ResourceTransport;
use Esanj\RemoteEloquent\Transport\RemoteResponse;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * GET {resource}/schema, cached.
 */
final class SchemaRepository
{
    private const STALE_FACTOR = 24;

    /** @var array<string, ResourceSchema|null> */
    private array $resolved = [];

    public function __construct(
        private readonly ResourceTransport $transport,
        private readonly CacheRepository $cache,
        private readonly int $ttl = 3600,
        private readonly string $prefix = 'esanj:remote_eloquent:',
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The schema of a resource, or null when it cannot be resolved.
     */
    public function for(string $resource): ?ResourceSchema
    {
        $resource = $this->normalizeName($resource);

        if ($resource === '') {
            return null;
        }

        if (array_key_exists($resource, $this->resolved)) {
            return $this->resolved[$resource];
        }

        $entry = $this->cached($resource);

        if ($entry !== null && $entry['fresh_until'] > time()) {
            return $this->resolved[$resource] = $this->hydrate($entry['payload']);
        }

        try {
            $response = $this->transport->schema($resource, $entry['etag'] ?? null);
        } catch (Throwable $exception) {
            $this->logger?->warning('Remote schema for "{resource}" could not be fetched.', [
                'resource' => $resource,
                'exception' => $exception->getMessage(),
                'served_stale' => $entry !== null,
            ]);

            return $this->resolved[$resource] = $this->fallback($entry);
        }

        $status = $response->status();

        if ($status === 304 && $entry !== null) {
            $this->remember($resource, $entry['payload'], $this->etagOf($response) ?? $entry['etag']);

            return $this->resolved[$resource] = $this->hydrate($entry['payload']);
        }

        $payload = $response->data();

        if ($status >= 200 && $status < 300 && $payload !== []) {
            $this->remember($resource, $payload, $this->etagOf($response));

            return $this->resolved[$resource] = $this->hydrate($payload);
        }

        $this->logger?->warning('Remote schema for "{resource}" answered {status} with no usable payload.', [
            'resource' => $resource,
            'status' => $status,
            'served_stale' => $entry !== null,
        ]);

        return $this->resolved[$resource] = $this->fallback($entry);
    }

    /**
     * Drop a resource from both caches, so the next for() revalidates.
     */
    public function forget(string $resource): void
    {
        $resource = $this->normalizeName($resource);

        unset($this->resolved[$resource]);

        if ($resource === '') {
            return;
        }

        try {
            $this->cache->forget($this->key($resource));
        } catch (Throwable $exception) {
            $this->logger?->warning('Remote schema cache for "{resource}" could not be cleared.', [
                'resource' => $resource,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Forget everything resolved in this process without touching the shared cache.
     */
    public function flushResolved(): void
    {
        $this->resolved = [];
    }

    /**
     * @param  array{etag: string|null, payload: array<string, mixed>, fresh_until: int}|null  $entry
     */
    private function fallback(?array $entry): ?ResourceSchema
    {
        return $entry === null ? null : $this->hydrate($entry['payload']);
    }

    private function etagOf(RemoteResponse $response): ?string
    {
        return $this->cleanEtag($response->header('ETag'));
    }

    private function cleanEtag(mixed $etag): ?string
    {
        if (! is_string($etag)) {
            return null;
        }

        $etag = trim($etag);

        return $etag === '' ? null : $etag;
    }

    /**
     * @return array{etag: string|null, payload: array<string, mixed>, fresh_until: int}|null
     */
    private function cached(string $resource): ?array
    {
        if ($this->ttl <= 0) {
            return null;
        }

        try {
            $entry = $this->cache->get($this->key($resource));
        } catch (Throwable $exception) {
            $this->logger?->warning('Remote schema cache for "{resource}" could not be read.', [
                'resource' => $resource,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! is_array($entry) || ! is_array($entry['payload'] ?? null) || $entry['payload'] === []) {
            return null;
        }

        return [
            'etag' => $this->cleanEtag($entry['etag'] ?? null),
            'payload' => $entry['payload'],
            'fresh_until' => is_numeric($entry['fresh_until'] ?? null) ? (int) $entry['fresh_until'] : 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function remember(string $resource, array $payload, ?string $etag): void
    {
        if ($this->ttl <= 0) {
            return;
        }

        try {
            $this->cache->put(
                $this->key($resource),
                ['etag' => $etag, 'payload' => $payload, 'fresh_until' => time() + $this->ttl],
                $this->ttl * self::STALE_FACTOR,
            );
        } catch (Throwable $exception) {
            $this->logger?->warning('Remote schema for "{resource}" could not be cached.', [
                'resource' => $resource,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * A payload that cannot be read is the same as no payload at all: null, and the request goes out unchecked.
     *
     * @param  array<string, mixed>  $payload
     */
    private function hydrate(array $payload): ?ResourceSchema
    {
        try {
            $schema = ResourceSchema::fromArray($payload);
        } catch (Throwable $exception) {
            $this->logger?->warning('Remote schema payload could not be read.', [
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        return $schema->fields() === [] ? null : $schema;
    }

    private function key(string $resource): string
    {
        return $this->prefix.'schema:'.$resource;
    }

    private function normalizeName(string $resource): string
    {
        return trim($resource, " \t\n\r\0\x0B/");
    }
}