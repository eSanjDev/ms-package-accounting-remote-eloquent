<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Query;

use Closure;
use Esanj\RemoteEloquent\Exceptions\UnboundedQueryException;
use Esanj\RemoteEloquent\Exceptions\UnsupportedQueryException;
use Esanj\RemoteEloquent\Models\ApiModel;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Str;

final class ApiEloquentBuilder extends EloquentBuilder
{
    public function toQuerySpec(): QuerySpec
    {
        $builder = $this->applyScopes();
        $builder->applyServerSideIncludes();

        return $builder->apiQuery()->toQuerySpec();
    }

    public function apiQuery(): ApiQueryBuilder
    {
        $query = $this->getQuery();

        if (!$query instanceof ApiQueryBuilder) {
            throw UnsupportedQueryException::method(
                sprintf('%s on a local query builder', static::class),
                'A remote model must build its queries through ApiQueryBuilder. Extend Esanj\\RemoteEloquent\\Models\\ApiModel instead of Illuminate\\Database\\Eloquent\\Model, or leave newBaseQueryBuilder() alone.',
                ['builder' => $query::class],
            );
        }

        return $query;
    }

    /**
     * A server-side include comes back as the server shapes it, so a constraint or column list on it would be silently dropped.
     */
    public function with($relations, $callback = null)
    {
        $items = $callback instanceof Closure
            ? [$relations => $callback]
            : (is_string($relations) ? func_get_args() : (array) $relations);

        foreach ($items as $key => $value) {
            $name = is_int($key) ? $value : $key;

            if (! is_string($name) || $this->model->isRelation(Str::before(Str::before($name, ':'), '.'))) {
                continue;
            }

            if (! is_int($key) || str_contains($name, ':')) {
                throw UnsupportedQueryException::method(
                    sprintf('with(\'%s\') with a constraint', Str::before($name, ':')),
                    'An include the server resolves cannot carry a closure or a column list: it would be ignored and every related row returned. Filter the included rows locally, or declare the relation on the model.',
                    ['builder_method' => 'with', 'include' => Str::before($name, ':')],
                );
            }
        }

        return parent::with(...func_get_args());
    }

    public function find($id, $columns = ['*'])
    {
        if (is_array($id) || $id instanceof Arrayable) {
            return $this->findMany($id, $columns);
        }

        if (!$this->isPlainKeyLookup()) {
            return parent::find($id, $columns);
        }

        return $this->fetchByKey($id, is_array($columns) ? $columns : [$columns]);
    }

    public function pluck($column, $key = null)
    {
        $query = $this->apiQuery();

        if ($query->getLimit() === null && !$query->isBoundedByKey()) {
            throw UnboundedQueryException::pluck(
                $this->model::class,
                is_string($column) ? $column : 'the column',
                $query->maxLimit(),
                ['resource' => $query->resource()],
            );
        }

        return parent::pluck($column, $key);
    }

    public function getModels($columns = ['*'])
    {
        $this->applyServerSideIncludes();

        return parent::getModels($columns);
    }

    public function paginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null, $total = null)
    {
        $page = $page ?: Paginator::resolveCurrentPage($pageName);

        $known = value($total);
        $known = is_numeric($known) ? (int)$known : null;

        $perPage = value($perPage, $known);
        $perPage = is_numeric($perPage) && (int)$perPage > 0 ? (int)$perPage : $this->model->getPerPage();

        if ($known === null) {
            $this->apiQuery()->requestTotal();
        }

        $results = $this->forPage($page, $perPage)->get($columns);

        $total = $known ?? $this->apiQuery()->lastTotal() ?? (($page - 1) * $perPage + $results->count());

        return $this->paginator($results, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    public function simplePaginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null)
    {
        $page = $page ?: Paginator::resolveCurrentPage($pageName);
        $perPage = $perPage ?: $this->model->getPerPage();

        $this->offset(($page - 1) * $perPage)->limit($perPage);

        $results = $this->get($columns);

        $paginator = $this->simplePaginator($results, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);

        return $paginator->hasMorePagesWhen($this->apiQuery()->lastHasMore());
    }

    protected function eagerLoadRelation(array $models, $name, Closure $constraints)
    {
        $relation = $this->getRelation($name);

        $relation->addEagerConstraints($models);

        $constraints($relation);

        $query = $relation->getBaseQuery();

        if ($query instanceof ApiQueryBuilder) {
            $query->asRelationLoad((string)$name);
        }

        return $relation->match(
            $relation->initRelation($models, $name),
            $relation->getEager(),
            $name,
        );
    }

    public function has($relation, $operator = '>=', $count = 1, $boolean = 'and', ?Closure $callback = null)
    {
        if (!is_string($relation)) {
            throw UnsupportedQueryException::subquery('has', ['resource' => $this->apiQuery()->resource()]);
        }

        $root = Str::before($relation, '.');

        $related = $this->model->isRelation($root)
            ? $this->assertRemoteRelation($root, 'whereHas')
            : null;

        if ($related !== null) {
            $this->assertRelationAddsNothing($root);
        }

        $conditions = [];

        if ($callback !== null) {
            $conditions = $this->constraintsFor($relation, $related, $root !== $relation, $callback);
        }

        $this->apiQuery()->addHasClause(
            Where::has($relation, $conditions, (string)$operator, (int)$count, $this->rootBoolean($boolean), $this->isNegated($boolean)),
            $this->rootBoolean($boolean),
        );

        return $this;
    }

    public function hasMorph($relation, $types, $operator = '>=', $count = 1, $boolean = 'and', ?Closure $callback = null): never
    {
        throw UnsupportedQueryException::method(
            'whereHasMorph()',
            'A polymorphic relation spans tables the resource API does not expose. Resolve the type you mean, pluck its ids, and pass them to whereIn().',
            ['builder_method' => 'hasMorph', 'relation' => is_string($relation) ? $relation : ''],
        );
    }

    public function withCount($relations)
    {
        $relations = is_array($relations) ? $relations : func_get_args();

        foreach ($relations as $name => $constraints) {
            if (is_int($name)) {
                $name = $constraints;
                $constraints = null;
            }

            if (!is_string($name)) {
                throw UnsupportedQueryException::subquery('withCount', ['resource' => $this->apiQuery()->resource()]);
            }

            if ($constraints !== null) {
                throw UnsupportedQueryException::method(
                    sprintf('withCount([\'%s\' => fn ($q) => ...])', $name),
                    'A counted relation is counted by the server, whole. Constrain it with whereHas() instead, or fetch the related rows and count them locally.',
                    ['builder_method' => 'withCount', 'relation' => $name],
                );
            }

            $this->assertRemoteRelation($name, 'withCount');

            $this->apiQuery()->countRelations($name);
        }

        return $this;
    }

    public function withAggregate($relations, $column, $function = null)
    {
        $aggregate = is_string($function) ? strtolower(trim($function)) : null;

        if ($aggregate === 'count' && ($column === '*' || $column === null)) {
            return $this->withCount($relations);
        }

        $called = sprintf('with%s()', $aggregate === null ? 'Aggregate' : ucfirst($aggregate));

        throw UnsupportedQueryException::method(
            $called,
            sprintf(
                'A QuerySpec carries one aggregate over a relation, a count, so %s has no equivalent on the wire. Count it with withCount(\'relation\') or $model->loadCount(\'relation\'), ask only whether it exists with whereHas(\'relation\'), or aggregate the related resource itself: Wallet::query()->where(\'user_id\', $user->id)->sum(\'balance\').',
                $called,
            ),
            ['builder_method' => 'withAggregate', 'aggregate' => is_string($function) ? $function : null],
        );
    }

    public function cursorPaginate($perPage = null, $columns = ['*'], $cursorName = 'cursor', $cursor = null): never
    {
        throw UnsupportedQueryException::cursorPagination(['resource' => $this->apiQuery()->resource()]);
    }

    public function cursor(): never
    {
        throw UnsupportedQueryException::chunking('cursor', ['resource' => $this->apiQuery()->resource()]);
    }

    public function chunk($count, callable $callback): never
    {
        throw UnsupportedQueryException::chunking('chunk', ['resource' => $this->apiQuery()->resource()]);
    }

    public function chunkMap(callable $callback, $count = 1000): never
    {
        throw UnsupportedQueryException::chunking('chunkMap', ['resource' => $this->apiQuery()->resource()]);
    }

    public function each(callable $callback, $count = 1000): never
    {
        throw UnsupportedQueryException::chunking('each', ['resource' => $this->apiQuery()->resource()]);
    }

    public function lazy($chunkSize = 1000): never
    {
        throw UnsupportedQueryException::chunking('lazy', ['resource' => $this->apiQuery()->resource()]);
    }

    public function orderedChunkById($count, callable $callback, $column = null, $alias = null, $descending = false)
    {
        return parent::orderedChunkById($this->idPageSize($count), $callback, $column, $alias, $descending);
    }

    public function eachById(callable $callback, $count = 1000, $column = null, $alias = null)
    {
        // eachById() numbers items by this count, so it is clamped here too.
        return parent::eachById($callback, $this->idPageSize($count), $column, $alias);
    }

    protected function orderedLazyById($chunkSize = 1000, $column = null, $alias = null, $descending = false)
    {
        return parent::orderedLazyById($this->idPageSize($chunkSize), $column, $alias, $descending);
    }

    private function idPageSize(mixed $size): int
    {
        return min((int)$size, $this->apiQuery()->maxLimit());
    }

    public function firstOrCreate(array $attributes = [], Closure|array $values = []): never
    {
        throw UnsupportedQueryException::atomicUpsert('firstOrCreate', ['resource' => $this->apiQuery()->resource()]);
    }

    public function createOrFirst(array $attributes = [], Closure|array $values = []): never
    {
        throw UnsupportedQueryException::atomicUpsert('createOrFirst', ['resource' => $this->apiQuery()->resource()]);
    }

    public function updateOrCreate(array $attributes, Closure|array $values = []): never
    {
        throw UnsupportedQueryException::atomicUpsert('updateOrCreate', ['resource' => $this->apiQuery()->resource()]);
    }

    public function incrementOrCreate(array $attributes, string $column = 'count', $default = 1, $step = 1, array $extra = []): never
    {
        throw UnsupportedQueryException::atomicUpsert('incrementOrCreate', ['resource' => $this->apiQuery()->resource()]);
    }

    public function upsert(array $values, $uniqueBy, $update = null): never
    {
        throw UnsupportedQueryException::atomicUpsert('upsert', ['resource' => $this->apiQuery()->resource()]);
    }

    public function update(array $values): never
    {
        throw UnsupportedQueryException::massUpdate(['resource' => $this->apiQuery()->resource()]);
    }

    public function delete(): never
    {
        throw UnsupportedQueryException::massDelete(['resource' => $this->apiQuery()->resource()]);
    }

    public function restore(): never
    {
        throw UnsupportedQueryException::method(
            'restore() on a query',
            'The resource API restores one record at a time. Load them — withTrashed()->whereIn(...)->get() — and call restore() on each.',
            ['resource' => $this->apiQuery()->resource()],
        );
    }

    public function forceDelete(): never
    {
        throw UnsupportedQueryException::massDelete([
            'resource' => $this->apiQuery()->resource(),
            'builder_method' => 'forceDelete',
        ]);
    }

    public function touch($column = null): never
    {
        throw UnsupportedQueryException::counterMutation('touch', ['resource' => $this->apiQuery()->resource()]);
    }

    public function increment($column, $amount = 1, array $extra = []): never
    {
        throw UnsupportedQueryException::counterMutation('increment', [
            'resource' => $this->apiQuery()->resource(),
            'field' => is_string($column) ? $column : null,
        ]);
    }

    public function decrement($column, $amount = 1, array $extra = []): never
    {
        throw UnsupportedQueryException::counterMutation('decrement', [
            'resource' => $this->apiQuery()->resource(),
            'field' => is_string($column) ? $column : null,
        ]);
    }

    protected function applyServerSideIncludes(): void
    {
        if ($this->eagerLoad === []) {
            return;
        }

        $query = $this->apiQuery();

        foreach (array_keys($this->eagerLoad) as $name) {
            $name = (string)$name;

            if ($this->model->isRelation(Str::before($name, '.'))) {
                continue;
            }

            $query->includeRelations($name);

            unset($this->eagerLoad[$name]);
        }
    }

    private function isPlainKeyLookup(): bool
    {
        $query = $this->getQuery();

        return $this->scopes === []
            && $query instanceof ApiQueryBuilder
            && $query->beforeQueryCallbacks === []
            && $query->counts() === []
            && empty($query->lock)
            && $query->wheres === []
            && empty($query->orders)
            && $query->limit === null
            && empty($query->offset)
            && empty($query->groups)
            && empty($query->havings)
            && empty($query->joins)
            && empty($query->unions)
            && $query->distinct === false;
    }

    private function fetchByKey(mixed $id, array $columns): ?Model
    {
        if ($id === null) {
            return null;
        }

        // "schema" or "me" is never a user id; sent as one it would reach a different route.
        if ($this->model->getKeyType() === 'int' && ! is_int($id) && preg_match('/^-?\d+$/', (string) $id) !== 1) {
            return null;
        }

        $query = $this->apiQuery();

        $this->applyServerSideIncludes();

        $options = [];

        $fields = $query->columns === null
            ? $query->fieldsFor($columns)
            : $query->projection();

        // find() passes ['*']: no explicit choice, so every readable field, as get() does.
        if ($fields === []) {
            $fields = $query->projection();
        }

        if ($fields !== []) {
            $options['fields'] = $fields;
        }

        if ($query->includes() !== []) {
            $options['include'] = $query->includes();
        }

        if ($query->trashedMode() !== null) {
            $options['trashed'] = $query->trashedMode();
        }

        $record = $query->fetchRecord(is_int($id) ? $id : (string)$id, $options);

        if ($record === null) {
            return null;
        }

        $model = $this->model->newFromBuilder($record);

        $loaded = $this->eagerLoadRelations([$model]);

        return $loaded[0] ?? $model;
    }

    private function constraintsFor(string $relation, ?ApiModel $related, bool $nested, Closure $callback): array
    {
        if ($related === null || $nested) {
            throw UnsupportedQueryException::method(
                sprintf('whereHas(\'%s\', fn ($q) => ...)', $relation),
                sprintf('Constraining "%s" needs the relation declared on the model and one hop deep, so its fields can be translated against the right resource. Declare it, or drop the closure — whereHas(\'%s\') asks only whether the relation exists, and that the server can answer on its own.', $relation, $relation),
                ['builder_method' => 'whereHas', 'relation' => $relation],
            );
        }

        $builder = $related->newQuery();

        if (!$builder instanceof self) {
            throw UnsupportedQueryException::localRelationConstraint('whereHas', $relation, [
                'resource' => $this->apiQuery()->resource(),
            ]);
        }

        $callback($builder);

        // The related model's global scopes are part of what "has" means.
        $scoped = $builder->applyScopes();

        if ($scoped->apiQuery()->trashedMode() !== null) {
            throw UnsupportedQueryException::method(
                sprintf('withTrashed()/onlyTrashed() inside whereHas(\'%s\')', $relation),
                'A has clause carries field conditions only; the server applies its default trashed state to the related records. Query the related resource on its own with the trashed state you need, and pass the ids to whereIn().',
                ['builder_method' => 'whereHas', 'relation' => $relation],
            );
        }

        return $scoped->apiQuery()->translatedWheres();
    }

    private function assertRelationAddsNothing(string $relation): void
    {
        $query = $this->getRelation($relation)->getQuery();

        if (!$query instanceof self) {
            return;
        }

        if ($query->apiQuery()->wheres !== [] || $query->apiQuery()->trashedMode() !== null) {
            throw UnsupportedQueryException::method(
                sprintf('whereHas(\'%s\') on a relation that adds conditions of its own', $relation),
                sprintf('The server applies "%s" as it publishes it, without the where() or trashed state its definition adds. Move the where() conditions into the whereHas() closure.', $relation),
                ['builder_method' => 'whereHas', 'relation' => $relation],
            );
        }
    }

    private function assertRemoteRelation(string $relation, string $method): ?ApiModel
    {
        if (!$this->model->isRelation($relation)) {
            return null;
        }

        $resolved = $this->getRelation($relation);

        $related = $resolved instanceof Relation ? $resolved->getRelated() : null;

        if ($related instanceof ApiModel) {
            return $related;
        }

        throw UnsupportedQueryException::localRelationConstraint($method, $relation, [
            'resource' => $this->apiQuery()->resource(),
            'related' => $related === null ? null : $related::class,
        ]);
    }

    private function rootBoolean(string $boolean): string
    {
        return str_starts_with(strtolower(trim($boolean)), 'or') ? 'or' : 'and';
    }

    private function isNegated(string $boolean): bool
    {
        return str_contains(strtolower($boolean), 'not');
    }
}
