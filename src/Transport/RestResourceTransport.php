<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Transport;

use Esanj\RemoteEloquent\Client\ResourceClient;
use Esanj\RemoteEloquent\Contracts\ResourceTransport;
use Esanj\RemoteEloquent\Observability\RemoteCallCollector;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The resource API over HTTP — the one transport the package ships.
 */
final class RestResourceTransport implements ResourceTransport
{
    private ResourceClient $client;

    public function __construct(ResourceClient $client, ?RemoteCallCollector $calls = null)
    {
        $this->client = $calls === null ? $client : $client->withCollector($calls);
    }

    public function name(): string
    {
        return 'rest';
    }

    public function withActor(?Authenticatable $actor): static
    {
        $copy = clone $this;
        $copy->client = $this->client->withActor($actor);

        return $copy;
    }

    public function client(): ResourceClient
    {
        return $this->client;
    }

    /**
     * GET {resource}/schema
     */
    public function schema(string $resource, ?string $etag = null): RemoteResponse
    {
        return $this->client->resource($resource)->schema($etag);
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    public function query(string $resource, array $spec): RemoteResponse
    {
        return $this->client->resource($resource)->query($spec);
    }

    /**
     * @param  array<string, mixed>  $spec  function, field, where, trashed
     */
    public function aggregate(string $resource, array $spec): RemoteResponse
    {
        return $this->client->resource($resource)->aggregate(
            is_string($spec['function'] ?? null) ? $spec['function'] : 'count',
            is_string($spec['field'] ?? null) ? $spec['field'] : null,
            is_array($spec['where'] ?? null) ? $spec['where'] : [],
            is_string($spec['trashed'] ?? null) ? $spec['trashed'] : null,
            ($spec['distinct'] ?? false) === true,
        );
    }

    /**
     * @param  array<string, mixed>  $options  fields, include, trashed
     */
    public function find(string $resource, string|int $id, array $options = []): RemoteResponse
    {
        return $this->client->resource($resource)->find(
            $id,
            $this->names($options, 'fields'),
            $this->names($options, 'include'),
            is_string($options['trashed'] ?? null) ? $options['trashed'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload  attributes, fields
     */
    public function create(string $resource, array $payload, string $idempotencyKey): RemoteResponse
    {
        return $this->client->resource($resource)->create(
            is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [],
            $this->names($payload, 'fields'),
            $idempotencyKey,
        );
    }

    /**
     * @param  array<string, mixed>  $payload  attributes, if_match, fields
     */
    public function update(string $resource, string|int $id, array $payload, string $idempotencyKey): RemoteResponse
    {
        $ifMatch = $payload['if_match'] ?? null;

        return $this->client->resource($resource)->update(
            $id,
            is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [],
            is_array($ifMatch) || is_string($ifMatch) || is_int($ifMatch) ? $ifMatch : null,
            $this->names($payload, 'fields'),
            $idempotencyKey,
        );
    }

    public function delete(string $resource, string|int $id, bool $force, string $idempotencyKey): RemoteResponse
    {
        return $this->client->resource($resource)->delete($id, $force, $idempotencyKey);
    }

    /**
     * @param  array<string, mixed>  $payload  fields
     */
    public function restore(string $resource, string|int $id, array $payload, string $idempotencyKey): RemoteResponse
    {
        return $this->client->resource($resource)->restore($id, $this->names($payload, 'fields'), $idempotencyKey);
    }

    /**
     * @param  array<string, mixed>  $payload  the action's own body, verbatim
     */
    public function action(
        string $resource,
        string|int $id,
        string $action,
        array $payload,
        string $idempotencyKey
    ): RemoteResponse {
        return $this->client->resource($resource)->action($id, $action, $payload, $idempotencyKey);
    }

    /**
     * @param  array<string, mixed>  $payload  mode, id, attributes
     */
    public function validate(string $resource, array $payload): RemoteResponse
    {
        $id = $payload['id'] ?? null;

        return $this->client->resource($resource)->validate(
            is_string($payload['mode'] ?? null) ? $payload['mode'] : 'create',
            is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [],
            is_string($id) || is_int($id) ? $id : null,
        );
    }

    /**
     * @param  array<string, mixed>  $options  fields, include
     */
    public function me(array $options = []): RemoteResponse
    {
        return $this->client->me($this->names($options, 'fields'), $this->names($options, 'include'));
    }

    public function access(): RemoteResponse
    {
        return $this->client->access();
    }

    /**
     * A list of field names out of a payload key, and nothing that is not one.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function names(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;

        if (! is_array($value)) {
            return [];
        }

        $names = [];

        foreach ($value as $name) {
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }
}
