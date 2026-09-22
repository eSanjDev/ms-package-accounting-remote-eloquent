<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Client;

use Esanj\RemoteEloquent\Query\QuerySpec;
use Esanj\RemoteEloquent\Transport\RemoteResponse;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * One resource of the account service, one method per endpoint.
 */
final class ResourceEndpoint
{
    public function __construct(
        private readonly ResourceClient $client,
        private readonly string $resource,
    ) {
    }

    public function name(): string
    {
        return $this->resource;
    }

    /**
     * A copy whose writes travel with this end user's token.
     */
    public function withActor(?Authenticatable $actor): self
    {
        return new self($this->client->withActor($actor), $this->resource);
    }

    /**
     * GET {resource}/schema — the contract, as THIS application may use it.
     */
    public function schema(?string $etag = null): RemoteResponse
    {
        $headers = $etag === null || trim($etag) === ''
            ? []
            : ['If-None-Match' => trim($etag)];

        return $this->client->call(
            'schema',
            'GET',
            $this->path('schema'),
            $this->resource,
            headers: $headers,
        );
    }

    /**
     * POST {resource}/query — the body IS the QuerySpec.
     *
     * @param  array<string, mixed>|QuerySpec  $spec
     */
    public function query(array|QuerySpec $spec): RemoteResponse
    {
        return $this->client->call(
            'query',
            'POST',
            $this->path('query'),
            $this->resource,
            $spec instanceof QuerySpec ? $spec->toArray() : $spec,
        );
    }

    /**
     * POST {resource}/aggregate — {function, field, where, trashed, distinct}.
     *
     * @param  array<int, mixed>  $where  translated Where clauses, as arrays
     */
    public function aggregate(
        string $function,
        ?string $field = null,
        array $where = [],
        ?string $trashed = null,
        bool $distinct = false,
    ): RemoteResponse {
        $payload = [
            'function' => $function,
            'where' => $where,
        ];

        if ($field !== null && $field !== '') {
            $payload['field'] = $field;
        }

        if ($trashed !== null && $trashed !== '') {
            $payload['trashed'] = $trashed;
        }

        if ($distinct) {
            $payload['distinct'] = true;
        }

        return $this->client->call(
            'aggregate',
            'POST',
            $this->path('aggregate'),
            $this->resource,
            $payload,
        );
    }

    /**
     * GET {resource}/{id}?fields=&include=&trashed=
     *
     * @param  array<int, string>  $fields
     * @param  array<int, string>  $include
     */
    public function find(
        string|int $id,
        array $fields = [],
        array $include = [],
        ?string $trashed = null,
    ): RemoteResponse {
        return $this->client->call(
            'find',
            'GET',
            $this->path($id),
            $this->resource,
            query: $this->queryString(['fields' => $fields, 'include' => $include, 'trashed' => $trashed]),
            id: $id,
        );
    }

    /**
     * POST {resource} — {attributes, fields}.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $fields  which fields to read back
     */
    public function create(array $attributes, array $fields = [], ?string $idempotencyKey = null): RemoteResponse
    {
        $payload = ['attributes' => $attributes];

        if ($fields !== []) {
            $payload['fields'] = array_values($fields);
        }

        return $this->client->call(
            'create',
            'POST',
            $this->path(),
            $this->resource,
            $payload,
            idempotencyKey: $idempotencyKey,
        );
    }

    /**
     * PATCH {resource}/{id} — {attributes, if_match, fields}.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, mixed>|string|int|null  $ifMatch
     * @param  array<int, string>  $fields
     */
    public function update(
        string|int $id,
        array $attributes,
        array|string|int|null $ifMatch = null,
        array $fields = [],
        ?string $idempotencyKey = null,
    ): RemoteResponse {
        $payload = ['attributes' => $attributes];

        if ($ifMatch !== null && $ifMatch !== []) {
            $payload['if_match'] = $ifMatch;
        }

        if ($fields !== []) {
            $payload['fields'] = array_values($fields);
        }

        return $this->client->call(
            'update',
            'PATCH',
            $this->path($id),
            $this->resource,
            $payload,
            idempotencyKey: $idempotencyKey,
            id: $id,
        );
    }

    /**
     * DELETE {resource}/{id}?force=1
     */
    public function delete(string|int $id, bool $force = false, ?string $idempotencyKey = null): RemoteResponse
    {
        return $this->client->call(
            'delete',
            'DELETE',
            $this->path($id),
            $this->resource,
            query: $force ? ['force' => '1'] : [],
            idempotencyKey: $idempotencyKey,
            id: $id,
        );
    }

    /**
     * POST {resource}/{id}/restore — {fields}
     *
     * @param  array<int, string>  $fields
     */
    public function restore(string|int $id, array $fields = [], ?string $idempotencyKey = null): RemoteResponse
    {
        $payload = $fields === [] ? [] : ['fields' => array_values($fields)];

        return $this->client->call(
            'restore',
            'POST',
            $this->path($id, 'restore'),
            $this->resource,
            $payload,
            idempotencyKey: $idempotencyKey,
            id: $id,
        );
    }

    /**
     * POST {resource}/{id}/actions/{action} — {"payload": {...}}; the server reads nothing else.
     *
     * @param  array<string, mixed>  $payload
     */
    public function action(
        string|int $id,
        string $action,
        array $payload = [],
        ?string $idempotencyKey = null,
    ): RemoteResponse {
        return $this->client->call(
            'action',
            'POST',
            $this->path($id, 'actions', $action),
            $this->resource,
            ['payload' => (object) $payload],
            idempotencyKey: $idempotencyKey,
            id: $id,
        );
    }

    /**
     * POST {resource}/validate — {mode, id, attributes}.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function validate(string $mode, array $attributes, string|int|null $id = null): RemoteResponse
    {
        $payload = [
            'mode' => $mode,
            'attributes' => $attributes,
        ];

        if ($id !== null) {
            $payload['id'] = $id;
        }

        return $this->client->call(
            'validate',
            'POST',
            $this->path('validate'),
            $this->resource,
            $payload,
            id: $id,
        );
    }

    /**
     * {resource}, plus whatever segments this endpoint adds, each encoded.
     */
    private function path(string|int ...$segments): string
    {
        $path = rawurlencode($this->resource);

        foreach ($segments as $segment) {
            $path .= '/' . rawurlencode((string) $segment);
        }

        return $path;
    }

    /**
     * fields and include travel comma-separated; the server splits on the comma and rejects nothing else.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, scalar>
     */
    private function queryString(array $values): array
    {
        $query = [];

        foreach ($values as $name => $value) {
            if (is_array($value)) {
                $value = implode(',', array_map(static fn (mixed $item): string => (string) $item, $value));
            }

            if (is_scalar($value) && (string) $value !== '') {
                $query[$name] = (string) $value;
            }
        }

        return $query;
    }
}
