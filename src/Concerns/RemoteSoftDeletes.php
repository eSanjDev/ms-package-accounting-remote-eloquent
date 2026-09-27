<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Concerns;

use Esanj\RemoteEloquent\Exceptions\UnsupportedQueryException;
use Esanj\RemoteEloquent\Query\ApiEloquentBuilder;
use Esanj\RemoteEloquent\Query\ApiQueryBuilder;
use Esanj\RemoteEloquent\Query\QuerySpec;
use Esanj\RemoteEloquent\Transport\RemoteResponse;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection as BaseCollection;

/**
 * Soft deletes, expressed in the API's vocabulary instead of SQL's.
 *
 * @method static ApiEloquentBuilder withTrashed(bool $withTrashed = true)
 * @method static ApiEloquentBuilder onlyTrashed()
 * @method static ApiEloquentBuilder withoutTrashed()
 */
trait RemoteSoftDeletes
{
    /**
     * Set for the length of one forceDelete().
     */
    protected bool $forceDeleting = false;

    /**
     * The attribute the resource reports a deletion time in, when it reports one.
     */
    public function getRemoteDeletedAtColumn(): string
    {
        return defined(static::class . '::DELETED_AT') ? (string) constant(static::class . '::DELETED_AT') : 'deleted_at';
    }

    public function initializeRemoteSoftDeletes(): void
    {
        $column = $this->getRemoteDeletedAtColumn();

        if (! $this->hasCast($column)) {
            $this->mergeCasts([$column => 'datetime']);
        }
    }

    /**
     * @param  EloquentBuilder<static>  $query
     */
    public function scopeWithTrashed(EloquentBuilder $query, bool $withTrashed = true): void
    {
        $this->remoteQueryOf($query, 'withTrashed')
            ->trashed($withTrashed ? QuerySpec::TRASHED_WITH : null);
    }

    /**
     * @param  EloquentBuilder<static>  $query
     */
    public function scopeOnlyTrashed(EloquentBuilder $query): void
    {
        $this->remoteQueryOf($query, 'onlyTrashed')->trashed(QuerySpec::TRASHED_ONLY);
    }

    /**
     * @param  EloquentBuilder<static>  $query
     */
    public function scopeWithoutTrashed(EloquentBuilder $query): void
    {
        $this->remoteQueryOf($query, 'withoutTrashed')->trashed(null);
    }

    /**
     * fresh(), refresh() and queued-model restoration of a deleted record have to ask for it with trashed.
     *
     * @param  EloquentBuilder<static>  $query
     * @return EloquentBuilder<static>
     */
    protected function setKeysForSelectQuery($query)
    {
        $query = parent::setKeysForSelectQuery($query);

        if ($this->trashed()) {
            $this->remoteQueryOf($query, 'fresh')->trashed(QuerySpec::TRASHED_WITH);
        }

        return $query;
    }

    /**
     * DELETE {resource}/{id}?force=1 — gone, not hidden.
     */
    public function forceDelete(): ?bool
    {
        if ($this->fireModelEvent('forceDeleting') === false) {
            return false;
        }

        $this->forceDeleting = true;

        try {
            $deleted = $this->delete();
        } finally {
            $this->forceDeleting = false;
        }

        if ($deleted) {
            $this->fireModelEvent('forceDeleted', false);
        }

        return $deleted;
    }

    public function forceDeleteQuietly(): ?bool
    {
        return static::withoutEvents(fn (): ?bool => $this->forceDelete());
    }

    public function isForceDeleting(): bool
    {
        return $this->forceDeleting;
    }

    /**
     * @param  BaseCollection<int, mixed>|array<int, mixed>|int|string  $ids
     */
    public static function forceDestroy($ids): int
    {
        if ($ids instanceof EloquentCollection) {
            $ids = $ids->modelKeys();
        } elseif ($ids instanceof BaseCollection) {
            $ids = $ids->all();
        }

        $ids = is_array($ids) ? $ids : func_get_args();

        if ($ids === []) {
            return 0;
        }

        $instance = new static;
        $destroyed = 0;

        // Bounded by the primary key, so this is one request per chunk of ids rather than an unbounded get().
        foreach ($instance->newQuery()->withTrashed()->whereKey($ids)->get() as $model) {
            if ($model->forceDelete()) {
                $destroyed++;
            }
        }

        return $destroyed;
    }

    /**
     * POST {resource}/{id}/restore.
     */
    public function restore(): bool
    {
        if ($this->fireModelEvent('restoring') === false) {
            return false;
        }

        $resource = $this->resource();
        $id = $this->remoteKey();

        $payload = [];

        if (($fields = $this->remoteFields()) !== []) {
            $payload['fields'] = $fields;
        }

        $response = $this->remoteWrite('restore', $resource, $id, fn (string $key): RemoteResponse => static::remoteTransport()
            ->withActor($this->remoteActor(), $this->remoteSubjectToken())
            ->restore($resource, $id, $payload, $key));

        $this->exists = true;

        $this->fillFromRemote($response);

        if ($response->record() === null) {
            $column = $this->getRemoteDeletedAtColumn();

            $this->setAttribute($column, null);
            $this->syncOriginalAttribute($column);
        }

        $this->fireModelEvent('restored', false);

        return true;
    }

    public function restoreQuietly(): bool
    {
        return static::withoutEvents(fn (): bool => $this->restore());
    }

    /**
     * Whether this record is soft-deleted, read from the field the response carried.
     */
    public function trashed(): bool
    {
        $column = $this->getRemoteDeletedAtColumn();

        return array_key_exists($column, $this->attributes) && $this->attributes[$column] !== null;
    }

    /**
     * Soft delete unless forceDelete() is what got us here.
     */
    protected function performDeleteOnModel(): void
    {
        $forcing = $this->forceDeleting;

        $this->remoteDelete($forcing);

        if ($forcing) {
            $this->exists = false;

            return;
        }

        // The row is still there, hidden.
        $column = $this->getRemoteDeletedAtColumn();

        $this->setAttribute($column, $this->freshTimestamp());
        $this->syncOriginalAttribute($column);

        $this->fireModelEvent('trashed', false);
    }

    protected function remoteForceDelete(): bool
    {
        return $this->forceDeleting;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $values
     */
    public static function restoreOrCreate(array $attributes = [], array $values = []): never
    {
        throw UnsupportedQueryException::atomicUpsert('restoreOrCreate', ['model' => static::class]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $values
     */
    public static function createOrRestore(array $attributes = [], array $values = []): never
    {
        throw UnsupportedQueryException::atomicUpsert('createOrRestore', ['model' => static::class]);
    }

    /**
     * @param  \Illuminate\Events\QueuedClosure|callable|class-string  $callback
     */
    public static function softDeleted($callback): void
    {
        static::registerModelEvent('trashed', $callback);
    }

    /**
     * @param  \Illuminate\Events\QueuedClosure|callable|class-string  $callback
     */
    public static function restoring($callback): void
    {
        static::registerModelEvent('restoring', $callback);
    }

    /**
     * @param  \Illuminate\Events\QueuedClosure|callable|class-string  $callback
     */
    public static function restored($callback): void
    {
        static::registerModelEvent('restored', $callback);
    }

    /**
     * @param  \Illuminate\Events\QueuedClosure|callable|class-string  $callback
     */
    public static function forceDeleting($callback): void
    {
        static::registerModelEvent('forceDeleting', $callback);
    }

    /**
     * @param  \Illuminate\Events\QueuedClosure|callable|class-string  $callback
     */
    public static function forceDeleted($callback): void
    {
        static::registerModelEvent('forceDeleted', $callback);
    }

    /**
     * @param  EloquentBuilder<static>  $query
     */
    private function remoteQueryOf(EloquentBuilder $query, string $method): ApiQueryBuilder
    {
        $base = $query->getQuery();

        if (! $base instanceof ApiQueryBuilder) {
            throw UnsupportedQueryException::method(
                sprintf('%s() on %s', $method, static::class),
                'RemoteSoftDeletes only works on a model that queries a remote resource. Use Illuminate\\Database\\Eloquent\\SoftDeletes for a local table.',
                ['model' => static::class, 'builder_method' => $method],
            );
        }

        return $base;
    }
}
