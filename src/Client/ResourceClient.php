<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Client;

use Esanj\RemoteEloquent\Access\RemoteAccess;
use Esanj\RemoteEloquent\Contracts\AccessTokenProvider;
use Esanj\RemoteEloquent\Contracts\ActorTokenProvider;
use Esanj\RemoteEloquent\Exceptions\AccessDeniedException;
use Esanj\RemoteEloquent\Exceptions\ConflictException;
use Esanj\RemoteEloquent\Exceptions\InvalidQueryException;
use Esanj\RemoteEloquent\Exceptions\RateLimitedException;
use Esanj\RemoteEloquent\Exceptions\RemoteAuthenticationException;
use Esanj\RemoteEloquent\Exceptions\RemoteEloquentException;
use Esanj\RemoteEloquent\Exceptions\RemoteTimeoutException;
use Esanj\RemoteEloquent\Exceptions\RemoteValidationException;
use Esanj\RemoteEloquent\Exceptions\TransportException;
use Esanj\RemoteEloquent\Exceptions\UnsupportedResourceException;
use Esanj\RemoteEloquent\Exceptions\UserMergedException;
use Esanj\RemoteEloquent\Idempotency\OperationContext;
use Esanj\RemoteEloquent\Observability\RemoteCallCollector;
use Esanj\RemoteEloquent\Transport\RemoteResponse;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The HTTP half of the resource API: headers, the error table, and nothing else.
 */
final class ResourceClient
{
    /**
     * The operations that change something, and therefore the ones that carry an Idempotency-Key.
     */
    private const MUTATING = ['create', 'update', 'delete', 'restore', 'action'];

    /**
     * A failure that happened BEFORE the request was dispatched, and only those.
     */
    private const CONNECT_LEVEL = '/could not resolve|couldn\'t resolve|failed to connect|could not connect|connection refused|name or service not known|no route to host|network is (unreachable|down)|connection reset by peer/i';

    private const TLS_FAILURE = '/\bssl\b|\btls\b|certificate|handshake/i';

    private const TIMED_OUT = '/timed out|timeout/i';

    private const REQUEST_ID = '/^[A-Za-z0-9._:-]{8,64}$/';

    /**
     * A 401 forces a fresh application token at most once per this many seconds.
     */
    private const FORCED_REFRESH_SECONDS = 30;

    private ?Authenticatable $actor = null;

    private ?string $requestId = null;

    private ?string $userToken = null;

    private float $lastForcedRefreshAt = 0.0;

    /** @var array<string, true> */
    private array $deprecationsSeen = [];

    /**
     * @param  array<string, string>  $headers  merged into every call; the ones this class builds win
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly AccessTokenProvider $accessTokens,
        private readonly ActorTokenProvider $actorTokens,
        private readonly string $baseUrl,
        private readonly string $prefix = '/api/remote/v1',
        private readonly float $timeout = 5.0,
        private readonly float $connectTimeout = 2.0,
        private readonly string $clientVersion = '2.0',
        private readonly array $headers = [],
        private ?RemoteCallCollector $calls = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly string $transport = 'rest',
    ) {
    }

    /**
     * One resource, every endpoint it has.
     */
    public function resource(string $resource): ResourceEndpoint
    {
        return new ResourceEndpoint($this, $resource);
    }

    /**
     * GET access — what this application may do.
     */
    public function access(): RemoteResponse
    {
        return $this->call('access', 'GET', 'access');
    }

    /**
     * GET users/me — the END USER, resolved from the actor's own token.
     *
     * @param  array<int, string>  $fields
     * @param  array<int, string>  $include
     */
    public function me(array $fields = [], array $include = [], ?string $userToken = null): RemoteResponse
    {
        $userToken = $userToken !== null && trim($userToken) !== '' ? trim($userToken) : $this->signedInToken();

        if ($userToken === null) {
            throw AccessDeniedException::actorDenied('users', 'me', null, null, [
                'transport' => $this->transport,
                'operation' => 'me',
                'hint' => 'GET users/me is answered with the signed-in person\'s own access token, and none is available: sign the user in through esanj/auth-bridge or pass the token.',
            ]);
        }

        $copy = clone $this;
        $copy->userToken = $userToken;

        return $copy->call('me', 'GET', 'users/me', 'users', query: $this->listQuery([
            'fields' => $fields,
            'include' => $include,
        ]));
    }

    /**
     * A copy bound to the end user a write originated from.
     */
    public function withActor(?Authenticatable $actor): self
    {
        $copy = clone $this;
        $copy->actor = $actor;

        return $copy;
    }

    public function actor(): ?Authenticatable
    {
        return $this->actor;
    }

    /**
     * A copy that stamps this id on every call instead of minting one.
     */
    public function withRequestId(?string $requestId): self
    {
        $copy = clone $this;
        $copy->requestId = $requestId === null || trim($requestId) === '' ? null : trim($requestId);

        return $copy;
    }

    /**
     * A copy that records on this collector.
     */
    public function withCollector(?RemoteCallCollector $calls): self
    {
        $copy = clone $this;
        $copy->calls = $calls;

        return $copy;
    }

    /**
     * Where this client is pointed: base URL and version prefix together.
     */
    public function baseUrl(): string
    {
        return rtrim($this->baseUrl, '/') . '/' . trim($this->prefix, '/');
    }

    public function name(): string
    {
        return $this->transport;
    }

    /**
     * Send one call and answer with what came back, or throw.
     *
     * @internal {@see ResourceEndpoint} is the shape this is meant to be
     *     called through; it is public only because the endpoint is a
     *     separate object.
     * @param  array<string, mixed>  $payload  the JSON body, for POST and PATCH
     * @param  array<string, scalar>  $query
     * @param  array<string, string>  $headers  extra headers for this one call
     */
    public function call(
        string $operation,
        string $method,
        string $path,
        string $resource = '',
        array $payload = [],
        array $query = [],
        array $headers = [],
        ?string $idempotencyKey = null,
        string|int|null $id = null,
    ): RemoteResponse {
        $mutating = in_array($operation, self::MUTATING, true);

        if (! $mutating) {
            return $this->send($operation, $method, $path, $resource, $payload, $query, $headers, null, $id, false);
        }

        $operations = $this->operations();
        $given = $idempotencyKey !== null ? trim($idempotencyKey) : '';
        $minted = $given === '';

        $key = $minted
            ? ($operations?->keyFor($operation, $resource, $id) ?? self::uuid())
            : $given;

        try {
            $response = $this->send($operation, $method, $path, $resource, $payload, $query, $headers, $key, $id, true);
        } catch (Throwable $exception) {
            if ($minted && self::isDefinitive($exception)) {
                $operations?->forget($operation, $resource, $id);
            }

            throw $exception;
        } finally {
            // The server spends an actor token on the first mutation it sees.
            $this->forgetActorToken();
        }

        if ($minted) {
            $operations?->forget($operation, $resource, $id);
        }

        return $response;
    }

    /**
     * A refusal the same key would only repeat; timeouts, 5xx, 429 and 409 in_progress keep the key for the retry.
     */
    public static function isDefinitive(Throwable $exception): bool
    {
        if ($exception instanceof RemoteValidationException) {
            return true;
        }

        if (! $exception instanceof RemoteEloquentException) {
            return $exception instanceof ModelNotFoundException;
        }

        $status = $exception->status();

        if ($status === 409) {
            return $exception->errorCode() !== 'idempotency_in_progress';
        }

        return $status >= 400 && $status < 500 && $status !== 429;
    }

    private function forgetActorToken(): void
    {
        if ($this->actor !== null && method_exists($this->actorTokens, 'forget')) {
            $this->actorTokens->forget($this->actor);
        }
    }

    private function signedInToken(): ?string
    {
        if (! method_exists($this->actorTokens, 'userToken')) {
            return null;
        }

        $token = $this->actorTokens->userToken();

        return is_string($token) && trim($token) !== '' ? trim($token) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, scalar>  $query
     * @param  array<string, string>  $headers
     */
    private function send(
        string $operation,
        string $method,
        string $path,
        string $resource,
        array $payload,
        array $query,
        array $headers,
        ?string $idempotencyKey,
        string|int|null $id,
        bool $mutating,
    ): RemoteResponse {
        $requestId = $this->requestId();
        $freshToken = false;
        $refreshed = false;
        $reconnected = false;
        $followed = false;
        $attempt = 0;

        while (true) {
            $attempt++;
            $startedAt = hrtime(true);
            $context = $this->context($operation, $resource, $method, $path, $id, $requestId);

            try {
                $raw = $this->pending($operation, $requestId, $idempotencyKey, $headers, $mutating, $freshToken)
                    ->send($method, $this->url($path), $this->options($method, $payload, $query));
            } catch (ConnectionException $exception) {
                $this->calls?->record(
                    $this->transport,
                    $operation,
                    $resource,
                    $method,
                    $path,
                    null,
                    $this->elapsed($startedAt),
                    $requestId,
                    'transport_unreachable',
                    $attempt > 1,
                );

                // Nothing reached the server, so asking again asks the same question.
                if (! $mutating && ! $reconnected && $this->isConnectLevel($exception)) {
                    $reconnected = true;

                    continue;
                }

                throw $this->connectionFailure($exception, $context);
            }

            $response = $this->decode($raw, $operation, $resource, $path, $context);
            $status = $response->status();
            $error = $this->errorOf($response);
            $code = is_string($error['code'] ?? null) ? $error['code'] : '';

            $this->calls?->record(
                $this->transport,
                $operation,
                $resource,
                $method,
                $path,
                $status,
                $this->elapsed($startedAt),
                $response->requestId() ?? $requestId,
                $code === '' ? null : $code,
                $attempt > 1,
            );

            RemoteAccess::observe($response->permissionsVersion(), $response->rateLimit());

            if ($status < 300 || $status === 304) {
                $this->warnIfDeprecated($response, $operation, $resource, $path);

                return $response;
            }

            // A revoked token looks exactly like a misconfigured one until the refresh has been tried; users/me carries the user's token, not ours.
            if ($status === 401 && ! $refreshed && $operation !== 'me' && $this->mayForceRefresh()) {
                $refreshed = true;
                $freshToken = true;

                continue;
            }

            // A write is never silently re-aimed at another record.
            if ($status === 404 && $code === 'user_merged' && ! $mutating && ! $followed && $id !== null) {
                $target = $this->mergeTarget($error);
                $retargeted = $target === null ? $path : $this->retarget($path, $id, $target);

                if ($target !== null && $retargeted !== $path) {
                    $followed = true;

                    $this->logger?->warning(
                        'Remote Eloquent followed a merged "{resource}" record from [{id}] to [{target}]. Store the new id: the redirect will not last forever.',
                        ['resource' => $resource, 'id' => (string) $id, 'target' => (string) $target, 'request_id' => $response->requestId() ?? $requestId],
                    );

                    $path = $retargeted;
                    $id = $target;

                    continue;
                }
            }

            throw $this->failure($response, $status, $error, $code, $operation, $resource, $id, $context, $followed);
        }
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function pending(
        string $operation,
        string $requestId,
        ?string $idempotencyKey,
        array $headers,
        bool $mutating,
        bool $freshToken,
    ): PendingRequest {
        $built = [
            'X-Request-Id' => $requestId,
            'X-Client-Version' => $this->clientVersion,
        ];

        $locale = $this->locale();

        if ($locale !== null) {
            $built['Accept-Language'] = $locale;
        }

        if ($mutating && $idempotencyKey !== null && $idempotencyKey !== '') {
            $built['Idempotency-Key'] = $idempotencyKey;
        }

        // validate presents the actor without spending it, so the same token can still carry the create.
        if ($mutating || $operation === 'validate') {
            $actorToken = $this->actorTokens->actorTokenFor($this->actor);

            if ($actorToken !== null && $actorToken !== '') {
                $built['Actor-Authorization'] = $this->actorScheme() . ' ' . $actorToken;
            }
        }

        $bearer = $operation === 'me' && $this->userToken !== null
            ? $this->userToken
            : $this->accessTokens->getAccessToken($freshToken);

        return $this->request()
            ->acceptJson()
            ->withoutRedirecting()
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->withToken($bearer)
            ->withHeaders(array_merge($this->headers, $headers, $built));
    }

    private function mayForceRefresh(): bool
    {
        $now = microtime(true);

        if ($now - $this->lastForcedRefreshAt < self::FORCED_REFRESH_SECONDS) {
            return false;
        }

        $this->lastForcedRefreshAt = $now;

        return true;
    }

    private function request(): PendingRequest
    {
        // Laravel 11 added createPendingRequest(); __call() reaches the same object on every supported version.
        if (method_exists($this->http, 'createPendingRequest')) {
            return $this->http->createPendingRequest();
        }

        /** @var PendingRequest $pending */
        $pending = $this->http->withOptions([]);

        return $pending;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>
     */
    private function options(string $method, array $payload, array $query): array
    {
        $options = [];

        if ($query !== []) {
            $options['query'] = $query;
        }

        if (in_array($method, ['POST', 'PATCH', 'PUT'], true)) {
            $options['json'] = $payload;
        }

        return $options;
    }

    private function url(string $path): string
    {
        return $this->baseUrl() . '/' . ltrim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function decode(Response $raw, string $operation, string $resource, string $path, array $context): RemoteResponse
    {
        $status = $raw->status();
        $headers = $raw->headers();
        $body = $raw->body();

        if (trim($body) === '') {
            return RemoteResponse::empty($status, $headers);
        }

        $decoded = $raw->json();

        if (! is_array($decoded)) {
            // A 5xx from a proxy is HTML, and calling that a malformed answer would hide the outage behind a parser error.
            if ($status >= 500) {
                throw TransportException::unavailable(
                    $this->headerValue($headers, 'x-request-id'),
                    $context + ['status' => $status],
                );
            }

            throw TransportException::malformedResponse(
                sprintf('%s %s answered %d with a body that is not JSON.', $operation, $path, $status),
                $this->headerValue($headers, 'x-request-id'),
                $context + ['status' => $status],
            );
        }

        return RemoteResponse::make($status, $decoded, $headers);
    }

    /**
     * @return array<string, mixed>
     */
    private function errorOf(RemoteResponse $response): array
    {
        $error = $response->body()['error'] ?? null;

        return is_array($error) ? $error : [];
    }

    /**
     * status + error.code -> the exception the contract names.
     *
     * @param  array<string, mixed>  $error
     * @param  array<string, mixed>  $context
     */
    private function failure(
        RemoteResponse $response,
        int $status,
        array $error,
        string $code,
        string $operation,
        string $resource,
        string|int|null $id,
        array $context,
        bool $followedMerge,
    ): Throwable {
        $requestId = $response->requestId();
        $message = is_string($error['message'] ?? null) ? $error['message'] : '';
        $field = is_string($error['field'] ?? null) ? $error['field'] : '';
        $context += ['status' => $status, 'error_code' => $code];

        if ($code === 'query_timeout') {
            return RemoteTimeoutException::queryTimeout($resource, $status, $requestId, $context);
        }

        if ($code === 'payload_too_large' || $status === 413) {
            return InvalidQueryException::payloadTooLarge($resource, $requestId, $context);
        }

        if ($code === 'rate_limited' || $status === 429) {
            $snapshot = $response->rateLimit();

            return RateLimitedException::fromServer(
                $this->intOf($error['retry_after'] ?? null) ?? $snapshot->waitSeconds(),
                $snapshot->bucket() !== '' ? $snapshot->bucket() : (is_string($error['bucket'] ?? null) ? $error['bucket'] : ''),
                $requestId,
                $context,
                $message,
            );
        }

        return match (true) {
            $status === 400 && $code === 'unknown_field' => InvalidQueryException::unknownField($resource, $field, $requestId, $context),
            $status === 400 && $code === 'operator_not_allowed' => InvalidQueryException::operatorNotAllowed(
                $resource,
                $field,
                is_string($error['operator'] ?? null) ? $error['operator'] : '',
                $requestId,
                $context,
            ),
            $status === 400 && $code === 'field_not_writable' => InvalidQueryException::fieldNotWritable($resource, $field, $requestId, $context),
            $status === 400 && $code === 'query_too_complex' => InvalidQueryException::tooComplex($resource, $requestId, $context),
            $status === 400 => InvalidQueryException::fromServer($code, $message, 400, $requestId, $context),

            // Reached only after the refresh above has already been spent.
            $status === 401 => RemoteAuthenticationException::tokenRejected($requestId, $context),

            $status === 403 => $this->denied($code, $message, $error, $requestId, $context),

            $status === 404 && $code === 'unknown_resource' => UnsupportedResourceException::fromServer($message, $resource, $requestId, $context),
            $status === 404 && $code === 'user_merged' => $this->merged($error, $resource, $id, $requestId, $context, $followedMerge),
            $status === 404 => (new ModelNotFoundException)->setModel($resource, $id === null ? [] : [$id]),

            $status === 409 => ConflictException::fromServer($code, $message, $requestId, $context),

            $status === 422 => RemoteValidationException::fromServer(
                $this->validationErrors($error, $message),
                $code,
                $requestId,
                $context,
                $message,
            ),

            $status === 405 || $status === 415 => InvalidQueryException::fromServer($code, $message, $status, $requestId, $context),

            $status === 503 => TransportException::unavailable($requestId, $context, $message),
            $status === 504 => RemoteTimeoutException::fromServer($message, $status, $requestId, $context),
            $status >= 500 => TransportException::serverError($status, $code, $requestId, $context),

            default => TransportException::unexpectedStatus($status, $requestId, $context),
        };
    }

    /**
     * @param  array<string, mixed>  $error
     * @param  array<string, mixed>  $context
     */
    private function denied(string $code, string $message, array $error, ?string $requestId, array $context): AccessDeniedException
    {
        if ($code === 'permission_denied') {
            RemoteAccess::denied();
        }

        $permission = is_string($error['required_permission'] ?? null) ? $error['required_permission'] : null;

        return AccessDeniedException::fromServer($code, $message, $permission, $requestId, $context);
    }

    /**
     * @param  array<string, mixed>  $error
     * @param  array<string, mixed>  $context
     */
    private function merged(
        array $error,
        string $resource,
        string|int|null $id,
        ?string $requestId,
        array $context,
        bool $followedMerge,
    ): UserMergedException {
        $target = $this->mergeTarget($error);

        if ($target === null) {
            return UserMergedException::withoutTarget($resource, $id ?? '', $requestId, $context);
        }

        return $followedMerge
            ? UserMergedException::chained($resource, $id ?? '', $target, $requestId, $context)
            : UserMergedException::into($resource, $id ?? '', $target, $requestId, $context);
    }

    /**
     * @param  array<string, mixed>  $error
     */
    private function mergeTarget(array $error): string|int|null
    {
        $details = is_array($error['details'] ?? null) ? $error['details'] : [];

        foreach ([$error['merged_into'] ?? null, $details['merged_into'] ?? null] as $candidate) {
            if (is_int($candidate)) {
                return $candidate;
            }

            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * error.details IS the message bag for a 422; the server puts per-field information nowhere else.
     *
     * @param  array<string, mixed>  $error
     * @return array<string, mixed>
     */
    private function validationErrors(array $error, string $message): array
    {
        $details = is_array($error['details'] ?? null) ? $error['details'] : [];

        if ($details !== []) {
            return $details;
        }

        return ['*' => [$message !== '' ? $message : 'The account service refused this record.']];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function connectionFailure(ConnectionException $exception, array $context): Throwable
    {
        $reason = $exception->getMessage();

        if (preg_match(self::TLS_FAILURE, $reason) === 1) {
            return TransportException::tlsFailure($reason, $context, $exception);
        }

        if (preg_match(self::CONNECT_LEVEL, $reason) === 1) {
            return TransportException::connectionFailed($reason, $context, $exception);
        }

        // Dispatched, then silent.
        if (preg_match(self::TIMED_OUT, $reason) === 1) {
            return RemoteTimeoutException::deadlineExceeded($this->timeout, $context, $exception);
        }

        return TransportException::connectionFailed($reason, $context, $exception);
    }

    private function isConnectLevel(ConnectionException $exception): bool
    {
        $reason = $exception->getMessage();

        return preg_match(self::TLS_FAILURE, $reason) !== 1
            && preg_match(self::CONNECT_LEVEL, $reason) === 1;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, scalar>
     */
    private function listQuery(array $values): array
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

    /**
     * Swap the old id for the new one in a path this client built.
     */
    private function retarget(string $path, string|int $id, string|int $target): string
    {
        $segments = explode('/', $path);

        foreach ($segments as $index => $segment) {
            if ($segment === rawurlencode((string) $id) || $segment === (string) $id) {
                $segments[$index] = rawurlencode((string) $target);

                return implode('/', $segments);
            }
        }

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function context(
        string $operation,
        string $resource,
        string $method,
        string $path,
        string|int|null $id,
        string $requestId,
    ): array {
        $context = [
            'transport' => $this->transport,
            'operation' => $operation,
            'method' => $method,
            'path' => $path,
            'request_id' => $requestId,
        ];

        if ($resource !== '') {
            $context['resource'] = $resource;
        }

        if ($id !== null) {
            $context['id'] = $id;
        }

        return $context;
    }

    private function warnIfDeprecated(RemoteResponse $response, string $operation, string $resource, string $path): void
    {
        if ($this->logger === null || ! $response->isDeprecated()) {
            return;
        }

        $seen = $resource . '.' . $operation;

        if (isset($this->deprecationsSeen[$seen])) {
            return;
        }

        $this->deprecationsSeen[$seen] = true;

        $this->logger->warning(
            'The account service marked {path} deprecated.',
            [
                'path' => $path,
                'resource' => $resource,
                'operation' => $operation,
                'deprecation' => $response->deprecation(),
                'sunset' => $response->sunset(),
            ],
        );
    }

    /**
     * The id this call travels under.
     */
    private function requestId(): string
    {
        if ($this->requestId !== null) {
            return $this->requestId;
        }

        $inbound = $this->inboundRequestId();

        return $inbound ?? self::uuid();
    }

    private function inboundRequestId(): ?string
    {
        try {
            $container = Container::getInstance();

            if (! $container->bound('request')) {
                return null;
            }

            $request = $container->make('request');

            if (! $request instanceof Request) {
                return null;
            }

            $id = $request->headers->get('X-Request-Id');
        } catch (Throwable) {
            return null;
        }

        return is_string($id) && preg_match(self::REQUEST_ID, trim($id)) === 1 ? trim($id) : null;
    }

    /**
     * The keys already minted in THIS request.
     */
    private function operations(): ?OperationContext
    {
        try {
            $operations = Container::getInstance()->make(OperationContext::class);
        } catch (Throwable) {
            return null;
        }

        return $operations instanceof OperationContext ? $operations : null;
    }

    private function locale(): ?string
    {
        try {
            $container = Container::getInstance();

            if (! method_exists($container, 'getLocale')) {
                return null;
            }

            $locale = $container->getLocale();
        } catch (Throwable) {
            return null;
        }

        return is_string($locale) && $locale !== '' ? $locale : null;
    }

    private function actorScheme(): string
    {
        if (! method_exists($this->actorTokens, 'tokenType')) {
            return 'Bearer';
        }

        $type = $this->actorTokens->tokenType();

        return is_string($type) && trim($type) !== '' ? trim($type) : 'Bearer';
    }

    /**
     * @param  array<string, string|array<int, string>>  $headers
     */
    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) !== $name) {
                continue;
            }

            $value = is_array($value) ? ($value[0] ?? '') : $value;

            return (string) $value !== '' ? (string) $value : null;
        }

        return null;
    }

    private function intOf(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function elapsed(float|int $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1_000_000;
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);

        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
