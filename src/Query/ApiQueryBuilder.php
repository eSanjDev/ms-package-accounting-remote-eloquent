<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Query;

use ArrayObject;
use Esanj\RemoteEloquent\Cache\IdentityMap;
use Esanj\RemoteEloquent\Contracts\ResourceTransport;
use Esanj\RemoteEloquent\Database\ApiConnection;
use Esanj\RemoteEloquent\Exceptions\InvalidQueryException;
use Esanj\RemoteEloquent\Exceptions\UnboundedQueryException;
use Esanj\RemoteEloquent\Exceptions\UnsupportedQueryException;
use Esanj\RemoteEloquent\Schema\SchemaValidator;
use Esanj\RemoteEloquent\Transport\RemoteResponse;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder as BaseQueryBuilder;

final class ApiQueryBuilder extends BaseQueryBuilder
{
    public const HAS_CLAUSE = 'RemoteHas';

    /** @var list<string> */
    private array $includes = [];

    /** @var list<string> */
    private array $counts = [];

    private ?string $trashed = null;

    private bool $withTotal = false;

    private ?string $relationLoad = null;

    private ArrayObject $meta;

    public function __construct(
        ApiConnection $connection,
        private readonly ResourceTransport $transport,
        private readonly string $resource,
        private readonly string $table = '',
        private readonly string $keyName = 'id',
        private readonly string $modelClass = '',
        private readonly int $maxLimit = 100,
        private readonly int $inChunk = 500,
        private readonly ?SchemaValidator $validator = null,
        private readonly ?IdentityMap $identityMap = null,
        private readonly string $application = '',
        private readonly ?string $actor = null,
    ) {
        parent::__construct($connection, $connection->getQueryGrammar(), $connection->getPostProcessor());

        $this->from = $this->table !== '' ? $this->table : $this->resource;
        $this->meta = new ArrayObject(['total' => null, 'has_more' => false, 'response' => null]);
    }

    public function newQuery(): self
    {
        return new self(
            $this->apiConnection(),
            $this->transport,
            $this->resource,
            $this->table,
            $this->keyName,
            $this->modelClass,
            $this->maxLimit,
            $this->inChunk,
            $this->validator,
            $this->identityMap,
            $this->application,
            $this->actor,
        );
    }

    public function resource(): string
    {
        return $this->resource;
    }

    public function keyName(): string
    {
        return $this->keyName;
    }

    public function maxLimit(): int
    {
        return $this->maxLimit;
    }

    public function inChunk(): int
    {
        return $this->inChunk;
    }

    public function identityMap(): ?IdentityMap
    {
        return $this->identityMap;
    }

    public function application(): string
    {
        return $this->application;
    }

    public function actor(): ?string
    {
        return $this->actor;
    }

    public function transport(): ResourceTransport
    {
        return $this->transport;
    }

    public function includeRelations(string ...$relations): self
    {
        foreach ($relations as $relation) {
            $relation = trim($relation);

            if ($relation !== '' && ! in_array($relation, $this->includes, true)) {
                $this->includes[] = $relation;
            }
        }

        return $this;
    }

    public function includes(): array
    {
        return $this->includes;
    }

    public function countRelations(string ...$relations): self
    {
        foreach ($relations as $relation) {
            $relation = trim($relation);

            if ($relation !== '' && ! in_array($relation, $this->counts, true)) {
                $this->counts[] = $relation;
            }
        }

        return $this;
    }

    public function counts(): array
    {
        return $this->counts;
    }

    public function trashed(?string $trashed): self
    {
        $this->trashed = $trashed === null ? null : QuerySpec::make()->trashed($trashed)->toArray()['trashed'];

        return $this;
    }

    public function trashedMode(): ?string
    {
        return $this->trashed;
    }

    public function requestTotal(bool $withTotal = true): self
    {
        $this->withTotal = $withTotal;

        return $this;
    }

    public function addHasClause(Where $condition, string $boolean = 'and'): self
    {
        $this->wheres[] = [
            'type' => self::HAS_CLAUSE,
            'remote' => $condition,
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function toQuerySpec(): QuerySpec
    {
        $this->assertSendableShape();

        $spec = QuerySpec::make()
            ->fields($this->projection())
            ->where(...$this->translatedWheres())
            ->offset($this->offset === null ? 0 : (int) $this->offset)
            ->withTotal($this->withTotal)
            ->trashed($this->trashed)
            ->distinct($this->distinct === true);

        $spec->limit($this->assertedLimit());

        foreach ((new OrderTranslator($this->resource, $this->tableName()))->order($this->orders) as $order) {
            $spec->order($order['field'], $order['direction']);
        }

        if ($this->includes !== []) {
            $spec->include($this->includes);
        }

        if ($this->counts !== []) {
            $spec->counts($this->counts);
        }

        return $spec;
    }

    public function translatedWheres(): array
    {
        return (new WhereTranslator($this->resource, $this->tableName()))->translate($this->wheres);
    }

    public function projection(): array
    {
        return $this->fieldsFor($this->columns ?? []);
    }

    public function fieldsFor(array $columns): array
    {
        $fields = [];

        foreach ($columns as $column) {
            if ($column instanceof ExpressionContract) {
                throw UnsupportedQueryException::rawExpression('selectRaw', $this->context());
            }

            if (! is_string($column)) {
                throw UnsupportedQueryException::subquery('select', $this->context());
            }

            $column = trim($column);

            if ($column === '') {
                continue;
            }

            if (stripos($column, ' as ') !== false) {
                throw UnsupportedQueryException::method(
                    sprintf('select(\'%s\')', $column),
                    'A field cannot be aliased on the way out: the resource names its own fields and the response is keyed by them. Select the field and rename it on the model with an accessor.',
                    $this->context(['field' => $column]),
                );
            }

            $field = $this->unqualify($column);

            if ($field === '*') {
                return [];
            }

            if (! in_array($field, $fields, true)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    protected function runSelect()
    {
        if ($this->limit !== null && (int) $this->limit === 0) {
            return [];
        }

        $this->assertSendableShape();

        $this->assertBounded('get');

        $oversized = $this->oversizedValueList(
            $this->limit === null ? $this->chunkSize() : $this->listCap(),
        );

        if ($oversized !== null) {
            return $this->sendSplitByValues($oversized[0], $oversized[1]);
        }

        $records = $this->send($this->toQuerySpec());

        $this->assertNothingWasTruncated();

        return $records;
    }

    public function exists(): bool
    {
        $this->applyBeforeQueryCallbacks();

        $probe = clone $this;
        $probe->columns = [$this->keyName];
        $probe->limit = 1;
        $probe->offset = null;
        $probe->orders = null;
        $probe->includes = [];
        $probe->counts = [];
        $probe->withTotal = false;

        $oversized = $probe->oversizedValueList($probe->listCap());

        if ($oversized === null) {
            return $probe->send($probe->toQuerySpec()) !== [];
        }

        foreach (array_chunk($oversized[1], $probe->listCap()) as $chunk) {
            $page = clone $probe;
            $page->wheres[$oversized[0]]['values'] = $chunk;

            if ($page->send($page->toQuerySpec()) !== []) {
                return true;
            }
        }

        return false;
    }

    public function doesntExist(): bool
    {
        return ! $this->exists();
    }

    public function aggregate($function, $columns = ['*']): mixed
    {
        $this->applyBeforeQueryCallbacks();
        $this->assertSendableShape();

        $function = strtolower(trim((string) $function));

        if (! in_array($function, ['count', 'min', 'max', 'sum', 'avg'], true)) {
            throw UnsupportedQueryException::method(
                sprintf('The "%s" aggregate', $function),
                'The resource API publishes count, min, max, sum and avg. Fetch the rows you need and compute the rest locally.',
                $this->context(['aggregate' => $function]),
            );
        }

        $field = $this->aggregateField($columns);

        if ($this->distinct === true && ($function !== 'count' || $field === null)) {
            throw UnsupportedQueryException::method(
                sprintf('distinct() before %s()', $function),
                'The only distinct aggregate is a count over one field: distinct()->count(\'field\'). For anything else, read the distinct values as a query (select the field, distinct(), get()) and compute locally.',
                $this->context(['builder_method' => 'distinct', 'aggregate' => $function]),
            );
        }

        if ($field === null && $function !== 'count') {
            throw new InvalidQueryException(
                sprintf('%s() needs a field name: "%s" cannot be aggregated over every field at once.', $function, $this->resource),
                'invalid_query',
                0,
                null,
                $this->context(['aggregate' => $function]),
            );
        }

        $oversized = $this->oversizedValueList($this->listCap());

        if ($oversized === null) {
            return $this->sendAggregate($function, $field);
        }

        if ($this->distinct === true) {
            throw UnsupportedQueryException::method(
                'distinct()->count() over a list longer than one request',
                sprintf('The list has to go out in pieces of %d, and distinct counts do not add up across pieces - a value present in two pieces would be counted twice. Narrow the list, or read the distinct values as a query and count them locally.', $this->listCap()),
                $this->context(['builder_method' => 'distinct', 'aggregate' => $function, 'value_count' => count($oversized[1])]),
            );
        }

        return $this->foldAggregate($function, $field, $oversized[0], $oversized[1]);
    }

    private function sendAggregate(string $function, ?string $field): mixed
    {
        $spec = [
            'function' => $function,
            'where' => array_map(static fn (Where $where): array => $where->toArray(), $this->translatedWheres()),
        ];

        if ($field !== null) {
            $spec['field'] = $field;
        }

        if ($this->trashed !== null) {
            $spec['trashed'] = $this->trashed;
        }

        if ($this->distinct === true) {
            $spec['distinct'] = true;
        }

        $response = $this->transport->aggregate($this->resource, $spec);

        $this->remember($response);

        return $response->value();
    }

    private function foldAggregate(string $function, ?string $field, int $index, array $values): mixed
    {
        $chunkSize = $this->listCap();

        if ($function === 'avg') {
            throw new InvalidQueryException(
                sprintf(
                    'avg(\'%s\') filters on %d values and one request may carry %d, so the list has to go out in pieces — and an average of averages is not the average: each piece would weigh the same as every other one, however many records it covers. Ask for the two parts that do add up and divide them: sum(\'%s\') / count().',
                    (string) $field,
                    count($values),
                    $chunkSize,
                    (string) $field,
                ),
                'invalid_query',
                0,
                null,
                $this->context([
                    'aggregate' => 'avg',
                    'field' => $field,
                    'value_count' => count($values),
                    'max_values' => $chunkSize,
                ]),
            );
        }

        $folded = null;

        foreach (array_chunk($values, $chunkSize) as $chunk) {
            $page = clone $this;
            $page->wheres[$index]['values'] = $chunk;

            $value = $page->sendAggregate($function, $field);

            if ($value === null) {
                continue;
            }

            $folded = match (true) {
                $folded === null => $value,
                $function === 'min' => min($folded, $value),
                $function === 'max' => max($folded, $value),
                default => $folded + $value,
            };
        }

        return $folded;
    }

    public function count($columns = '*'): int
    {
        return (int) $this->aggregate('count', is_array($columns) ? $columns : [$columns]);
    }

    public function getCountForPagination($columns = ['*']): int
    {
        return $this->count($columns === ['*'] ? '*' : ($columns[0] ?? '*'));
    }

    /**
     * Fetch one record by id, through the identity map.
     *
     * @param  array<string, mixed>  $options  fields, include, trashed
     * @return array<string, mixed>|null
     */
    public function fetchRecord(string|int $id, array $options = []): ?array
    {
        /** @var list<string> $fields */
        $fields = is_array($options['fields'] ?? null) ? $options['fields'] : [];
        /** @var list<string> $include */
        $include = is_array($options['include'] ?? null) ? $options['include'] : [];
        $trashed = is_string($options['trashed'] ?? null) ? $options['trashed'] : null;

        $key = $this->identityMap?->key(
            $this->application,
            $this->actor,
            $this->resource,
            $id,
            $fields,
            $include,
            $trashed,
        );

        if ($key !== null && ($cached = $this->identityMap?->get($key)) !== null) {
            return $cached;
        }

        try {
            $response = $this->transport->find($this->resource, $id, $options);
        } catch (ModelNotFoundException) {
            return null;
        }

        $this->remember($response);

        $record = $response->record();

        if ($record === null) {
            return null;
        }

        if ($key !== null) {
            $this->identityMap?->put(
                $this->identityMap->key(
                    $this->application,
                    $this->actor,
                    $this->resource,
                    $id,
                    $fields,
                    $include,
                    $trashed,
                ),
                $record,
            );
        }

        return $record;
    }

    /**
     * The total the last response reported, or null when none was asked for.
     */
    public function lastTotal(): ?int
    {
        $total = $this->meta['total'] ?? null;

        return is_int($total) ? $total : null;
    }

    public function lastHasMore(): bool
    {
        return (bool) ($this->meta['has_more'] ?? false);
    }

    public function lastResponse(): ?RemoteResponse
    {
        $response = $this->meta['response'] ?? null;

        return $response instanceof RemoteResponse ? $response : null;
    }

    public function toSql(): never
    {
        throw UnsupportedQueryException::method(
            'toSql()',
            sprintf('There is no SQL: a %s query is a QuerySpec posted to the resource API. Call toQuerySpec()->toArray() to see the body that would be sent.', $this->resource),
            $this->context(),
        );
    }

    public function toRawSql(): never
    {
        throw UnsupportedQueryException::method(
            'toRawSql()',
            sprintf('There is no SQL: a %s query is a QuerySpec posted to the resource API. Call toQuerySpec()->toArray() to see the body that would be sent.', $this->resource),
            $this->context(),
        );
    }

    public function cursor(): never
    {
        throw UnsupportedQueryException::chunking('cursor', $this->context());
    }

    /**
     * @param  array<int|string, mixed>  $values
     */
    public function insert(array $values): never
    {
        throw self::writeThroughModel('insert', 'create');
    }

    /**
     * @param  array<int|string, mixed>  $values
     */
    public function insertOrIgnore(array $values): never
    {
        throw UnsupportedQueryException::atomicUpsert('insertOrIgnore', $this->context());
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  string|null  $sequence
     */
    public function insertGetId(array $values, $sequence = null): never
    {
        throw self::writeThroughModel('insertGetId', 'create');
    }

    /**
     * @param  array<int, string>  $columns
     * @param  mixed  $query
     */
    public function insertUsing(array $columns, $query): never
    {
        throw UnsupportedQueryException::subquery('insertUsing', $this->context());
    }

    /**
     * @param  array<int, string>  $columns
     * @param  mixed  $query
     */
    public function insertOrIgnoreUsing(array $columns, $query): never
    {
        throw UnsupportedQueryException::subquery('insertOrIgnoreUsing', $this->context());
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): never
    {
        throw UnsupportedQueryException::massUpdate($this->context());
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|callable  $values
     */
    public function updateOrInsert(array $attributes, array|callable $values = []): never
    {
        throw UnsupportedQueryException::atomicUpsert('updateOrInsert', $this->context());
    }

    /**
     * @param  array<int, array<string, mixed>>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int, string>|null  $update
     */
    public function upsert(array $values, array|string $uniqueBy, ?array $update = null): never
    {
        throw UnsupportedQueryException::atomicUpsert('upsert', $this->context());
    }

    /**
     * @param  mixed  $id
     */
    public function delete($id = null): never
    {
        throw UnsupportedQueryException::massDelete($this->context());
    }

    public function truncate(): never
    {
        throw UnsupportedQueryException::massDelete($this->context(['builder_method' => 'truncate']));
    }

    /**
     * @param  string  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     */
    public function increment($column, $amount = 1, array $extra = []): never
    {
        throw UnsupportedQueryException::counterMutation('increment', $this->context(['field' => (string) $column]));
    }

    /**
     * @param  string  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     */
    public function decrement($column, $amount = 1, array $extra = []): never
    {
        throw UnsupportedQueryException::counterMutation('decrement', $this->context(['field' => (string) $column]));
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function incrementEach(array $columns, array $extra = []): never
    {
        throw UnsupportedQueryException::counterMutation('incrementEach', $this->context());
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function decrementEach(array $columns, array $extra = []): never
    {
        throw UnsupportedQueryException::counterMutation('decrementEach', $this->context());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function send(QuerySpec $spec): array
    {
        $this->validator?->assertQueryable($this->resource, $spec);

        $response = $this->transport->query($this->resource, $spec->toArray());

        $this->remember($response);

        return $response->records();
    }

    /**
     * An "in" list longer than one request may carry, split across requests.
     *
     * @param  list<mixed>  $values
     * @return array<int, array<string, mixed>>
     */
    private function sendSplitByValues(int $index, array $values): array
    {
        if ($this->limit !== null || ($this->offset !== null && (int) $this->offset > 0) || ! empty($this->orders)) {
            $field = $this->splitField($index);
            $cap = $this->listCap();

            throw new InvalidQueryException(
                sprintf(
                    'whereIn(\'%s\', ...) was given %d values and one request may carry %d, so the list has to go out in pieces — but a limit, an offset or an ordering cannot go with it: each piece would apply the window to its own share, and the pieces stitched together answer a question nobody asked. Drop the window and let the list bound the query on its own, or chunk the values yourself with array_chunk() and merge what comes back.',
                    $field,
                    count($values),
                    $cap,
                ),
                'invalid_query',
                0,
                null,
                $this->context([
                    'builder_method' => 'whereIn',
                    'field' => $field,
                    'value_count' => count($values),
                    'max_values' => $cap,
                ]),
            );
        }

        $chunkSize = $this->chunkSize();

        $records = [];

        foreach (array_chunk($values, $chunkSize) as $chunk) {
            $page = clone $this;
            $page->wheres[$index]['values'] = $chunk;

            foreach ($page->send($page->toQuerySpec()) as $record) {
                $records[] = $record;
            }

            $page->assertNothingWasTruncated();
        }

        return $records;
    }

    /**
     * The field a split is chunking, for the message when it cannot be.
     */
    private function splitField(int $index): string
    {
        $clause = $this->wheres[$index] ?? null;
        $column = is_array($clause) ? ($clause['column'] ?? null) : null;

        return is_string($column) ? ($this->lenientField($column) ?? $column) : $this->keyName;
    }

    private function remember(RemoteResponse $response): void
    {
        $this->meta['response'] = $response;
        $this->meta['total'] = $response->total();
        $this->meta['has_more'] = $response->hasMore();

        $this->identityMap?->observe($response->schemaVersion(), $response->permissionsVersion());
    }

    private function assertBounded(string $method): void
    {
        if ($this->limit !== null || $this->isBoundedByKey()) {
            return;
        }

        throw UnboundedQueryException::get($this->modelName(), $this->maxLimit, $this->context([
            'builder_method' => $method,
        ]));
    }

    /**
     * Is anything other than a limit holding this query down?
     */
    public function isBoundedByKey(): bool
    {
        return $this->rowBound() !== null;
    }

    /**
     * Tell this builder it is a relation's own query.
     */
    public function asRelationLoad(string $relation): self
    {
        $relation = trim($relation);

        $this->relationLoad = $relation === '' ? null : $relation;

        return $this;
    }

    /**
     * @return array{rows: int|null, through: string}|null
     */
    private function rowBound(): ?array
    {
        if ($this->hasOrAtTopLevel()) {
            return null;
        }

        $cap = $this->keyRowCap();

        if ($cap !== null) {
            return ['rows' => $cap, 'through' => $this->keyName];
        }

        $through = $this->relationLoad ?? $this->relationConstraintField();

        return $through === null ? null : ['rows' => null, 'through' => $through];
    }

    /**
     * The most rows an ANDed primary-key clause can match, or null when there is none.
     */
    private function keyRowCap(): ?int
    {
        $cap = null;

        foreach ($this->wheres as $clause) {
            if (! $this->isPlainAnd($clause)) {
                continue;
            }

            $column = $clause['column'] ?? null;

            if (! is_string($column) || $this->lenientField($column) !== $this->keyName) {
                continue;
            }

            $type = strtolower((string) ($clause['type'] ?? ''));

            if ($type === 'basic' && in_array((string) ($clause['operator'] ?? ''), ['=', '<=>'], true)) {
                return 1;
            }

            if (($type === 'in' || $type === 'inraw') && is_array($clause['values'] ?? null)) {
                $count = count($this->distinctValues($clause['values']));

                $cap = $cap === null ? $count : min($cap, $count);
            }
        }

        return $cap;
    }

    /**
     * The foreign key of a lazily loaded hasMany, read off the clauses Laravel writes for one.
     */
    private function relationConstraintField(): ?string
    {
        $compared = [];
        $notNull = [];

        foreach ($this->wheres as $clause) {
            if (! $this->isPlainAnd($clause)) {
                continue;
            }

            $column = $clause['column'] ?? null;
            $field = is_string($column) ? $this->lenientField($column) : null;

            if ($field === null || $field === $this->keyName) {
                continue;
            }

            $type = strtolower((string) ($clause['type'] ?? ''));

            if ($type === 'basic' && in_array((string) ($clause['operator'] ?? ''), ['=', '<=>'], true)) {
                $compared[$field] = true;
            } elseif ($type === 'notnull') {
                $notNull[$field] = true;
            }
        }

        foreach (array_keys($compared) as $field) {
            if (isset($notNull[$field])) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Is any condition OR'd onto the ones before it?
     */
    private function hasOrAtTopLevel(): bool
    {
        foreach ($this->wheres as $index => $clause) {
            if ($index === 0 || ! is_array($clause)) {
                continue;
            }

            if (str_starts_with(strtolower(trim((string) ($clause['boolean'] ?? 'and'))), 'or')) {
                return true;
            }
        }

        return false;
    }

    /**
     * A clause ANDed onto its neighbours and not negated.
     */
    private function isPlainAnd(mixed $clause): bool
    {
        return is_array($clause)
            && strtolower(trim((string) ($clause['boolean'] ?? 'and'))) === 'and';
    }

    /**
     * @return array{0: int, 1: list<mixed>}|null
     */
    private function oversizedValueList(int $threshold): ?array
    {
        $cap = $this->listCap();
        $orAtTopLevel = $this->hasOrAtTopLevel();

        foreach ($this->wheres as $index => $clause) {
            if (! is_array($clause)) {
                continue;
            }

            $type = strtolower((string) ($clause['type'] ?? ''));

            if (! in_array($type, ['in', 'inraw', 'notin', 'notinraw'], true)) {
                continue;
            }

            $column = $clause['column'] ?? null;
            $values = $clause['values'] ?? null;

            if (! is_string($column) || ! is_array($values)) {
                continue;
            }

            $field = $this->lenientField($column);

            if ($field === null) {
                continue;
            }

            $values = $this->distinctValues($values);
            $count = count($values);

            $splittable = ($type === 'in' || $type === 'inraw')
                && ! $orAtTopLevel
                && $this->isPlainAnd($clause);

            if ($splittable) {
                if ($count > $threshold) {
                    return [(int) $index, $values];
                }

                continue;
            }

            if ($count > $cap) {
                throw $this->unsplittableList($field, $count, $cap, match (true) {
                    $type === 'notin' || $type === 'notinraw' => 'a not_in is not a union: "not in (the first half)" and "not in (the second half)" are both true of very nearly every record, so merging the pieces would answer with very nearly everything',
                    $orAtTopLevel => 'an orWhere() sits beside it and widens the query past anything the list names, so every record the other branch matches would come back once per piece',
                    default => 'the clause is negated, and NOT (a or b) is not the question NOT a or NOT b asks',
                });
            }
        }

        return null;
    }

    /**
     * A value list too long for one request that no split can rescue.
     *
     * @param  non-empty-string  $reason
     */
    private function unsplittableList(string $field, int $count, int $max, string $reason): InvalidQueryException
    {
        return new InvalidQueryException(
            sprintf(
                'The list on "%s" carries %d values and one request may carry %d. A list that long is normally split across several requests and the answers merged; this one cannot be, because %s. Narrow the list, or ask the parts as separate queries and combine them where the difference is visible.',
                $field,
                $count,
                $max,
                $reason,
            ),
            'invalid_query',
            0,
            null,
            $this->context([
                'field' => $field,
                'value_count' => $count,
                'max_values' => $max,
            ]),
        );
    }

    /**
     * The longest value list ONE request may carry.
     */
    private function listCap(): int
    {
        return max(1, min($this->inChunk, Where::MAX_IN));
    }

    /**
     * The longest list a split PAGE may carry.
     */
    private function chunkSize(): int
    {
        return max(1, min($this->listCap(), $this->maxLimit));
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<mixed>
     */
    private function distinctValues(array $values): array
    {
        $seen = [];
        $distinct = [];

        foreach ($values as $value) {
            if (! is_scalar($value)) {
                $distinct[] = $value;

                continue;
            }

            $key = is_bool($value) ? 'bool:'.($value ? '1' : '0') : 'scalar:'.$value;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $distinct[] = $value;
        }

        return $distinct;
    }

    /**
     * A relation that did not fit in one page, raised instead of returned.
     */
    private function assertNothingWasTruncated(): void
    {
        if ($this->limit !== null || ! $this->lastHasMore()) {
            return;
        }

        $bound = $this->rowBound();

        if ($bound === null || $bound['rows'] !== null) {
            return;
        }

        throw new UnboundedQueryException(
            sprintf(
                'The relation loaded through "%s" has more rows than one page carries (%d), and the rest would have been dropped without a word. Load it one parent at a time with $parent->relation()->limit(%d)->get(), or query the "%s" resource directly with whereIn() on the parent keys and a limit you choose.',
                $bound['through'],
                $this->maxLimit,
                $this->maxLimit,
                $this->resource,
            ),
            'unbounded_query',
            0,
            null,
            $this->context(['builder_method' => 'get', 'relation' => $bound['through']]),
        );
    }

    /**
     * The limit a bounded query carries when the caller wrote none.
     */
    private function impliedLimit(): ?int
    {
        $bound = $this->rowBound();

        if ($bound === null) {
            return null;
        }

        return $bound['rows'] === null
            ? $this->maxLimit
            : max(1, min($bound['rows'], $this->maxLimit));
    }

    private function assertedLimit(): ?int
    {
        if ($this->limit === null) {
            return $this->impliedLimit();
        }

        $limit = (int) $this->limit;

        if ($limit > $this->maxLimit) {
            throw InvalidQueryException::limitTooHigh($limit, $this->maxLimit, $this->context());
        }

        return $limit;
    }

    /**
     * The clauses that never reach $wheres, and so never reach a translator.
     */
    private function assertSendableShape(): void
    {
        if (! empty($this->joins)) {
            throw UnsupportedQueryException::join('join', $this->firstJoinTable(), $this->context());
        }

        if (! empty($this->groups)) {
            throw UnsupportedQueryException::grouping('groupBy', $this->context());
        }

        if (! empty($this->havings)) {
            throw UnsupportedQueryException::grouping('having', $this->context());
        }

        if (! empty($this->unions)) {
            throw UnsupportedQueryException::method(
                'union()',
                'Two resources cannot be unioned in one request. Run each query and merge the collections locally.',
                $this->context(['builder_method' => 'union']),
            );
        }

        if (isset($this->groupLimit)) {
            throw UnsupportedQueryException::method(
                'A per-parent limit on an eager-loaded relation',
                sprintf('Laravel compiles "n per parent" into a window function, and the %s API has no equivalent: one limit caps the whole response, not each parent\'s share. Load the relation whole with with(\'relation\'), limit it one parent at a time ($model->relation()->limit(5)->get()), or query the related resource directly with whereIn() on the parent keys.', $this->resource),
                $this->context(['builder_method' => 'limit']),
            );
        }

        if (! empty($this->lock)) {
            throw UnsupportedQueryException::method(
                'lockForUpdate()',
                'A row lock only means something inside a transaction, and there is none here. Guard the write with if_match (a 409 stale_record beats a lost update) or move the whole step behind a domain action.',
                $this->context(['builder_method' => 'lock']),
            );
        }

        if (is_array($this->distinct)) {
            throw UnsupportedQueryException::method(
                'distinct($columns)',
                'The API takes distinct as a flag over the projection, not over named columns. Call distinct() with no arguments, or select() exactly the fields that should be distinct.',
                $this->context(['builder_method' => 'distinct']),
            );
        }
    }

    /**
     * @param  array<int, mixed>  $columns
     */
    private function aggregateField(array $columns): ?string
    {
        $fields = $this->fieldsFor($columns);

        if ($fields === []) {
            return null;
        }

        if (count($fields) > 1) {
            throw new InvalidQueryException(
                sprintf('An aggregate takes one field, %d given on "%s".', count($fields), $this->resource),
                'invalid_query',
                0,
                null,
                $this->context(),
            );
        }

        return $fields[0];
    }

    /**
     * Drop this resource's own table qualifier; refuse anyone else's.
     */
    private function unqualify(string $column): string
    {
        if (! str_contains($column, '.')) {
            return $column;
        }

        $position = strrpos($column, '.');
        $qualifier = trim(substr($column, 0, $position), '`"[] ');
        $field = trim(substr($column, $position + 1), '`"[] ');

        if ($qualifier === $this->tableName() || $qualifier === $this->resource || $qualifier === '') {
            return $field;
        }

        throw UnsupportedQueryException::join(
            'select',
            $qualifier,
            $this->context(['field' => $column]),
        );
    }

    /**
     * unqualify() without the refusal, for the two inspections that run BEFORE translation.
     */
    private function lenientField(string $column): ?string
    {
        if (! str_contains($column, '.')) {
            return $column;
        }

        $position = strrpos($column, '.');
        $qualifier = trim(substr($column, 0, $position), '`"[] ');

        if ($qualifier !== $this->tableName() && $qualifier !== $this->resource && $qualifier !== '') {
            return null;
        }

        return trim(substr($column, $position + 1), '`"[] ');
    }

    private function tableName(): string
    {
        return is_string($this->from) && $this->from !== '' ? $this->from : $this->resource;
    }

    private function firstJoinTable(): string
    {
        $join = ($this->joins ?? [])[0] ?? null;
        $table = is_object($join) ? ($join->table ?? null) : null;

        return is_string($table) ? $table : 'the joined table';
    }

    private function modelName(): string
    {
        return $this->modelClass !== '' ? $this->modelClass : $this->resource;
    }

    private function apiConnection(): ApiConnection
    {
        $connection = $this->connection;

        return $connection instanceof ApiConnection ? $connection : new ApiConnection();
    }

    private static function writeThroughModel(string $method, string $operation): UnsupportedQueryException
    {
        return UnsupportedQueryException::method(
            sprintf('%s() on the query builder', $method),
            sprintf('A remote write goes through the model so its events, casts and idempotency key travel with it: $model->save() sends a %s. The query builder has no record to attach any of that to.', $operation),
            ['builder_method' => $method],
        );
    }

    /**
     * @param  array<string, scalar|null>  $extra
     * @return array<string, scalar|null>
     */
    private function context(array $extra = []): array
    {
        return $extra + [
            'resource' => $this->resource,
            'model' => $this->modelClass !== '' ? $this->modelClass : null,
            'transport' => $this->transport->name(),
        ];
    }
}
