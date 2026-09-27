<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Models;

use Esanj\RemoteEloquent\Cache\IdentityMap;
use Esanj\RemoteEloquent\Access\RemoteAccess;
use Esanj\RemoteEloquent\Contracts\ResourceTransport;
use Esanj\RemoteEloquent\Database\ApiConnection;
use Esanj\RemoteEloquent\Exceptions\UnboundedQueryException;
use Esanj\RemoteEloquent\Exceptions\UnsupportedQueryException;
use Esanj\RemoteEloquent\Exceptions\UnsupportedResourceException;
use Esanj\RemoteEloquent\Idempotency\OperationContext;
use Esanj\RemoteEloquent\Query\ApiEloquentBuilder;
use Esanj\RemoteEloquent\Query\ApiQueryBuilder;
use Esanj\RemoteEloquent\Schema\SchemaValidator;
use Esanj\RemoteEloquent\Transport\RemoteResponse;
use Esanj\RemoteEloquent\Client\ResourceClient;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

abstract class ApiModel extends Model
{
    public const REMOTE_VERSION = 'version';

    private const FAKE_TRANSPORT = 'Esanj\\RemoteEloquent\\Testing\\FakeResourceTransport';

    protected string $resource = '';

    /**
     * Read by Rule::exists()/unique() to route the check to the remote resource instead of a local table.
     */
    protected $connection = 'remote-eloquent';

    public $timestamps = false;

    private static bool $resolvingActor = false;

    private static ?ApiConnection $sharedConnection = null;

    private static ?OperationContext $sharedOperations = null;

    private static ?IdentityMap $sharedIdentityMap = null;

    private static ?Authenticatable $pinnedActor = null;

    private static bool $actorIsPinned = false;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $casts = [];

        foreach ([static::CREATED_AT, static::UPDATED_AT] as $column) {
            if (is_string($column) && $column !== '' && ! $this->hasCast($column)) {
                $casts[$column] = 'datetime';
            }
        }

        if ($casts !== []) {
            $this->mergeCasts($casts);
        }
    }

    public function resource(): string
    {
        $resource = trim($this->resource);

        if ($resource === '') {
            throw UnsupportedResourceException::notConfigured(static::class);
        }

        return $resource;
    }

    public function getTable(): string
    {
        return is_string($this->table) && $this->table !== '' ? $this->table : $this->resource();
    }

    public function getConnection(): ApiConnection
    {
        return self::$sharedConnection ??= new ApiConnection('remote-eloquent');
    }

    public function newEloquentBuilder($query): ApiEloquentBuilder
    {
        return new ApiEloquentBuilder($query);
    }

    protected function newBaseQueryBuilder(): ApiQueryBuilder
    {
        $transport = static::remoteTransport();
        $actor = $this->remoteActor();

        return new ApiQueryBuilder(
            $this->getConnection(),
            $transport,
            $this->resource(),
            $this->getTable(),
            (string) $this->getKeyName(),
            static::class,
            (int) static::remoteConfig('limits.max_query_limit', 100),
            (int) static::remoteConfig('limits.in_chunk', 500),
            static::remoteSchemaValidator(),
            static::remoteIdentityMap(),
            static::remoteApplication($transport),
            $actor === null ? null : (string) $actor->getAuthIdentifier(),
        );
    }

    public static function all($columns = ['*']): never
    {
        throw UnboundedQueryException::all(
            static::class,
            (int) static::remoteConfig('limits.max_query_limit', 100),
        );
    }

    protected function performInsert(EloquentBuilder $query): bool
    {
        if ($this->usesUniqueIds()) {
            $this->setUniqueIds();
        }

        if ($this->fireModelEvent('creating') === false) {
            return false;
        }

        $resource = $this->resource();

        $payload = ['attributes' => $this->remoteWritableAttributes($this->getAttributesForInsert())];

        if (($fields = $this->remoteFields()) !== []) {
            $payload['fields'] = $fields;
        }

        $response = $this->remoteWrite('create', $resource, null, fn (string $key): RemoteResponse => static::remoteTransport()
            ->withActor($this->remoteActor())
            ->create($resource, $payload, $key));

        $this->exists = true;
        $this->wasRecentlyCreated = true;

        $this->fillFromRemote($response);

        $this->fireModelEvent('created', false);

        return true;
    }

    protected function performUpdate(EloquentBuilder $query): bool
    {
        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        $dirty = $this->remoteWritableAttributes($this->getDirtyForUpdate());

        if ($dirty === []) {
            return true;
        }

        $resource = $this->resource();
        $id = $this->remoteKey();

        $payload = ['attributes' => $dirty];

        if (($ifMatch = $this->remoteIfMatch()) !== []) {
            $payload['if_match'] = $ifMatch;
        }

        if (($fields = $this->remoteFields()) !== []) {
            $payload['fields'] = $fields;
        }

        $response = $this->remoteWrite('update', $resource, $id, fn (string $key): RemoteResponse => static::remoteTransport()
            ->withActor($this->remoteActor())
            ->update($resource, $id, $payload, $key));

        $this->syncChanges();

        $this->fillFromRemote($response);

        $this->fireModelEvent('updated', false);

        return true;
    }

    protected function performDeleteOnModel(): void
    {
        $this->remoteDelete($this->remoteForceDelete());

        $this->exists = false;
    }

    protected function remoteDelete(bool $force): void
    {
        $resource = $this->resource();
        $id = $this->remoteKey();
        $operation = $force ? 'force_delete' : 'delete';

        $this->remoteWrite($operation, $resource, $id, fn (string $key): RemoteResponse => static::remoteTransport()
            ->withActor($this->remoteActor())
            ->delete($resource, $id, $force, $key));

        static::remoteIdentityMap()?->forget($resource, $id);
    }

    private bool $remoteForceDeleting = false;

    /**
     * DELETE ?force=1 even on a model without RemoteSoftDeletes, so an erasure is never a silent soft delete.
     */
    public function forceDelete()
    {
        $this->remoteForceDeleting = true;

        try {
            return $this->delete();
        } finally {
            $this->remoteForceDeleting = false;
        }
    }

    protected function remoteForceDelete(): bool
    {
        return $this->remoteForceDeleting;
    }

    public function remoteAction(string $action, array $payload = []): mixed
    {
        $action = trim($action);
        $resource = $this->resource();

        if ($action === '') {
            throw UnsupportedResourceException::unknownAction($resource, $action, null, ['model' => static::class]);
        }

        if (! $this->exists) {
            throw UnsupportedQueryException::method(
                sprintf('%s::remoteAction(\'%s\')', static::class, $action),
                'An action runs against a record that exists. Save the model first, or call the action on one you loaded.',
                ['resource' => $resource, 'action' => $action],
            );
        }

        $id = $this->remoteKey();

        $response = $this->remoteWrite('action:' . $action, $resource, $id, fn (string $key): RemoteResponse => static::remoteTransport()
            ->withActor($this->remoteActor())
            ->action($resource, $id, $action, $payload, $key));

        if ($response->record() !== null) {
            $this->fillFromRemote($response);
        } else {
            static::remoteIdentityMap()?->forget($resource, $id);
        }

        return $response->data();
    }

    public function validateRemote(array $attributes = []): bool
    {
        $attributes = $attributes !== []
            ? $attributes
            : ($this->exists ? $this->getDirtyForUpdate() : $this->getAttributesForInsert());

        return $this->sendValidate(
            $this->exists ? 'update' : 'create',
            $attributes,
            $this->exists ? $this->remoteKey() : null,
        );
    }

    public static function validateRemoteInto(array $attributes): bool
    {
        return (new static)->sendValidate('create', $attributes, null);
    }

    public function remoteCan(string $operation): bool
    {
        return RemoteAccess::allows($this->resource(), $operation);
    }

    public static function remoteAccess(bool $fresh = false): ?array
    {
        if ($fresh) {
            RemoteAccess::refresh();
        }

        $snapshot = RemoteAccess::snapshot();

        return $snapshot->isKnown() ? $snapshot->toArray() : null;
    }

    public static function fake(array $rows = []): ResourceTransport
    {
        return static::swapTransport(static::newFakeTransport($rows, 0));
    }

    public static function fakeFailure(int $status): ResourceTransport
    {
        return static::swapTransport(static::newFakeTransport([], $status));
    }

    public static function stopFaking(): void
    {
        Container::getInstance()->forgetInstance(ResourceTransport::class);

        self::$sharedIdentityMap?->flush();
    }

    public static function actingAsRemote(?Authenticatable $actor): void
    {
        self::$pinnedActor = $actor;
        self::$actorIsPinned = true;
    }

    public static function forgetRemoteActor(): void
    {
        self::$pinnedActor = null;
        self::$actorIsPinned = false;
    }

    protected function remoteActor(): ?Authenticatable
    {
        if (self::$actorIsPinned) {
            return self::$pinnedActor;
        }

        // A guard whose provider queries this model would ask for the actor again while resolving it.
        if (self::$resolvingActor) {
            return null;
        }

        $auth = static::remoteResolve('auth');

        if (! $auth instanceof AuthFactory) {
            return null;
        }

        self::$resolvingActor = true;

        try {
            $user = $auth->guard()->user();
        } catch (Throwable) {
            return null;
        } finally {
            self::$resolvingActor = false;
        }

        return $user instanceof Authenticatable ? $user : null;
    }

    public static function remoteTransport(): ResourceTransport
    {
        $transport = static::remoteResolve(ResourceTransport::class);

        if ($transport instanceof ResourceTransport) {
            return $transport;
        }

        throw UnsupportedQueryException::method(
            sprintf('%s has no transport', static::class),
            'Nothing is bound to Esanj\\RemoteEloquent\\Contracts\\ResourceTransport. Register the package service provider, or bind a transport yourself — in a test, ' . static::class . '::fake() does it.',
            ['model' => static::class],
        );
    }

    public static function remoteIdentityMap(): ?IdentityMap
    {
        $map = static::remoteResolve(IdentityMap::class);

        if ($map instanceof IdentityMap) {
            return $map;
        }

        return self::$sharedIdentityMap ??= new IdentityMap(
            (bool) static::remoteConfig('cache.identity_map', true),
        );
    }

    public static function remoteOperations(): OperationContext
    {
        $operations = static::remoteResolve(OperationContext::class);

        if ($operations instanceof OperationContext) {
            return $operations;
        }

        return self::$sharedOperations ??= new OperationContext();
    }

    public static function remoteSchemaValidator(): ?SchemaValidator
    {
        $validator = static::remoteResolve(SchemaValidator::class);

        return $validator instanceof SchemaValidator ? $validator : null;
    }

    public static function remoteConfig(string $key, mixed $default = null): mixed
    {
        $config = static::remoteResolve('config');

        if (! $config instanceof ConfigRepository) {
            return $default;
        }

        return $config->get('esanj.remote_eloquent.' . $key, $default);
    }

    protected function remoteFields(): array
    {
        return [];
    }

    protected function fillFromRemote(RemoteResponse $response): void
    {
        $map = static::remoteIdentityMap();

        $map?->observe($response->schemaVersion(), $response->permissionsVersion());

        $record = $response->record();

        if ($record !== null) {
            $this->setRawAttributes(array_merge($this->getAttributes(), $record), true);
        }

        $key = $this->getKey();

        if ($key !== null) {
            $map?->forget($this->resource(), $this->scalarId($key));
        }
    }

    protected function remoteWritableAttributes(array $attributes): array
    {
        foreach ($this->remoteServerOwnedFields() as $field) {
            unset($attributes[$field]);
        }

        return $attributes;
    }

    protected function remoteServerOwnedFields(): array
    {
        $fields = ['created_at', 'updated_at', 'deleted_at'];

        foreach ([static::CREATED_AT, static::UPDATED_AT] as $column) {
            if (is_string($column) && $column !== '') {
                $fields[] = $column;
            }
        }

        if (defined(static::class . '::DELETED_AT')) {
            $deletedAt = constant(static::class . '::DELETED_AT');

            if (is_string($deletedAt) && $deletedAt !== '') {
                $fields[] = $deletedAt;
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * The optimistic lock the server checks: {field: value as loaded}. Only a field the record was loaded with.
     *
     * @return array<string, scalar>
     */
    protected function remoteIfMatch(): array
    {
        foreach ([static::REMOTE_VERSION, static::UPDATED_AT] as $field) {
            if (! is_string($field) || $field === '') {
                continue;
            }

            $value = $this->original[$field] ?? null;

            if ($value instanceof \DateTimeInterface) {
                $value = \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
            }

            if (is_string($value) || is_int($value)) {
                return [$field => $value];
            }
        }

        return [];
    }

    /**
     * One idempotent write: the key survives a timeout or a 5xx for the retry, and is released after an answer the same key would only repeat.
     *
     * @param  \Closure(string): RemoteResponse  $send
     */
    protected function remoteWrite(string $operation, string $resource, string|int|null $id, \Closure $send): RemoteResponse
    {
        $operations = static::remoteOperations();
        $key = $operations->keyFor($operation, $resource, $id);

        try {
            $response = $send($key);
        } catch (Throwable $exception) {
            if (ResourceClient::isDefinitive($exception)) {
                $operations->forget($operation, $resource, $id);
            }

            throw $exception;
        }

        $operations->forget($operation, $resource, $id);

        return $response;
    }

    protected function sendValidate(string $mode, array $attributes, string|int|null $id): bool
    {
        $payload = [
            'mode' => $mode,
            'attributes' => $this->remoteWritableAttributes($attributes),
        ];

        if ($id !== null) {
            $payload['id'] = $id;
        }

        static::remoteTransport()
            ->withActor($this->remoteActor())
            ->validate($this->resource(), $payload);

        return true;
    }

    protected function remoteKey(): string|int
    {
        $id = $this->getKeyForSaveQuery();

        if ($id === null || $id === '') {
            throw UnsupportedQueryException::method(
                sprintf('Writing a "%s" record that has no %s', $this->resource(), $this->getKeyName()),
                'Load the record, or set its key, before saving, deleting, restoring or running an action on it.',
                ['resource' => $this->resource(), 'model' => static::class],
            );
        }

        return $this->scalarId($id);
    }

    private function scalarId(mixed $id): string|int
    {
        return is_int($id) ? $id : (string) $id;
    }

    private static function swapTransport(ResourceTransport $transport): ResourceTransport
    {
        Container::getInstance()->instance(ResourceTransport::class, $transport);

        self::$sharedIdentityMap?->flush();

        return $transport;
    }

    private static function newFakeTransport(array $rows, int $status): ResourceTransport
    {
        $class = self::FAKE_TRANSPORT;

        if (! class_exists($class)) {
            throw UnsupportedQueryException::method(
                sprintf('%s::fake()', static::class),
                sprintf('%s is not installed. It ships with the package\'s testing helpers; require the package with dev dependencies, or bind your own ResourceTransport double into the container.', $class),
                ['builder_method' => 'fake'],
            );
        }

        /** @var ResourceTransport $fake */
        $fake = new $class($rows, $status);

        return $fake;
    }

    private static function remoteApplication(ResourceTransport $transport): string
    {
        $clientId = static::remoteConfig('auth.client_id');

        return is_scalar($clientId) && (string) $clientId !== ''
            ? (string) $clientId
            : $transport->name();
    }

    private static function remoteResolve(string $abstract): ?object
    {
        try {
            $container = Container::getInstance();

            return $container->bound($abstract) ? $container->make($abstract) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
