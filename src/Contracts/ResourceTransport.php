<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Contracts;

use Esanj\RemoteEloquent\Exceptions\AccessDeniedException;
use Esanj\RemoteEloquent\Exceptions\ConflictException;
use Esanj\RemoteEloquent\Exceptions\InvalidQueryException;
use Esanj\RemoteEloquent\Exceptions\RateLimitedException;
use Esanj\RemoteEloquent\Exceptions\RemoteAuthenticationException;
use Esanj\RemoteEloquent\Exceptions\RemoteTimeoutException;
use Esanj\RemoteEloquent\Exceptions\RemoteValidationException;
use Esanj\RemoteEloquent\Exceptions\TransportException;
use Esanj\RemoteEloquent\Exceptions\UnsupportedResourceException;
use Esanj\RemoteEloquent\Exceptions\UserMergedException;
use Esanj\RemoteEloquent\Transport\RemoteResponse;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * One endpoint of the Accounting resource API, one method.
 *
 * @throws InvalidQueryException        400 invalid_query, unknown_field, operator_not_allowed,
 *     field_not_writable, query_too_complex; 413 payload_too_large
 * @throws RemoteAuthenticationException 401 after the single refresh
 * @throws AccessDeniedException        403 permission_denied, privileged_account, actor_denied
 * @throws ModelNotFoundException       404 not_found
 * @throws UnsupportedResourceException 404 unknown_resource
 * @throws UserMergedException          404 user_merged that could not be followed
 * @throws ConflictException            409 stale_record, idempotency_mismatch, idempotency_in_progress
 * @throws RemoteValidationException    422 validation_failed, field_locked
 * @throws RateLimitedException         429 rate_limited
 * @throws RemoteTimeoutException       503/504 query_timeout
 * @throws TransportException           503 unavailable, a connect/TLS failure, an unreadable body
 */
interface ResourceTransport
{
    /**
     * The driver name this instance speaks.
     */
    public function name(): string;

    /**
     * A copy of this transport bound to the end user a write originated from.
     */
    public function withActor(?Authenticatable $actor, #[\SensitiveParameter] ?string $subjectToken = null): static;

    /**
     * GET {resource}/schema
     */
    public function schema(string $resource, ?string $etag = null): RemoteResponse;

    /**
     * POST {resource}/query — the body IS the QuerySpec.
     *
     * @param  array<string, mixed>  $spec  fields, where, order, limit, offset,
     *     with_total, include, counts, trashed, distinct
     */
    public function query(string $resource, array $spec): RemoteResponse;

    /**
     * POST {resource}/aggregate — {function, field, where, trashed}.
     *
     * @param  array<string, mixed>  $spec
     */
    public function aggregate(string $resource, array $spec): RemoteResponse;

    /**
     * GET {resource}/{id}?fields=&include=&trashed=
     *
     * @param  array<string, mixed>  $options  fields, include, trashed
     */
    public function find(string $resource, string|int $id, array $options = []): RemoteResponse;

    /**
     * POST {resource} — {attributes, fields}.
     *
     * @param  array<string, mixed>  $payload
     */
    public function create(string $resource, array $payload, string $idempotencyKey): RemoteResponse;

    /**
     * PATCH {resource}/{id} — {attributes, if_match, fields}.
     *
     * @param  array<string, mixed>  $payload
     */
    public function update(string $resource, string|int $id, array $payload, string $idempotencyKey): RemoteResponse;

    /**
     * DELETE {resource}/{id}?force=1
     */
    public function delete(string $resource, string|int $id, bool $force, string $idempotencyKey): RemoteResponse;

    /**
     * POST {resource}/{id}/restore — {fields}
     *
     * @param  array<string, mixed>  $payload
     */
    public function restore(string $resource, string|int $id, array $payload, string $idempotencyKey): RemoteResponse;

    /**
     * POST {resource}/{id}/actions/{action} — the body is the action payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function action(
        string $resource,
        string|int $id,
        string $action,
        array $payload,
        string $idempotencyKey
    ): RemoteResponse;

    /**
     * POST {resource}/validate — {mode, id, attributes}.
     *
     * @param  array<string, mixed>  $payload
     */
    public function validate(string $resource, array $payload): RemoteResponse;

    /**
     * GET users/me?fields=&include=
     *
     * @param  array<string, mixed>  $options  fields, include
     */
    public function me(array $options = []): RemoteResponse;

    /**
     * GET access
     */
    public function access(): RemoteResponse;
}
