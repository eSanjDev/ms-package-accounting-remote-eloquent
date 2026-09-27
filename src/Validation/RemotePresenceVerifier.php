<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Validation;

use BackedEnum;
use Closure;
use Esanj\RemoteEloquent\Contracts\ResourceTransport;
use Esanj\RemoteEloquent\Database\ApiConnection;
use Esanj\RemoteEloquent\Exceptions\UnsupportedQueryException;
use Esanj\RemoteEloquent\Exceptions\UnsupportedValidationRuleException;
use Esanj\RemoteEloquent\Models\ApiModel;
use Esanj\RemoteEloquent\Query\ApiQueryBuilder;
use Esanj\RemoteEloquent\Query\QuerySpec;
use Esanj\RemoteEloquent\Schema\SchemaRepository;
use Illuminate\Validation\DatabasePresenceVerifierInterface;
use Illuminate\Validation\PresenceVerifierInterface;
use Stringable;

/**
 * exists / unique against a resource instead of a table.
 */
final class RemotePresenceVerifier implements DatabasePresenceVerifierInterface
{
    /**
     * The connection name the rule under validation named, if any.
     */
    private ?string $connection = null;

    private ?ApiConnection $api = null;

    /**
     * @param  ResourceTransport|Closure  $transport  the transport every probe sends on, or a resolver for it
     * @param  PresenceVerifierInterface  $local  Laravel's own verifier, for every local table
     * @param  list<string>  $connections  connection names that mean "this is a remote resource"
     * @param  int  $maxLimit  config('esanj.remote_eloquent.limits.max_query_limit')
     * @param  int  $inChunk  config('esanj.remote_eloquent.limits.in_chunk')
     * @param  string  $deletedAtColumn  the column withoutTrashed() nulls, translated into a trashed mode
     */
    public function __construct(
        private readonly ResourceTransport|Closure $transport,
        private readonly PresenceVerifierInterface $local,
        private readonly array $connections = ['remote-eloquent'],
        private readonly int $maxLimit = 100,
        private readonly int $inChunk = 500,
        private readonly string $deletedAtColumn = 'deleted_at',
        private readonly ?Closure $schemas = null,
    ) {
    }

    /**
     * @param  string|null  $connection
     */
    public function setConnection($connection): void
    {
        $this->connection = is_string($connection) && trim($connection) !== '' ? trim($connection) : null;

        if ($this->local instanceof DatabasePresenceVerifierInterface) {
            $this->local->setConnection($connection);
        }
    }

    /**
     * How many records match — one aggregate call.
     *
     * @param  string  $collection
     * @param  string  $column
     * @param  mixed  $value
     * @param  int|string|null  $excludeId
     * @param  string|null  $idColumn
     * @param  array<array-key, mixed>  $extra
     */
    public function getCount($collection, $column, $value, $excludeId = null, $idColumn = null, array $extra = []): int
    {
        if (! $this->isRemote($collection)) {
            return (int) $this->local->getCount($collection, $column, $value, $excludeId, $idColumn, $extra);
        }

        $collection = (string) $collection;
        $probe = $this->probe($collection);

        $probe->where((string) $column, '=', $value);

        // 'NULL' is how the rule spells "ignore nothing"; it is not an id.
        if ($excludeId !== null && $excludeId !== 'NULL') {
            $probe->where((string) ($idColumn ?: 'id'), '!=', $excludeId);
        }

        $this->applyConditions($probe, $extra, $collection);

        return $probe->count();
    }

    /**
     * How many DISTINCT values among these exist.
     *
     * @param  string  $collection
     * @param  string  $column
     * @param  array<array-key, mixed>  $values
     * @param  array<array-key, mixed>  $extra
     */
    public function getMultiCount($collection, $column, array $values, array $extra = []): int
    {
        if (! $this->isRemote($collection)) {
            return (int) $this->local->getMultiCount($collection, $column, $values, $extra);
        }

        $collection = (string) $collection;
        $column = (string) $column;
        $field = $this->unqualify($column);
        $wanted = $this->distinctValues($values);

        if ($wanted === []) {
            return 0;
        }

        $matched = 0;

        foreach (array_chunk($wanted, $this->pageSize()) as $chunk) {
            $matched += $this->countPresent($collection, $column, $field, $chunk, $extra);
        }

        return $matched;
    }

    private function countPresent(string $collection, string $column, string $field, array $values, array $extra): int
    {
        $probe = $this->probe($collection);
        $probe->whereIn($column, $values);
        $this->applyConditions($probe, $extra, $collection);

        if ($field === $probe->keyName()) {
            return $probe->count();
        }

        if ($this->isDistinctable($collection, $field)) {
            return $probe->distinct()->count($column);
        }

        $present = 0;

        foreach ($values as $value) {
            $single = $this->probe($collection);
            $single->where($column, '=', $value);
            $this->applyConditions($single, $extra, $collection);
            $present += $single->exists() ? 1 : 0;
        }

        return $present;
    }

    private function transport(): ResourceTransport
    {
        return $this->transport instanceof Closure ? ($this->transport)() : $this->transport;
    }

    private function isDistinctable(string $collection, string $field): bool
    {
        $schemas = $this->schemas === null ? null : ($this->schemas)();

        return $schemas instanceof SchemaRepository
            && ($schemas->for($this->resourceFor($collection))?->field($field)?->isDistinctable() ?? false);
    }

    /**
     * Whether this rule is about a resource rather than a table.
     */
    private function isRemote(mixed $collection): bool
    {
        if ($this->connection !== null && in_array($this->connection, $this->connections, true)) {
            return true;
        }

        return is_string($collection) && is_subclass_of($collection, ApiModel::class);
    }

    /**
     * A builder for one check.
     */
    private function probe(string $collection): ApiQueryBuilder
    {
        $resource = $this->resourceFor($collection);

        return new ApiQueryBuilder(
            $this->api ??= new ApiConnection(),
            $this->transport(),
            $resource,
            $resource,
            'id',
            is_string($collection) && is_subclass_of($collection, ApiModel::class) ? $collection : '',
            $this->maxLimit,
            $this->inChunk,
        );
    }

    /**
     * The resource behind the rule's "table".
     */
    private function resourceFor(string $collection): string
    {
        if (is_subclass_of($collection, ApiModel::class)) {
            /** @var ApiModel $model */
            $model = new $collection;

            return $model->resource();
        }

        return $this->unqualify($collection);
    }

    /**
     * @param  array<array-key, mixed>  $extra
     */
    private function applyConditions(ApiQueryBuilder $probe, array $extra, string $collection): void
    {
        foreach ($extra as $key => $value) {
            if ($value instanceof Closure) {
                $this->applyCallback($probe, $value, $collection);

                continue;
            }

            if (! is_string($key) || trim($key) === '') {
                throw UnsupportedValidationRuleException::condition(
                    $collection,
                    (string) $key,
                    'a condition arrived without a column name',
                    ['transport' => $this->transport()->name()],
                );
            }

            $this->applyWhere($probe, trim($key), $value, $collection);
        }
    }

    /**
     * One compiled condition from the rule string.
     */
    private function applyWhere(ApiQueryBuilder $probe, string $column, mixed $value, string $collection): void
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if ($value === null || $value === 'NULL') {
            if ($this->isDeletedAt($column)) {
                $probe->trashed(null);

                return;
            }

            $probe->whereNull($column);

            return;
        }

        if ($value === 'NOT_NULL') {
            if ($this->isDeletedAt($column)) {
                $probe->trashed(QuerySpec::TRASHED_ONLY);

                return;
            }

            $probe->whereNotNull($column);

            return;
        }

        if (! is_scalar($value)) {
            throw UnsupportedValidationRuleException::condition(
                $collection,
                $column,
                sprintf('a %s was given where the API takes a single value', get_debug_type($value)),
                ['transport' => $this->transport()->name()],
            );
        }

        if (is_string($value) && str_starts_with($value, '!')) {
            $probe->where($column, '!=', mb_substr($value, 1));

            return;
        }

        $probe->where($column, '=', $value);
    }

    /**
     * A closure condition — using(), and the closures whereIn()/whereNotIn() leave behind.
     */
    private function applyCallback(ApiQueryBuilder $probe, Closure $callback, string $collection): void
    {
        try {
            $probe->where($callback);

            $probe->translatedWheres();
        } catch (UnsupportedValidationRuleException $exception) {
            throw $exception;
        } catch (UnsupportedQueryException $exception) {
            throw UnsupportedValidationRuleException::queryCallback(
                $collection,
                $exception->getMessage(),
                ['transport' => $this->transport()->name()],
                $exception,
            );
        }
    }

    private function isDeletedAt(string $column): bool
    {
        return $this->unqualify($column) === $this->deletedAtColumn;
    }

    private function pageSize(): int
    {
        return max(1, min($this->maxLimit, $this->inChunk));
    }

    /**
     * The values, deduplicated the way Laravel counted them.
     *
     * @param  array<array-key, mixed>  $values
     * @return list<mixed>
     */
    private function distinctValues(array $values): array
    {
        $unique = [];

        foreach ($values as $value) {
            $unique[$this->fingerprint($value)] ??= $value;
        }

        return array_values($unique);
    }

    private function fingerprint(mixed $value): string
    {
        return match (true) {
            $value === null => "\0null",
            $value instanceof BackedEnum => (string) $value->value,
            is_scalar($value) => (string) $value,
            $value instanceof Stringable => (string) $value,
            is_object($value) => "\0object:" . spl_object_hash($value),
            default => "\0array:" . md5(serialize($value)),
        };
    }

    /**
     * Drop a qualifier: "remote-eloquent.users" is "users", "users.email" is "email".
     */
    private function unqualify(string $name): string
    {
        $name = trim($name);
        $dot = strrpos($name, '.');

        return $dot === false ? $name : substr($name, $dot + 1);
    }
}
