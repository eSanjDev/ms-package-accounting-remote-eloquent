<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Testing;

use Closure;
use Esanj\RemoteEloquent\Contracts\ResourceTransport;
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
use Illuminate\Support\Arr;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * The account service, in memory, for a consumer's test suite.
 */
final class FakeResourceTransport implements ResourceTransport
{
    /**
     * The bucket rows land in when they are seeded without a resource name.
     */
    public const ANY = '*';

    /**
     * The two rate-limit buckets the account service publishes.
     */
    public const BUCKET_READ = 'read';

    public const BUCKET_WRITE = 'write';

    /**
     * The application segment of an idempotency scope.
     */
    private const APPLICATION = 'fake';

    /** @var array<string, list<array<string, mixed>>> */
    private static array $rows = [];

    /** @var array<string, string> */
    private static array $keys = [];

    /** @var array<string, array<string, mixed>> */
    private static array $schemas = [];

    /** @var array<string, array{status: int, code: string, message: string, retry_after: int, bucket: string}> */
    private static array $failures = [];

    /** @var list<array<string, mixed>> */
    private static array $calls = [];

    /** @var array<string, Closure> */
    private static array $actionHandlers = [];

    /** @var array<string, array<string, list<string>>> */
    private static array $validationErrors = [];

    /** @var array<string, mixed>|null */
    private static ?array $access = null;

    /** @var array<string, mixed>|null */
    private static ?array $me = null;

    /** @var array<string, array{fingerprint: string, response: RemoteResponse}> */
    private static array $replays = [];

    private static int $sequence = 0;

    private ?string $actor = null;

    /**
     * @param  array<int, array<string, mixed>>  $rows  seeded under {@see self::ANY}
     * @param  int  $status  non-zero makes every call fail with that status
     */
    public function __construct(array $rows = [], int $status = 0)
    {
        self::reset();

        if ($rows !== []) {
            self::seed(self::ANY, $rows);
        }

        if ($status !== 0) {
            self::failWith(self::ANY, $status);
        }
    }

    public function name(): string
    {
        return 'fake';
    }

    public function withActor(?Authenticatable $actor): static
    {
        $clone = clone $this;

        $clone->actor = $actor === null ? null : (string) $actor->getAuthIdentifier();

        return $clone;
    }

    /**
     * Put rows behind a resource, replacing whatever was there.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public static function seed(string $resource, array $rows): void
    {
        $stored = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $stored[] = $row;
            }
        }

        self::$rows[$resource] = $stored;
    }

    /**
     * Add one row to a resource, leaving the rest alone.
     *
     * @param  array<string, mixed>  $row
     */
    public static function seedOne(string $resource, array $row): void
    {
        self::$rows[$resource][] = $row;
    }

    /**
     * The primary key of a resource whose key is not "id".
     */
    public static function identifyBy(string $resource, string $keyName): void
    {
        self::$keys[$resource] = $keyName;
    }

    /**
     * Answer GET {resource}/schema with this payload instead of one inferred from the rows.
     *
     * @param  array<string, mixed>  $schema
     */
    public static function schemaFor(string $resource, array $schema): void
    {
        self::$schemas[$resource] = isset($schema['data']) && is_array($schema['data'])
            ? $schema['data']
            : $schema;
    }

    /**
     * Make every call to a resource fail, so the error paths get a test too.
     */
    public static function failWith(
        string $resource,
        int $status,
        string $code = '',
        string $message = '',
        int $retryAfter = 30,
        string $bucket = '',
    ): void {
        self::$failures[$resource] = [
            'status' => $status,
            'code' => $code !== '' ? $code : self::defaultCodeFor($status),
            'message' => $message,
            'retry_after' => $retryAfter,
            'bucket' => $bucket,
        ];
    }

    public static function stopFailing(string $resource = self::ANY): void
    {
        unset(self::$failures[$resource]);
    }

    /**
     * Make POST {resource}/validate answer 422 with these errors.
     *
     * @param  array<string, list<string>|string>  $errors
     */
    public static function failValidation(string $resource, array $errors): void
    {
        $normalized = [];

        foreach ($errors as $field => $messages) {
            $normalized[(string) $field] = array_values(array_map(
                static fn (mixed $message): string => (string) $message,
                is_array($messages) ? $messages : [$messages],
            ));
        }

        self::$validationErrors[$resource] = $normalized;
    }

    /**
     * Script a domain action.
     *
     * @param  Closure(array<string, mixed>, array<string, mixed>): mixed  $handler
     */
    public static function handleAction(string $resource, string $action, Closure $handler): void
    {
        self::$actionHandlers[$resource . '@' . $action] = $handler;
    }

    /**
     * The row GET users/me answers with.
     *
     * @param  array<string, mixed>  $row
     */
    public static function actingAs(array $row): void
    {
        self::$me = $row;
    }

    /**
     * The payload GET access answers with.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function allowAccess(array $payload): void
    {
        self::$access = $payload;
    }

    /**
     * @param  list<string>  $permissions
     */
    public static function grant(array $permissions): void
    {
        $access = self::$access ?? self::defaultAccess();
        $access['permissions'] = array_values($permissions);

        self::$access = $access;
    }

    /**
     * Forget every seeded row, failure, call and handler.
     */
    public static function reset(): void
    {
        self::$rows = [];
        self::$keys = [];
        self::$schemas = [];
        self::$failures = [];
        self::$calls = [];
        self::$actionHandlers = [];
        self::$validationErrors = [];
        self::$access = null;
        self::$me = null;
        self::$replays = [];
        self::$sequence = 0;
    }

    /**
     * The rows a resource holds right now — after the writes a test made.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(string $resource): array
    {
        return self::$rows[$resource] ?? self::$rows[self::ANY] ?? [];
    }

    /**
     * Every call, in order: method, resource, spec/payload, id, actor.
     *
     * @return list<array<string, mixed>>
     */
    public static function calls(): array
    {
        return self::$calls;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function callsTo(string $method, ?string $resource = null): array
    {
        return array_values(array_filter(
            self::$calls,
            static fn (array $call): bool => $call['method'] === $method
                && ($resource === null || $call['resource'] === $resource),
        ));
    }

    public static function requestCount(): int
    {
        return count(self::$calls);
    }

    /**
     * @param  (callable(array<string, mixed>, array<string, mixed>): bool)|null  $matcher  given the QuerySpec
     */
    public static function assertQueried(string $resource, ?callable $matcher = null): void
    {
        self::assertCalled('query', $resource, $matcher, 'spec');
    }

    /**
     * @param  (callable(array<string, mixed>, array<string, mixed>): bool)|null  $matcher  given the aggregate body
     */
    public static function assertAggregated(string $resource, ?callable $matcher = null): void
    {
        self::assertCalled('aggregate', $resource, $matcher, 'spec');
    }

    /**
     * @param  (callable(array<string, mixed>, array<string, mixed>): bool)|null  $matcher  given the attributes
     */
    public static function assertCreated(string $resource, ?callable $matcher = null): void
    {
        self::assertCalled('create', $resource, $matcher, 'attributes');
    }

    /**
     * @param  (callable(array<string, mixed>, array<string, mixed>): bool)|null  $matcher  given the attributes
     */
    public static function assertUpdated(string $resource, ?callable $matcher = null): void
    {
        self::assertCalled('update', $resource, $matcher, 'attributes');
    }

    /**
     * @param  (callable(array<string, mixed>, array<string, mixed>): bool)|null  $matcher  given the call record
     */
    public static function assertDeleted(string $resource, ?callable $matcher = null): void
    {
        self::assertCalled('delete', $resource, $matcher, null);
    }

    public static function assertNothingDeleted(): void
    {
        $deletes = self::callsTo('delete');

        PHPUnit::assertSame(
            [],
            $deletes,
            sprintf(
                'Expected no record to be deleted remotely, but %d delete call(s) were made: %s.',
                count($deletes),
                self::describeTargets($deletes),
            ),
        );
    }

    /**
     * @param  (callable(array<string, mixed>, array<string, mixed>): bool)|null  $matcher  given the payload
     */
    public static function assertActioned(string $resource, string $action, ?callable $matcher = null): void
    {
        $calls = array_values(array_filter(
            self::callsTo('action', $resource),
            static fn (array $call): bool => ($call['action'] ?? null) === $action,
        ));

        PHPUnit::assertNotEmpty(
            $calls,
            sprintf('Expected the "%s" action to run on "%s", but it never did.', $action, $resource),
        );

        if ($matcher === null) {
            return;
        }

        foreach ($calls as $call) {
            if ($matcher($call['payload'] ?? [], $call) === true) {
                PHPUnit::assertTrue(true);

                return;
            }
        }

        PHPUnit::fail(sprintf(
            'The "%s" action ran on "%s" %d time(s), but none matched the expectation.',
            $action,
            $resource,
            count($calls),
        ));
    }

    /**
     * How a test catches an N+1: every remote call counts, of every kind.
     */
    public static function assertRequestCount(int $expected): void
    {
        PHPUnit::assertSame(
            $expected,
            count(self::$calls),
            sprintf(
                "Expected %d remote call(s), %d were made:\n%s",
                $expected,
                count(self::$calls),
                self::describeCalls(),
            ),
        );
    }

    public function schema(string $resource, ?string $etag = null): RemoteResponse
    {
        $this->record('schema', $resource, ['etag' => $etag]);
        $this->guard($resource, self::BUCKET_READ);

        return $this->respond(200, ['data' => self::schemaPayload($resource)], $resource);
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    public function query(string $resource, array $spec): RemoteResponse
    {
        $this->record('query', $resource, ['spec' => $spec]);
        $this->guard($resource, self::BUCKET_READ);

        $matched = self::applyWheres(
            self::visibleRows($resource, is_string($spec['trashed'] ?? null) ? $spec['trashed'] : null),
            is_array($spec['where'] ?? null) ? $spec['where'] : [],
        );

        /** @var list<string> $fields */
        $fields = self::stringList($spec['fields'] ?? null);
        /** @var list<string> $counts */
        $counts = self::stringList($spec['counts'] ?? null);
        /** @var list<string> $include */
        $include = self::stringList($spec['include'] ?? null);

        if (($spec['distinct'] ?? false) === true) {
            $matched = self::distinct($matched, $fields);
        }

        $matched = self::applyOrder($matched, is_array($spec['order'] ?? null) ? $spec['order'] : []);

        $total = count($matched);
        $offset = is_numeric($spec['offset'] ?? null) ? max(0, (int) $spec['offset']) : 0;
        $limit = is_numeric($spec['limit'] ?? null) ? max(0, (int) $spec['limit']) : null;

        $page = array_slice($matched, $offset, $limit === null ? null : $limit + 1);
        $hasMore = $limit !== null && count($page) > $limit;

        if ($hasMore) {
            $page = array_slice($page, 0, $limit);
        }

        $data = [];

        foreach ($page as $row) {
            $data[] = self::project($row, $fields, $counts, $include);
        }

        $meta = [
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => $hasMore,
        ];

        if (($spec['with_total'] ?? false) === true) {
            $meta['total'] = $total;
        }

        return $this->respond(200, [
            'data' => $data,
            'meta' => $meta,
            'schema_version' => self::versionTag($resource),
        ], $resource);
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    public function aggregate(string $resource, array $spec): RemoteResponse
    {
        $this->record('aggregate', $resource, ['spec' => $spec]);
        $this->guard($resource, self::BUCKET_READ);

        $rows = self::applyWheres(
            self::visibleRows($resource, is_string($spec['trashed'] ?? null) ? $spec['trashed'] : null),
            is_array($spec['where'] ?? null) ? $spec['where'] : [],
        );

        $function = strtolower((string) ($spec['function'] ?? 'count'));
        $field = isset($spec['field']) && is_string($spec['field']) ? $spec['field'] : null;

        if (($spec['distinct'] ?? false) === true && $function === 'count' && $field !== null) {
            $values = [];

            foreach ($rows as $row) {
                $value = $row[$field] ?? null;

                if ($value !== null) {
                    $values[serialize($value)] = true;
                }
            }

            return $this->respond(200, ['data' => ['value' => count($values)]], $resource);
        }

        return $this->respond(200, [
            'data' => ['value' => self::computeAggregate($resource, $function, $field, $rows)],
        ], $resource);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function find(string $resource, string|int $id, array $options = []): RemoteResponse
    {
        $this->record('find', $resource, ['id' => $id, 'options' => $options]);
        $this->guard($resource, self::BUCKET_READ);

        $trashed = is_string($options['trashed'] ?? null) ? $options['trashed'] : null;
        $index = self::locate($resource, $id, $trashed);

        if ($index === null) {
            throw self::notFound($resource, $id);
        }

        return $this->respond(200, [
            'data' => self::project(
                self::$rows[self::bucket($resource)][$index],
                self::stringList($options['fields'] ?? null),
                [],
                self::stringList($options['include'] ?? null),
            ),
            'schema_version' => self::versionTag($resource),
        ], $resource);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(string $resource, array $payload, string $idempotencyKey): RemoteResponse
    {
        $this->record('create', $resource, [
            'attributes' => is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [],
            'payload' => $payload,
            'idempotency_key' => $idempotencyKey,
        ]);

        $this->guard($resource, self::BUCKET_WRITE);

        $fingerprint = self::fingerprint('POST', 'create', $payload);
        $replay = self::replay('create', $resource, null, $idempotencyKey, $fingerprint);

        if ($replay !== null) {
            return $replay;
        }

        $bucket = self::bucket($resource);
        $key = self::keyName($resource);
        $attributes = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];

        $row = $attributes;

        if (! array_key_exists($key, $row) || $row[$key] === null) {
            $row[$key] = self::nextId($bucket, $key);
        }

        $stamp = self::now();
        $row['created_at'] ??= $stamp;
        $row['updated_at'] ??= $stamp;

        self::$rows[$bucket][] = $row;

        $response = $this->respond(201, [
            'data' => self::project($row, self::stringList($payload['fields'] ?? null), [], []),
            'schema_version' => self::versionTag($resource),
        ], $resource);

        return self::remember('create', $resource, null, $idempotencyKey, $fingerprint, $response);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(string $resource, string|int $id, array $payload, string $idempotencyKey): RemoteResponse
    {
        $this->record('update', $resource, [
            'id' => $id,
            'attributes' => is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [],
            'payload' => $payload,
            'idempotency_key' => $idempotencyKey,
        ]);

        $this->guard($resource, self::BUCKET_WRITE);

        $fingerprint = self::fingerprint('PATCH', 'update', $payload);
        $replay = self::replay('update', $resource, $id, $idempotencyKey, $fingerprint);

        if ($replay !== null) {
            return $replay;
        }

        $bucket = self::bucket($resource);
        $index = self::locate($resource, $id, 'with');

        if ($index === null) {
            throw self::notFound($resource, $id);
        }

        $row = self::$rows[$bucket][$index];

        if (array_key_exists('if_match', $payload) && $payload['if_match'] !== null) {
            $current = $row['version'] ?? null;

            if ($current !== null && (string) $current !== (string) $payload['if_match']) {
                throw ConflictException::staleRecord($resource, $id, null, [
                    'transport' => 'fake',
                    'expected' => (string) $payload['if_match'],
                    'actual' => (string) $current,
                ]);
            }
        }

        $attributes = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];

        $row = array_merge($row, $attributes);
        $row['updated_at'] = self::now();

        if (isset($row['version']) && is_numeric($row['version'])) {
            $row['version'] = (int) $row['version'] + 1;
        }

        self::$rows[$bucket][$index] = $row;

        $response = $this->respond(200, [
            'data' => self::project($row, self::stringList($payload['fields'] ?? null), [], []),
            'schema_version' => self::versionTag($resource),
        ], $resource);

        return self::remember('update', $resource, $id, $idempotencyKey, $fingerprint, $response);
    }

    public function delete(string $resource, string|int $id, bool $force, string $idempotencyKey): RemoteResponse
    {
        $this->record('delete', $resource, [
            'id' => $id,
            'force' => $force,
            'idempotency_key' => $idempotencyKey,
        ]);

        $this->guard($resource, self::BUCKET_WRITE);

        $fingerprint = self::fingerprint('DELETE', 'delete', ['force' => $force]);
        $replay = self::replay('delete', $resource, $id, $idempotencyKey, $fingerprint);

        if ($replay !== null) {
            return $replay;
        }

        $bucket = self::bucket($resource);
        $index = self::locate($resource, $id, 'with');

        if ($index === null) {
            throw self::notFound($resource, $id);
        }

        $row = self::$rows[$bucket][$index];

        $softDeletes = array_key_exists('deleted_at', $row)
            || (bool) (self::$schemas[$resource]['soft_deletes'] ?? false);

        if (! $force && $softDeletes) {
            $row['deleted_at'] = self::now();
            self::$rows[$bucket][$index] = $row;
        } else {
            unset(self::$rows[$bucket][$index]);
            self::$rows[$bucket] = array_values(self::$rows[$bucket]);
        }

        $response = $this->respond(200, ['data' => ['deleted' => true]], $resource);

        return self::remember('delete', $resource, $id, $idempotencyKey, $fingerprint, $response);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function restore(string $resource, string|int $id, array $payload, string $idempotencyKey): RemoteResponse
    {
        $this->record('restore', $resource, ['id' => $id, 'payload' => $payload, 'idempotency_key' => $idempotencyKey]);

        $this->guard($resource, self::BUCKET_WRITE);

        $fingerprint = self::fingerprint('POST', 'restore', $payload);
        $replay = self::replay('restore', $resource, $id, $idempotencyKey, $fingerprint);

        if ($replay !== null) {
            return $replay;
        }

        $bucket = self::bucket($resource);
        $index = self::locate($resource, $id, 'with');

        if ($index === null) {
            throw self::notFound($resource, $id);
        }

        $row = self::$rows[$bucket][$index];
        $row['deleted_at'] = null;
        $row['updated_at'] = self::now();

        self::$rows[$bucket][$index] = $row;

        $response = $this->respond(200, [
            'data' => self::project($row, self::stringList($payload['fields'] ?? null), [], []),
            'schema_version' => self::versionTag($resource),
        ], $resource);

        return self::remember('restore', $resource, $id, $idempotencyKey, $fingerprint, $response);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function action(
        string $resource,
        string|int $id,
        string $action,
        array $payload,
        string $idempotencyKey
    ): RemoteResponse {
        $this->record('action', $resource, [
            'id' => $id,
            'action' => $action,
            'payload' => $payload,
            'idempotency_key' => $idempotencyKey,
        ]);

        $this->guard($resource, self::BUCKET_WRITE);

        $operation = 'action:' . $action;
        $fingerprint = self::fingerprint('POST', $operation, $payload);
        $replay = self::replay($operation, $resource, $id, $idempotencyKey, $fingerprint);

        if ($replay !== null) {
            return $replay;
        }

        $bucket = self::bucket($resource);
        $index = self::locate($resource, $id, 'with');

        if ($index === null) {
            throw self::notFound($resource, $id);
        }

        $row = self::$rows[$bucket][$index];
        $handler = self::$actionHandlers[$resource . '@' . $action] ?? null;
        $data = $handler === null ? $row : $handler($payload, $row);

        if (is_array($data) && ! array_is_list($data) && array_key_exists(self::keyName($resource), $data)) {
            self::$rows[$bucket][$index] = $data;
        }

        $response = $this->respond(200, [
            'data' => $data,
            'schema_version' => self::versionTag($resource),
        ], $resource);

        return self::remember($operation, $resource, $id, $idempotencyKey, $fingerprint, $response);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function validate(string $resource, array $payload): RemoteResponse
    {
        $this->record('validate', $resource, ['payload' => $payload]);

        $this->guard($resource, self::BUCKET_WRITE);

        $errors = self::$validationErrors[$resource] ?? self::$validationErrors[self::ANY] ?? null;

        if ($errors !== null) {
            throw RemoteValidationException::fromServer($errors, 'validation_failed', null, [
                'transport' => 'fake',
                'resource' => $resource,
                'mode' => $payload['mode'] ?? null,
            ]);
        }

        return $this->respond(204, [], $resource);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function me(array $options = []): RemoteResponse
    {
        $this->record('me', 'users', ['options' => $options]);
        $this->guard('users', self::BUCKET_READ);

        $row = self::$me;

        if ($row === null && $this->actor !== null) {
            $index = self::locate('users', $this->actor, null);
            $row = $index === null ? null : self::$rows[self::bucket('users')][$index];
        }

        if ($row === null) {
            throw AccessDeniedException::actorDenied('users', 'me', null, null, [
                'transport' => 'fake',
                'hint' => 'Seed the end user with FakeResourceTransport::actingAs([...]), or bind an actor whose id is a seeded users row.',
            ]);
        }

        return $this->respond(200, [
            'data' => self::project($row, self::stringList($options['fields'] ?? null), [], self::stringList($options['include'] ?? null)),
            'schema_version' => self::versionTag('users'),
        ], 'users');
    }

    public function access(): RemoteResponse
    {
        $this->record('access', '', []);
        $this->guard(self::ANY, self::BUCKET_READ);

        return $this->respond(200, ['data' => self::$access ?? self::defaultAccess()]);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function record(string $method, string $resource, array $details): void
    {
        self::$calls[] = array_merge([
            'method' => $method,
            'resource' => $resource,
            'actor' => $this->actor,
        ], $details);
    }

    /**
     * Throw whatever failWith() asked for, mapped exactly as the real transport maps the same status and code.
     */
    private function guard(string $resource, string $bucket = self::BUCKET_READ): void
    {
        $failure = self::$failures[$resource] ?? self::$failures[self::ANY] ?? null;

        if ($failure === null) {
            return;
        }

        if (($failure['bucket'] ?? '') !== '') {
            $bucket = (string) $failure['bucket'];
        }

        $status = $failure['status'];
        $code = $failure['code'];
        $message = $failure['message'] !== ''
            ? $failure['message']
            : sprintf('The fake transport was told to answer %d %s for "%s".', $status, $code, $resource);
        $context = ['transport' => 'fake', 'resource' => $resource];

        throw match (true) {
            $status === 401 => RemoteAuthenticationException::tokenRejected(null, $context),
            $status === 403 => AccessDeniedException::fromServer($code, $message, null, null, $context),
            $status === 404 && $code === 'unknown_resource' => UnsupportedResourceException::unknown($resource, null, $context),
            $status === 404 && $code === 'user_merged' => UserMergedException::withoutTarget($resource, '', null, $context),
            $status === 404 => self::notFound($resource, ''),
            $status === 409 => ConflictException::fromServer($code, $message, null, $context),
            $status === 413 => InvalidQueryException::payloadTooLarge($resource, null, $context),
            $status === 422 => RemoteValidationException::fromServer(
                self::$validationErrors[$resource] ?? ['*' => [$message]],
                $code,
                null,
                $context,
            ),
            $status === 429 => RateLimitedException::fromServer($failure['retry_after'], $bucket, null, $context, $message),
            $status === 400 => InvalidQueryException::fromServer($code, $message, 400, null, $context),
            ($status === 503 || $status === 504) && $code === 'query_timeout' => RemoteTimeoutException::queryTimeout($resource, $status, null, $context),
            $status === 503 => TransportException::unavailable(null, $context, $message),
            $status === 504 => RemoteTimeoutException::fromServer($message, $status, null, $context),
            default => TransportException::unexpectedStatus($status, null, $context),
        };
    }

    private static function notFound(string $resource, string|int $id): ModelNotFoundException
    {
        return (new ModelNotFoundException)->setModel($resource, [$id]);
    }

    private static function defaultCodeFor(int $status): string
    {
        return match ($status) {
            400 => 'invalid_query',
            401 => 'unauthenticated',
            403 => 'permission_denied',
            404 => 'not_found',
            409 => 'stale_record',
            413 => 'payload_too_large',
            422 => 'validation_failed',
            429 => 'rate_limited',
            503, 504 => 'unavailable',
            default => 'error',
        };
    }

    private static function replayScope(
        string $operation,
        string $resource,
        string|int|null $id,
        string $key,
    ): string {
        return implode(':', [
            self::APPLICATION,
            $operation,
            $resource,
            $id === null || $id === '' ? 'new' : (string) $id,
            $key,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function fingerprint(string $method, string $operation, array $payload): string
    {
        $canonical = json_encode(
            [
                'method' => $method,
                'route' => $operation,
                'body' => self::canonicalize($payload),
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );

        return hash('sha256', (string) $canonical);
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(static fn (mixed $item): mixed => self::canonicalize($item), $value);
    }

    /**
     * The answer this key already produced, or null when it is a new key.
     *
     * @throws ConflictException
     */
    private static function replay(
        string $operation,
        string $resource,
        string|int|null $id,
        string $key,
        string $fingerprint,
    ): ?RemoteResponse {
        $stored = self::$replays[self::replayScope($operation, $resource, $id, $key)] ?? null;

        if ($stored === null) {
            return null;
        }

        if (! hash_equals($stored['fingerprint'], $fingerprint)) {
            throw ConflictException::idempotencyMismatch($resource, $operation, null, [
                'transport' => 'fake',
                'idempotency_key' => $key,
                'id' => $id,
            ]);
        }

        return $stored['response'];
    }

    private static function remember(
        string $operation,
        string $resource,
        string|int|null $id,
        string $key,
        string $fingerprint,
        RemoteResponse $response,
    ): RemoteResponse {
        self::$replays[self::replayScope($operation, $resource, $id, $key)] = [
            'fingerprint' => $fingerprint,
            'response' => $response,
        ];

        return $response;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function respond(int $status, array $body, string $resource = '', array $headers = []): RemoteResponse
    {
        self::$sequence++;

        $headers['X-Request-Id'] = $headers['X-Request-Id'] ?? 'fake-' . self::$sequence;

        if ($resource !== '' && $resource !== self::ANY) {
            $headers['X-Schema-Version'] = self::versionTag($resource);
        }

        return $body === []
            ? RemoteResponse::empty($status, $headers)
            : RemoteResponse::make($status, $body, $headers);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function visibleRows(string $resource, ?string $trashed): array
    {
        $rows = self::$rows[self::bucket($resource)] ?? [];

        if ($trashed === 'with') {
            return array_values($rows);
        }

        $visible = [];

        foreach ($rows as $row) {
            $deleted = ($row['deleted_at'] ?? null) !== null;

            if ($trashed === 'only' ? $deleted : ! $deleted) {
                $visible[] = $row;
            }
        }

        return $visible;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<int, mixed>  $wheres
     * @return list<array<string, mixed>>
     */
    private static function applyWheres(array $rows, array $wheres): array
    {
        if ($wheres === []) {
            return $rows;
        }

        self::assertValidWheres($wheres);

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => self::evaluateAll($wheres, $row) === true,
        ));
    }

    /**
     * @param  array<int, mixed>  $wheres
     * @throws InvalidQueryException
     */
    private static function assertValidWheres(array $wheres): void
    {
        foreach ($wheres as $where) {
            if (! is_array($where)) {
                continue;
            }

            if (isset($where['group']) && is_array($where['group'])) {
                self::assertValidWheres($where['group']);

                continue;
            }

            if (isset($where['has']) && is_array($where['has'])) {
                self::assertValidWheres(is_array($where['has']['where'] ?? null) ? $where['has']['where'] : []);

                continue;
            }

            self::assertValue(
                (string) ($where['field'] ?? ''),
                strtolower((string) ($where['op'] ?? '')),
                $where['value'] ?? null,
            );
        }
    }

    /**
     * null is not a value on this wire.
     *
     * @throws InvalidQueryException
     */
    private static function assertValue(string $field, string $operator, mixed $value): void
    {
        if ($operator === '' || $operator === 'null' || $operator === 'not_null') {
            return;
        }

        foreach (is_array($value) ? $value : [$value] as $item) {
            if ($item !== null) {
                continue;
            }

            if ($operator === 'eq' || $operator === 'ne') {
                throw InvalidQueryException::nullComparison($field, $operator, ['transport' => 'fake']);
            }

            throw InvalidQueryException::fromServer(
                'invalid_query',
                sprintf(
                    'A null value cannot be used with the "%s" operator on "%s". Ask with the null or not_null operator instead.',
                    $operator,
                    $field,
                ),
                400,
                null,
                ['transport' => 'fake', 'field' => $field, 'operator' => $operator],
            );
        }
    }

    /**
     * A row matches when the whole expression is TRUE.
     *
     * @param  array<int, mixed>  $wheres
     * @param  array<string, mixed>  $row
     */
    private static function evaluateAll(array $wheres, array $row): ?bool
    {
        $result = true;
        $first = true;

        foreach ($wheres as $where) {
            if (! is_array($where)) {
                continue;
            }

            $value = self::evaluate($where, $row);

            if (($where['not'] ?? false) === true) {
                $value = self::negate($value);
            }

            if ($first) {
                $result = $value;
                $first = false;

                continue;
            }

            $result = strtolower((string) ($where['boolean'] ?? 'and')) === 'or'
                ? self::either($result, $value)
                : self::both($result, $value);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $where
     * @param  array<string, mixed>  $row
     */
    private static function evaluate(array $where, array $row): ?bool
    {
        if (isset($where['group']) && is_array($where['group'])) {
            return self::evaluateAll($where['group'], $row);
        }

        if (isset($where['has']) && is_array($where['has'])) {
            return self::evaluateHas($where['has'], $row);
        }

        $field = (string) ($where['field'] ?? '');
        $operator = strtolower((string) ($where['op'] ?? ''));

        return self::compareOperator(
            $operator,
            $field === '' ? null : Arr::get($row, $field),
            $where['value'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $has
     * @param  array<string, mixed>  $row
     */
    private static function evaluateHas(array $has, array $row): bool
    {
        $relation = (string) ($has['relation'] ?? '');
        $conditions = is_array($has['where'] ?? null) ? $has['where'] : [];
        $related = Arr::get($row, $relation);

        if (is_array($related)) {
            $matching = self::applyWheres(
                array_values(array_filter($related, 'is_array')),
                $conditions,
            );
            $count = count($matching);
        } elseif ($conditions === [] && is_numeric(Arr::get($row, $relation . '_count'))) {
            $count = (int) Arr::get($row, $relation . '_count');
        } else {
            // Nothing was seeded for the relation, so it holds nothing.
            $count = 0;
        }

        $expected = is_numeric($has['count'] ?? null) ? (int) $has['count'] : 1;

        return match ((string) ($has['operator'] ?? '>=')) {
            '>' => $count > $expected,
            '<' => $count < $expected,
            '<=' => $count <= $expected,
            '=' => $count === $expected,
            '!=' => $count !== $expected,
            default => $count >= $expected,
        };
    }

    private static function compareOperator(string $operator, mixed $left, mixed $right): ?bool
    {
        if ($operator === 'null') {
            return $left === null;
        }

        if ($operator === 'not_null') {
            return $left !== null;
        }

        if ($operator === 'in' || $operator === 'not_in') {
            $list = is_array($right) ? array_values($right) : [];

            if ($list === []) {
                return $operator === 'not_in';
            }

            if ($left === null) {
                return null;
            }

            $found = false;

            foreach ($list as $candidate) {
                if (self::equals($left, $candidate)) {
                    $found = true;

                    break;
                }
            }

            return $operator === 'in' ? $found : ! $found;
        }

        if ($operator === 'between' || $operator === 'not_between') {
            $bounds = is_array($right) ? array_values($right) : [];

            if (count($bounds) !== 2 || $left === null) {
                return null;
            }

            $lower = self::compare($left, $bounds[0]);
            $upper = self::compare($left, $bounds[1]);

            if ($lower === null || $upper === null) {
                return null;
            }

            $inside = $lower >= 0 && $upper <= 0;

            return $operator === 'between' ? $inside : ! $inside;
        }

        if ($left === null) {
            return null;
        }

        if ($operator === 'starts_with' || $operator === 'contains' || $operator === 'ends_with') {
            if (! is_scalar($right) || ! is_scalar($left)) {
                return false;
            }

            $haystack = (string) $left;
            // Literal: a % or _ inside the value is four percent signs worth of nothing special.
            $needle = (string) $right;

            if ($needle === '') {
                return true;
            }

            return match ($operator) {
                'starts_with' => str_starts_with($haystack, $needle),
                'ends_with' => str_ends_with($haystack, $needle),
                default => str_contains($haystack, $needle),
            };
        }

        if ($right === null) {
            return null;
        }

        if ($operator === 'eq' || $operator === 'ne') {
            $equal = self::equals($left, $right);

            return $operator === 'eq' ? $equal : ! $equal;
        }

        $comparison = self::compare($left, $right);

        if ($comparison === null) {
            return null;
        }

        return match ($operator) {
            'gt' => $comparison > 0,
            'gte' => $comparison >= 0,
            'lt' => $comparison < 0,
            'lte' => $comparison <= 0,
            default => null,
        };
    }

    private static function equals(mixed $left, mixed $right): bool
    {
        if (is_bool($left) || is_bool($right)) {
            return self::asBool($left) === self::asBool($right);
        }

        if (is_numeric($left) && is_numeric($right)) {
            return self::compareNumbers($left, $right) === 0;
        }

        if (is_scalar($left) && is_scalar($right)) {
            return (string) $left === (string) $right;
        }

        return $left === $right;
    }

    private static function compare(mixed $left, mixed $right): ?int
    {
        if ($left === null || $right === null) {
            return null;
        }

        if (is_numeric($left) && is_numeric($right)) {
            return self::compareNumbers($left, $right);
        }

        if (is_bool($left) || is_bool($right)) {
            return self::asBool($left) <=> self::asBool($right);
        }

        if (! is_scalar($left) || ! is_scalar($right)) {
            return null;
        }

        // ISO-8601 sorts correctly as text, which is why every date the query layer sends is formatted that way.
        return strcmp((string) $left, (string) $right);
    }

    private static function asBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float) $value !== 0.0;
        }

        return is_string($value) && in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return array{0: bool, 1: string, 2: string}|null
     */
    private static function exactParts(mixed $value): ?array
    {
        if (is_int($value)) {
            $digits = (string) abs($value);

            return [$value < 0, $digits, ''];
        }

        if (! is_string($value) || preg_match('/^\s*([+-]?)(\d+)(?:\.(\d*))?\s*$/', $value, $matches) !== 1) {
            return null;
        }

        $integer = ltrim($matches[2], '0');
        $fraction = rtrim($matches[3] ?? '', '0');

        return [
            $matches[1] === '-' && ($integer !== '' || $fraction !== ''),
            $integer === '' ? '0' : $integer,
            $fraction,
        ];
    }

    /**
     * Two numbers compared without a float in between wherever both are written out exactly.
     */
    private static function compareNumbers(mixed $left, mixed $right): int
    {
        $a = self::exactParts($left);
        $b = self::exactParts($right);

        if ($a === null || $b === null) {
            return (float) $left <=> (float) $right;
        }

        if ($a[0] !== $b[0]) {
            return $a[0] ? -1 : 1;
        }

        $magnitude = self::compareMagnitude($a, $b);

        return $a[0] ? -$magnitude : $magnitude;
    }

    /**
     * @param  array{0: bool, 1: string, 2: string}  $a
     * @param  array{0: bool, 1: string, 2: string}  $b
     */
    private static function compareMagnitude(array $a, array $b): int
    {
        if (strlen($a[1]) !== strlen($b[1])) {
            return strlen($a[1]) <=> strlen($b[1]);
        }

        if (($integers = strcmp($a[1], $b[1])) !== 0) {
            return $integers < 0 ? -1 : 1;
        }

        $width = max(strlen($a[2]), strlen($b[2]));
        $fractions = strcmp(str_pad($a[2], $width, '0'), str_pad($b[2], $width, '0'));

        return $fractions === 0 ? 0 : ($fractions < 0 ? -1 : 1);
    }

    /**
     * @param  array{0: bool, 1: string, 2: string}  $parts
     * @return array{0: bool, 1: string}
     */
    private static function scaled(array $parts, int $scale): array
    {
        return [$parts[0], ltrim($parts[1] . str_pad(substr($parts[2], 0, $scale), $scale, '0'), '0')];
    }

    /**
     * @param  array{0: bool, 1: string}  $a
     * @param  array{0: bool, 1: string}  $b
     * @return array{0: bool, 1: string}
     */
    private static function addScaled(array $a, array $b): array
    {
        if ($a[0] === $b[0]) {
            return [$a[0], self::addDigits($a[1], $b[1])];
        }

        $order = self::compareDigits($a[1], $b[1]);

        if ($order === 0) {
            return [false, ''];
        }

        return $order > 0
            ? [$a[0], self::subtractDigits($a[1], $b[1])]
            : [$b[0], self::subtractDigits($b[1], $a[1])];
    }

    private static function addDigits(string $left, string $right): string
    {
        $width = max(strlen($left), strlen($right)) + 1;
        $left = str_pad($left, $width, '0', STR_PAD_LEFT);
        $right = str_pad($right, $width, '0', STR_PAD_LEFT);
        $carry = 0;
        $sum = '';

        for ($i = $width - 1; $i >= 0; $i--) {
            $digit = (int) $left[$i] + (int) $right[$i] + $carry;
            $carry = intdiv($digit, 10);
            $sum = ((string) ($digit % 10)) . $sum;
        }

        return ltrim($sum, '0');
    }

    /**
     * $left minus $right, where $left is known not to be the smaller of the two.
     */
    private static function subtractDigits(string $left, string $right): string
    {
        $width = max(strlen($left), strlen($right));
        $left = str_pad($left, $width, '0', STR_PAD_LEFT);
        $right = str_pad($right, $width, '0', STR_PAD_LEFT);
        $borrow = 0;
        $difference = '';

        for ($i = $width - 1; $i >= 0; $i--) {
            $digit = (int) $left[$i] - (int) $right[$i] - $borrow;
            $borrow = $digit < 0 ? 1 : 0;
            $difference = ((string) ($digit + ($borrow * 10))) . $difference;
        }

        return ltrim($difference, '0');
    }

    private static function compareDigits(string $left, string $right): int
    {
        $left = ltrim($left, '0');
        $right = ltrim($right, '0');

        if (strlen($left) !== strlen($right)) {
            return strlen($left) <=> strlen($right);
        }

        return strcmp($left, $right) <=> 0;
    }

    private static function divideDigits(string $digits, int $divisor): string
    {
        $digits = ltrim($digits, '0');

        if ($digits === '' || $divisor <= 0) {
            return '';
        }

        $quotient = '';
        $remainder = 0;

        foreach (str_split($digits) as $digit) {
            $current = $remainder * 10 + (int) $digit;
            $quotient .= (string) intdiv($current, $divisor);
            $remainder = $current % $divisor;
        }

        $quotient = ltrim($quotient, '0');

        if ($remainder * 2 >= $divisor) {
            $quotient = self::addDigits($quotient, '1');
        }

        return $quotient;
    }

    /**
     * @param  array{0: bool, 1: string}  $value
     */
    private static function unscaled(array $value, int $scale): string
    {
        $digits = ltrim($value[1], '0');

        if ($digits === '') {
            $digits = '0';
        }

        if ($scale === 0) {
            return ($value[0] && $digits !== '0' ? '-' : '') . $digits;
        }

        $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
        $whole = substr($digits, 0, -$scale);
        $fraction = substr($digits, -$scale);
        $negative = $value[0] && ltrim($whole . $fraction, '0') !== '';

        return ($negative ? '-' : '') . $whole . '.' . $fraction;
    }

    private static function both(?bool $left, ?bool $right): ?bool
    {
        if ($left === false || $right === false) {
            return false;
        }

        return $left === null || $right === null ? null : true;
    }

    private static function either(?bool $left, ?bool $right): ?bool
    {
        if ($left === true || $right === true) {
            return true;
        }

        return $left === null || $right === null ? null : false;
    }

    private static function negate(?bool $value): ?bool
    {
        return $value === null ? null : ! $value;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<int, mixed>  $order
     * @return list<array<string, mixed>>
     */
    private static function applyOrder(array $rows, array $order): array
    {
        if ($order === []) {
            return $rows;
        }

        usort($rows, static function (array $left, array $right) use ($order): int {
            foreach ($order as $clause) {
                if (! is_array($clause)) {
                    continue;
                }

                $field = (string) ($clause['field'] ?? '');

                if ($field === '') {
                    continue;
                }

                $a = Arr::get($left, $field);
                $b = Arr::get($right, $field);

                if ($a === null && $b === null) {
                    continue;
                }

                // Nulls last, ascending or descending. See the class docblock.
                if ($a === null) {
                    return 1;
                }

                if ($b === null) {
                    return -1;
                }

                $comparison = self::compare($a, $b) ?? 0;

                if ($comparison !== 0) {
                    return strtolower((string) ($clause['direction'] ?? 'asc')) === 'desc'
                        ? -$comparison
                        : $comparison;
                }
            }

            return 0;
        });

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $fields
     * @return list<array<string, mixed>>
     */
    private static function distinct(array $rows, array $fields): array
    {
        $seen = [];
        $unique = [];

        foreach ($rows as $row) {
            $signature = json_encode($fields === [] ? $row : self::project($row, $fields, [], []));

            if (isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;
            $unique[] = $row;
        }

        return $unique;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $fields
     * @param  list<string>  $counts
     * @param  list<string>  $include
     * @return array<string, mixed>
     */
    private static function project(array $row, array $fields, array $counts, array $include): array
    {
        $projected = $row;

        if ($fields !== []) {
            $projected = [];

            foreach ($fields as $field) {
                if (array_key_exists($field, $row)) {
                    $projected[$field] = $row[$field];
                }
            }
        }

        foreach ($include as $relation) {
            if (array_key_exists($relation, $row)) {
                $projected[$relation] = $row[$relation];
            }
        }

        foreach ($counts as $relation) {
            $related = $row[$relation] ?? null;

            if (is_array($related)) {
                $projected[$relation . '_count'] = count($related);

                continue;
            }

            if (is_numeric($row[$relation . '_count'] ?? null)) {
                $projected[$relation . '_count'] = (int) $row[$relation . '_count'];

                continue;
            }

            $projected[$relation . '_count'] = 0;
        }

        return $projected;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private static function computeAggregate(
        string $resource,
        string $function,
        ?string $field,
        array $rows,
    ): int|float|string|null {
        if ($function === 'count') {
            if ($field === null) {
                return count($rows);
            }

            $counted = 0;

            foreach ($rows as $row) {
                if (Arr::get($row, $field) !== null) {
                    $counted++;
                }
            }

            return $counted;
        }

        $values = [];

        foreach ($rows as $row) {
            $value = $field === null ? null : Arr::get($row, $field);

            if ($value === null) {
                continue;
            }

            // Kept as it was seeded.
            if (is_numeric($value)) {
                $values[] = $value;
            } elseif (is_scalar($value) && ($function === 'min' || $function === 'max')) {
                $values[] = $value;
            }
        }

        if ($values === []) {
            return null;
        }

        if ($function === 'min' || $function === 'max') {
            return self::extreme($values, $function === 'min');
        }

        if ($function !== 'sum' && $function !== 'avg') {
            return null;
        }

        return self::accumulate($resource, $field, $values, $function === 'avg');
    }

    /**
     * Sum, and the average that divides it.
     *
     * @param  list<int|float|string>  $values
     */
    private static function accumulate(string $resource, ?string $field, array $values, bool $average): int|float|string
    {
        $scale = self::scaleOf($resource, $field, $values);

        if ($scale === null) {
            $sum = array_sum(array_map(
                static fn (int|float|string $value): int|float => $value + 0,
                $values,
            ));

            return $average ? $sum / count($values) : $sum;
        }

        $total = [false, ''];

        foreach ($values as $value) {
            $parts = self::exactParts($value);

            if ($parts !== null) {
                $total = self::addScaled($total, self::scaled($parts, $scale));
            }
        }

        if ($average) {
            $total = [$total[0], self::divideDigits($total[1], count($values))];
        }

        return self::unscaled($total, $scale);
    }

    /**
     * @param  list<int|float|string>  $values
     */
    private static function scaleOf(string $resource, ?string $field, array $values): ?int
    {
        $declared = $field === null ? null : (self::$schemas[$resource]['fields'][$field] ?? null);
        $declared = is_array($declared) ? $declared : [];
        $type = (string) ($declared['type'] ?? '');

        if ($type !== 'bigint' && $type !== 'decimal') {
            foreach ($values as $value) {
                if (! is_string($value)) {
                    return null;
                }
            }
        }

        $widest = 0;

        foreach ($values as $value) {
            $parts = self::exactParts($value);

            if ($parts === null) {
                return null;
            }

            $widest = max($widest, strlen($parts[2]));
        }

        if ($type === 'decimal' && is_numeric($declared['scale'] ?? null)) {
            return max(0, (int) $declared['scale']);
        }

        return $type === 'bigint' ? 0 : $widest;
    }

    /**
     * @param  list<int|float|string>  $values
     */
    private static function extreme(array $values, bool $lowest): int|float|string|null
    {
        $best = null;

        foreach ($values as $value) {
            if ($best === null) {
                $best = $value;

                continue;
            }

            $comparison = self::compare($value, $best) ?? 0;

            if ($lowest ? $comparison < 0 : $comparison > 0) {
                $best = $value;
            }
        }

        return is_scalar($best) && ! is_bool($best) ? $best : null;
    }

    private static function bucket(string $resource): string
    {
        if (isset(self::$rows[$resource])) {
            return $resource;
        }

        return isset(self::$rows[self::ANY]) ? self::ANY : $resource;
    }

    private static function keyName(string $resource): string
    {
        return self::$keys[$resource] ?? self::$keys[self::ANY] ?? 'id';
    }

    private static function locate(string $resource, string|int $id, ?string $trashed): ?int
    {
        $bucket = self::bucket($resource);
        $key = self::keyName($resource);

        foreach (self::$rows[$bucket] ?? [] as $index => $row) {
            if (! self::equals($row[$key] ?? null, $id)) {
                continue;
            }

            $deleted = ($row['deleted_at'] ?? null) !== null;

            if ($trashed === null && $deleted) {
                return null;
            }

            if ($trashed === 'only' && ! $deleted) {
                return null;
            }

            return (int) $index;
        }

        return null;
    }

    private static function nextId(string $bucket, string $key): int|string
    {
        $highest = 0;

        foreach (self::$rows[$bucket] ?? [] as $row) {
            $value = $row[$key] ?? null;

            if (is_numeric($value)) {
                $highest = max($highest, (int) $value);
            }
        }

        return $highest + 1;
    }

    private static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    /**
     * @return array<string, mixed>
     */
    private static function schemaPayload(string $resource): array
    {
        if (isset(self::$schemas[$resource])) {
            return self::$schemas[$resource];
        }

        $rows = self::$rows[self::bucket($resource)] ?? [];
        $fields = [];
        $softDeletes = false;

        foreach ($rows as $row) {
            foreach ($row as $name => $value) {
                $name = (string) $name;

                if ($name === 'deleted_at') {
                    $softDeletes = true;
                }

                $type = self::inferType($value);
                $known = $fields[$name] ?? null;

                $fields[$name] = [
                    'type' => $type === 'mixed' && $known !== null ? $known['type'] : $type,
                    'nullable' => ($known['nullable'] ?? false) || $value === null,
                ];
            }
        }

        return [
            'resource' => $resource,
            'version' => 1,
            'key' => ['name' => self::keyName($resource), 'type' => 'int'],
            'soft_deletes' => $softDeletes,
            'default_fields' => array_keys($fields),
            'fields' => $fields,
            'includes' => [],
            'relations' => [],
            'actions' => [],
            'limits' => ['max_limit' => 100],
        ];
    }

    private static function versionTag(string $resource): string
    {
        $payload = self::$schemas[$resource] ?? null;
        $version = $payload['version'] ?? 1;

        if (is_string($version) && str_contains($version, '@')) {
            return $version;
        }

        return $resource . '@' . (is_numeric($version) ? (int) $version : 1);
    }

    private static function inferType(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_array($value) => 'array',
            is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}:\d{2})/', $value) === 1 => 'datetime',
            is_string($value) => 'string',
            default => 'mixed',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function defaultAccess(): array
    {
        return [
            'application' => 'fake',
            'permissions_version' => '1',
            'resources' => [],
            'quota' => ['limit' => null, 'used' => 0, 'remaining' => null],
        ];
    }

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    private static function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $list = [];

        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                $list[] = trim($value);
            }
        }

        return array_values(array_unique($list));
    }

    /**
     * @param  (callable(array<string, mixed>, array<string, mixed>): bool)|null  $matcher
     */
    private static function assertCalled(string $method, string $resource, ?callable $matcher, ?string $payloadKey): void
    {
        $calls = self::callsTo($method, $resource);

        PHPUnit::assertNotEmpty($calls, sprintf(
            "Expected a %s call on \"%s\", none was made.\nCalls: %s",
            $method,
            $resource,
            self::describeCalls(),
        ));

        if ($matcher === null) {
            return;
        }

        foreach ($calls as $call) {
            $payload = $payloadKey === null ? $call : ($call[$payloadKey] ?? []);

            if ($matcher(is_array($payload) ? $payload : [], $call) === true) {
                PHPUnit::assertTrue(true);

                return;
            }
        }

        PHPUnit::fail(sprintf(
            "%d %s call(s) reached \"%s\", none matched the expectation:\n%s",
            count($calls),
            $method,
            $resource,
            json_encode($calls, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ));
    }

    private static function describeCalls(): string
    {
        if (self::$calls === []) {
            return '  (none)';
        }

        $lines = [];

        foreach (self::$calls as $index => $call) {
            $lines[] = sprintf(
                '  %d. %s %s%s',
                $index + 1,
                $call['method'],
                $call['resource'],
                isset($call['id']) ? '/' . $call['id'] : '',
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<array<string, mixed>>  $calls
     */
    private static function describeTargets(array $calls): string
    {
        $targets = [];

        foreach ($calls as $call) {
            $targets[] = $call['resource'] . '/' . ($call['id'] ?? '?');
        }

        return $targets === [] ? '(none)' : implode(', ', $targets);
    }
}
