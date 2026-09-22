<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Schema;

use Esanj\RemoteEloquent\Exceptions\InvalidQueryException;
use Esanj\RemoteEloquent\Query\QuerySpec;

/**
 * The last thing a QuerySpec passes before it is sent, and the least important.
 */
final class SchemaValidator
{
    /**
     * Used only for a limit the resource itself does not publish.
     */
    private const DEFAULT_LIMITS = [
        'max_limit' => 100,
        'max_offset' => 10000,
        'max_fields' => 50,
        'max_orders' => 5,
        'max_includes' => 5,
        'max_counts' => 5,
        'max_conditions' => 30,
        'max_depth' => 3,
        'max_in' => 500,
    ];

    private const LIST_OPERATORS = ['in', 'not_in'];

    private const NULL_OPERATORS = ['null', 'not_null'];

    /** @var array<string, true> */
    private array $refreshed = [];

    public function __construct(private readonly SchemaRepository $schemas)
    {
    }

    /**
     * Every field the schema publishes to this application, which is exactly what it may read.
     *
     * @return list<string>
     */
    public function readableFields(string $resource): array
    {
        return $this->schemas->for($resource)?->fieldNames() ?? [];
    }

    /**
     * Check a spec against the cached schema before it is sent.
     *
     * @throws InvalidQueryException when the schema says the request is certain to be refused
     */
    public function assertQueryable(string $resource, QuerySpec $spec): void
    {
        $schema = $this->schemas->for($resource);

        if ($schema === null) {
            return;
        }

        $body = $spec->toArray();

        $this->assertFields($schema, $this->listOf($body, 'fields'));
        $this->assertOrder($schema, is_array($body['order'] ?? null) ? $body['order'] : []);
        $this->assertIncludes($schema, $this->listOf($body, 'include'));
        $this->assertCounts($schema, $this->listOf($body, 'counts'));
        $this->assertTrashed($schema, $body['trashed'] ?? null);
        $this->assertPage($schema, $body);

        $conditions = 0;

        $this->assertWhere(
            $schema,
            is_array($body['where'] ?? null) ? $body['where'] : [],
            0,
            $schema,
            $conditions,
        );

        $maxConditions = $schema->limit('max_conditions', self::DEFAULT_LIMITS['max_conditions']);

        if ($conditions > $maxConditions) {
            throw $this->refuse(
                sprintf(
                    'A query on "%s" carries at most %d conditions, %d given. Narrow the query, or filter the remainder locally.',
                    $this->label($schema),
                    $maxConditions,
                    $conditions,
                ),
                'query_too_complex',
                ['resource' => $this->label($schema), 'condition_count' => $conditions],
            );
        }
    }

    /**
     * @param  list<string>  $fields
     */
    private function assertFields(ResourceSchema $schema, array $fields): void
    {
        $max = $schema->limit('max_fields', self::DEFAULT_LIMITS['max_fields']);

        if (count($fields) > $max) {
            throw $this->refuse(
                sprintf(
                    'A query on "%s" selects at most %d fields, %d given.',
                    $this->label($schema),
                    $max,
                    count($fields),
                ),
                'invalid_query',
                ['resource' => $this->label($schema), 'field_count' => count($fields)],
            );
        }

        foreach ($fields as $field) {
            $this->fieldOrFail($schema, $field);
        }
    }

    /**
     * @param  array<int, mixed>  $order
     */
    private function assertOrder(ResourceSchema $schema, array $order): void
    {
        $max = $schema->limit('max_orders', self::DEFAULT_LIMITS['max_orders']);

        if (count($order) > $max) {
            throw $this->refuse(
                sprintf(
                    'A query on "%s" orders by at most %d fields, %d given.',
                    $this->label($schema),
                    $max,
                    count($order),
                ),
                'invalid_query',
                ['resource' => $this->label($schema), 'order_count' => count($order)],
            );
        }

        foreach ($order as $clause) {
            if (! is_array($clause) || ! isset($clause['field']) || ! is_string($clause['field'])) {
                continue;
            }

            $field = $this->fieldOrFail($schema, $clause['field']);

            if (! $field->isSortable()) {
                throw $this->refuse(
                    sprintf(
                        '"%s" is not sortable on "%s". Sortable fields: %s.',
                        $clause['field'],
                        $this->label($schema),
                        $this->sortableFields($schema),
                    ),
                    'invalid_query',
                    ['resource' => $this->label($schema), 'field' => $clause['field']],
                );
            }
        }
    }

    /**
     * @param  list<string>  $includes
     */
    private function assertIncludes(ResourceSchema $schema, array $includes): void
    {
        $max = $schema->limit('max_includes', self::DEFAULT_LIMITS['max_includes']);

        if (count($includes) > $max) {
            throw $this->refuse(
                sprintf(
                    'A query on "%s" includes at most %d relations, %d given.',
                    $this->label($schema),
                    $max,
                    count($includes),
                ),
                'invalid_query',
                ['resource' => $this->label($schema), 'include_count' => count($includes)],
            );
        }

        if (! $schema->publishesIncludes()) {
            return;
        }

        foreach ($includes as $include) {
            if (! in_array($include, $schema->includes(), true)) {
                throw $this->refuse(
                    sprintf(
                        '"%s" is not an include of "%s". Available includes: %s.',
                        $include,
                        $this->label($schema),
                        implode(', ', $schema->includes()),
                    ),
                    'invalid_query',
                    ['resource' => $this->label($schema), 'include' => $include],
                );
            }
        }
    }

    /**
     * @param  list<string>  $counts
     */
    private function assertCounts(ResourceSchema $schema, array $counts): void
    {
        $max = $schema->limit('max_counts', self::DEFAULT_LIMITS['max_counts']);

        if (count($counts) > $max) {
            throw $this->refuse(
                sprintf(
                    'A query on "%s" counts at most %d relations, %d given.',
                    $this->label($schema),
                    $max,
                    count($counts),
                ),
                'invalid_query',
                ['resource' => $this->label($schema), 'count_count' => count($counts)],
            );
        }

        if (! $schema->publishesRelations()) {
            return;
        }

        foreach ($counts as $relation) {
            if (! $schema->supportsCount($relation)) {
                throw $this->refuse(
                    sprintf(
                        '"%s" cannot be counted on "%s".%s',
                        $relation,
                        $this->label($schema),
                        $this->countableRelations($schema),
                    ),
                    'invalid_query',
                    ['resource' => $this->label($schema), 'relation' => $relation],
                );
            }
        }
    }

    private function assertTrashed(ResourceSchema $schema, mixed $trashed): void
    {
        if ($trashed === null || $schema->softDeletes()) {
            return;
        }

        throw $this->refuse(
            sprintf(
                '"%s" does not soft delete, so trashed rows cannot be asked for. Drop withTrashed() / onlyTrashed().',
                $this->label($schema),
            ),
            'invalid_query',
            ['resource' => $this->label($schema)],
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function assertPage(ResourceSchema $schema, array $body): void
    {
        $maxLimit = $schema->limit('max_limit', self::DEFAULT_LIMITS['max_limit']);

        if (isset($body['limit']) && is_int($body['limit']) && $body['limit'] > $maxLimit) {
            throw $this->refuse(
                sprintf(
                    'A query on "%s" reads at most %d rows at a time, %d requested. Page through them instead.',
                    $this->label($schema),
                    $maxLimit,
                    $body['limit'],
                ),
                'invalid_query',
                ['resource' => $this->label($schema), 'requested_limit' => $body['limit'], 'max_limit' => $maxLimit],
            );
        }

        $maxOffset = $schema->limit('max_offset', self::DEFAULT_LIMITS['max_offset']);

        if (isset($body['offset']) && is_int($body['offset']) && $body['offset'] > $maxOffset) {
            throw $this->refuse(
                sprintf(
                    'A query on "%s" offsets at most %d rows, %d requested. Use chunkById() for anything deeper.',
                    $this->label($schema),
                    $maxOffset,
                    $body['offset'],
                ),
                'invalid_query',
                ['resource' => $this->label($schema), 'requested_offset' => $body['offset'], 'max_offset' => $maxOffset],
            );
        }
    }

    /**
     * @param  array<int, mixed>  $conditions
     */
    private function assertWhere(
        ?ResourceSchema $schema,
        array $conditions,
        int $depth,
        ResourceSchema $root,
        int &$count,
    ): void {
        $maxDepth = $root->limit('max_depth', self::DEFAULT_LIMITS['max_depth']);

        if ($depth > $maxDepth) {
            throw $this->refuse(
                sprintf(
                    'A query on "%s" nests conditions at most %d level(s) deep. Flatten the closures, or split the query.',
                    $this->label($root),
                    $maxDepth,
                ),
                'query_too_complex',
                ['resource' => $this->label($root), 'depth' => $depth],
            );
        }

        foreach ($conditions as $condition) {
            if (! is_array($condition)) {
                continue;
            }

            if (isset($condition['group']) && is_array($condition['group'])) {
                $this->assertWhere($schema, $condition['group'], $depth + 1, $root, $count);

                continue;
            }

            if (isset($condition['has']) && is_array($condition['has'])) {
                $count++;
                $this->assertHas($schema, $condition['has'], $depth + 1, $root, $count);

                continue;
            }

            $count++;

            if ($schema === null || ! isset($condition['field']) || ! is_string($condition['field'])) {
                continue;
            }

            $field = $this->fieldOrFail($schema, $condition['field']);
            $operator = isset($condition['op']) && is_string($condition['op']) ? $condition['op'] : '';

            if (! $field->allowsOperator($operator) && ! $this->isNullCheckOnNullableField($field, $operator)) {
                throw $this->refuse(
                    sprintf(
                        '"%s" does not support the "%s" operator on "%s".%s',
                        $condition['field'],
                        $operator,
                        $this->label($schema),
                        $field->operators() === []
                            ? ' That field cannot be filtered on.'
                            : ' It accepts: '.implode(', ', $field->operators()).'.',
                    ),
                    'operator_not_allowed',
                    ['resource' => $this->label($schema), 'field' => $condition['field'], 'operator' => $operator],
                );
            }

            $this->assertListSize($root, $condition, $operator, $condition['field']);
        }
    }

    /**
     * @param  array<string, mixed>  $has
     */
    private function assertHas(
        ?ResourceSchema $schema,
        array $has,
        int $depth,
        ResourceSchema $root,
        int &$count,
    ): void {
        $relation = isset($has['relation']) && is_string($has['relation']) ? $has['relation'] : '';
        $nested = is_array($has['where'] ?? null) ? $has['where'] : [];

        if ($schema === null || $relation === '' || ! $schema->publishesRelations()) {
            $this->assertWhere(null, $nested, $depth, $root, $count);

            return;
        }

        if (! $schema->supportsHas($relation)) {
            throw $this->refuse(
                sprintf(
                    '"%s" cannot be used in a relation condition on "%s".%s',
                    $relation,
                    $this->label($schema),
                    $this->hasRelations($schema),
                ),
                'invalid_query',
                ['resource' => $this->label($schema), 'relation' => $relation],
            );
        }

        $related = $schema->relationResource($relation);

        $this->assertWhere(
            $related === null ? null : $this->schemas->for($related),
            $nested,
            $depth,
            $root,
            $count,
        );
    }

    private function isNullCheckOnNullableField(FieldDefinition $field, string $operator): bool
    {
        return $field->isNullable() && in_array($operator, self::NULL_OPERATORS, true);
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    private function assertListSize(ResourceSchema $root, array $condition, string $operator, string $field): void
    {
        if (! in_array($operator, self::LIST_OPERATORS, true) || ! is_array($condition['value'] ?? null)) {
            return;
        }

        $max = $root->limit('max_in', self::DEFAULT_LIMITS['max_in']);

        if (count($condition['value']) > $max) {
            throw $this->refuse(
                sprintf(
                    '"%s" was given %d values for "%s"; "%s" takes at most %d. Split the list and merge the results.',
                    $field,
                    count($condition['value']),
                    $operator,
                    $this->label($root),
                    $max,
                ),
                'invalid_query',
                ['resource' => $this->label($root), 'field' => $field, 'value_count' => count($condition['value'])],
            );
        }
    }

    private function fieldOrFail(ResourceSchema $schema, string $name): FieldDefinition
    {
        $field = $schema->field($name);

        if ($field !== null) {
            return $field;
        }

        // The schema is per caller: a permission granted since it was cached publishes the field now.
        if ($schema->name() !== '' && ! isset($this->refreshed[$schema->name()])) {
            $this->refreshed[$schema->name()] = true;
            $this->schemas->forget($schema->name());

            $field = $this->schemas->for($schema->name())?->field($name);

            if ($field !== null) {
                return $field;
            }
        }

        $suggestion = $this->suggest($name, $schema->fieldNames());

        throw $this->refuse(
            sprintf(
                '"%s" is not a field of "%s".%s',
                $name,
                $this->label($schema),
                $suggestion === '' ? '' : sprintf(' Did you mean %s?', $suggestion),
            ),
            'unknown_field',
            ['resource' => $this->label($schema), 'field' => $name],
        );
    }

    /**
     * The closest field names to one that does not exist.
     *
     * @param  list<string>  $candidates
     */
    private function suggest(string $name, array $candidates): string
    {
        $needle = strtolower($name);

        if ($needle === '' || strlen($needle) > 255) {
            return '';
        }

        $threshold = max(2, intdiv(strlen($needle), 3));
        $scored = [];

        foreach ($candidates as $candidate) {
            $haystack = strtolower($candidate);

            if ($haystack === $needle || strlen($haystack) > 255) {
                continue;
            }

            if (str_contains($haystack, $needle) || str_contains($needle, $haystack)) {
                $scored[$candidate] = -1;

                continue;
            }

            $distance = levenshtein($needle, $haystack);

            if ($distance <= $threshold) {
                $scored[$candidate] = $distance;
            }
        }

        if ($scored === []) {
            return '';
        }

        asort($scored);

        return implode(' / ', array_slice(array_keys($scored), 0, 3));
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    private function refuse(string $message, string $code, array $context = []): InvalidQueryException
    {
        return new InvalidQueryException($message, $code, 0, null, $context);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<string>
     */
    private function listOf(array $body, string $key): array
    {
        $values = $body[$key] ?? [];

        if (! is_array($values)) {
            return [];
        }

        $list = [];

        foreach ($values as $value) {
            if (is_string($value)) {
                $list[] = $value;
            }
        }

        return $list;
    }

    private function sortableFields(ResourceSchema $schema): string
    {
        $sortable = [];

        foreach ($schema->fields() as $name => $field) {
            if ($field->isSortable()) {
                $sortable[] = $name;
            }
        }

        return $sortable === [] ? 'none' : implode(', ', $sortable);
    }

    private function countableRelations(ResourceSchema $schema): string
    {
        $countable = [];

        foreach (array_keys($schema->relations()) as $relation) {
            if ($schema->supportsCount($relation)) {
                $countable[] = $relation;
            }
        }

        return $countable === [] ? '' : ' Countable relations: '.implode(', ', $countable).'.';
    }

    private function hasRelations(ResourceSchema $schema): string
    {
        $available = [];

        foreach (array_keys($schema->relations()) as $relation) {
            if ($schema->supportsHas($relation)) {
                $available[] = $relation;
            }
        }

        return $available === [] ? '' : ' Available relations: '.implode(', ', $available).'.';
    }

    private function label(ResourceSchema $schema): string
    {
        return $schema->name() === '' ? 'this resource' : $schema->name();
    }
}