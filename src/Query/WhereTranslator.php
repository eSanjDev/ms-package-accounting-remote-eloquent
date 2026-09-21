<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Query;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Esanj\RemoteEloquent\Exceptions\UnsupportedQueryException;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Traversable;

/**
 * Laravel's internal $query->wheres, turned into the QuerySpec "where" list.
 */
final class WhereTranslator
{
    /**
     * SQL comparison operators that have a direct operator on the wire.
     */
    private const COMPARISONS = [
        '=' => 'eq',
        '<=>' => 'eq',
        '!=' => 'ne',
        '<>' => 'ne',
        '>' => 'gt',
        '>=' => 'gte',
        '<' => 'lt',
        '<=' => 'lte',
    ];

    /**
     * LIKE spellings, mapped to whether they negate.
     */
    private const LIKE_OPERATORS = [
        'like' => false,
        'like binary' => false,
        'not like' => true,
        'not like binary' => true,
    ];

    private const LIKE_ALTERNATIVE = "Only 'x%', '%x%' and '%x' can be sent, as starts_with, contains and ends_with. A wildcard in the middle, a single-character '_' wildcard, an escaped literal, or a pattern with no wildcard at all has no equivalent on the API and will not be guessed at: say what you mean with where('field', '=', ...) or one of the three patterns above.";

    public function __construct(
        private readonly string $resource,
        private readonly string $table,
    ) {
    }

    /**
     * @param  array<int, mixed>  $wheres  a Builder's $wheres, in order
     * @return list<Where>
     */
    public function translate(array $wheres): array
    {
        $translated = [];

        foreach ($wheres as $where) {
            $translated[] = $this->condition($this->normalizeClause($where));
        }

        return $translated;
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private function condition(array $where): Where
    {
        $type = strtolower((string) $where['type']);

        return match ($type) {
            'basic' => $this->basic($where),
            'in', 'inraw' => $this->inList($where, 'in'),
            'notin', 'notinraw' => $this->inList($where, 'not_in'),
            'null' => $this->nullCheck($where, 'null'),
            'notnull' => $this->nullCheck($where, 'not_null'),
            'between' => $this->between($where),
            'nested' => $this->nested($where),
            strtolower(ApiQueryBuilder::HAS_CLAUSE) => $this->hasCondition($where),
            'like' => $this->like($where),
            'date', 'year' => $this->datePeriod($where),
            'month', 'day', 'time' => throw UnsupportedQueryException::dateComponent(
                'where'.ucfirst($type),
                $this->context(),
            ),
            'exists', 'notexists' => $this->refuseExists($where),
            'column' => throw UnsupportedQueryException::whereColumn($this->context()),
            'betweencolumns' => throw UnsupportedQueryException::whereColumn(
                ['builder_method' => 'whereBetweenColumns'] + $this->context(),
            ),
            'valuebetween' => throw UnsupportedQueryException::whereColumn(
                ['builder_method' => 'whereValueBetween'] + $this->context(),
            ),
            'raw' => throw UnsupportedQueryException::rawExpression('whereRaw', $this->context()),
            'expression' => throw UnsupportedQueryException::rawExpression('where', $this->context()),
            'bitwise' => throw UnsupportedQueryException::method(
                'A bitwise where clause',
                sprintf(
                    'Bitwise operators reach past the fields the "%s" resource publishes into how it stores them. If a flag matters to the caller, the resource has to expose it as a field first.',
                    $this->resource,
                ),
                $this->context(),
            ),
            'sub' => throw UnsupportedQueryException::subquery('where', $this->context()),
            'rowvalues' => throw UnsupportedQueryException::subquery('whereRowValues', $this->context()),
            'jsonboolean' => throw UnsupportedQueryException::jsonWhere('where', $this->context()),
            'jsoncontains' => throw UnsupportedQueryException::jsonWhere('whereJsonContains', $this->context()),
            'jsonoverlaps' => throw UnsupportedQueryException::jsonWhere('whereJsonOverlaps', $this->context()),
            'jsoncontainskey' => throw UnsupportedQueryException::jsonWhere('whereJsonContainsKey', $this->context()),
            'jsonlength' => throw UnsupportedQueryException::jsonWhere('whereJsonLength', $this->context()),
            'fulltext' => throw UnsupportedQueryException::fullText($this->context()),
            default => throw UnsupportedQueryException::method(
                sprintf('A "%s" where clause', (string) $where['type']),
                sprintf(
                    'This package translates each condition explicitly and has no rule for that one, so it will not send an approximation. Express the filter with where(), whereIn(), whereNull(), whereBetween() or whereHas() on the fields the "%s" schema publishes.',
                    $this->resource,
                ),
                ['where_type' => (string) $where['type']] + $this->context(),
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private function basic(array $where): Where
    {
        [$boolean, $not] = $this->splitBoolean($where);

        $field = $this->field($where['column'] ?? null, 'where');
        $operator = $this->operator($where['operator'] ?? '=');

        if (array_key_exists($operator, self::LIKE_OPERATORS)) {
            [$likeOperator, $value] = $this->likePattern($field, $where['value'] ?? null);

            return Where::condition(
                $field,
                $likeOperator,
                $value,
                $boolean,
                $not !== self::LIKE_OPERATORS[$operator],
            );
        }

        if (! array_key_exists($operator, self::COMPARISONS)) {
            throw UnsupportedQueryException::method(
                sprintf("where('%s', '%s', ...)", $field, $operator),
                sprintf(
                    'The API compares with = != < <= > >= and LIKE. "%s" is a database operator with no equivalent it can be given, and sending the nearest one would answer a different question.',
                    $operator,
                ),
                ['field' => $field, 'operator' => $operator] + $this->context(),
            );
        }

        return Where::condition(
            $field,
            self::COMPARISONS[$operator],
            $this->value($where['value'] ?? null, 'where', $field),
            $boolean,
            $not,
        );
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private function like(array $where): Where
    {
        [$boolean, $not] = $this->splitBoolean($where);

        $field = $this->field($where['column'] ?? null, 'whereLike');

        if (($where['caseSensitive'] ?? false) === true) {
            throw UnsupportedQueryException::method(
                sprintf("whereLike('%s', ..., caseSensitive: true)", $field),
                'The API decides how text is matched and publishes no case-sensitivity switch, so a case-sensitive match cannot be promised here. Drop the flag to send starts_with / contains / ends_with, or compare the exact value with where().',
                ['field' => $field] + $this->context(),
            );
        }

        [$likeOperator, $value] = $this->likePattern($field, $where['value'] ?? null);

        return Where::condition(
            $field,
            $likeOperator,
            $value,
            $boolean,
            $not !== (($where['not'] ?? false) === true),
        );
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private function inList(array $where, string $operator): Where
    {
        [$boolean, $not] = $this->splitBoolean($where);

        $method = $operator === 'in' ? 'whereIn' : 'whereNotIn';
        $field = $this->field($where['column'] ?? null, $method);

        return Where::condition(
            $field,
            $operator,
            $this->valueList($where['values'] ?? null, $method, $field),
            $boolean,
            $not,
        );
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private function nullCheck(array $where, string $operator): Where
    {
        [$boolean, $not] = $this->splitBoolean($where);

        return Where::condition(
            $this->field($where['column'] ?? null, $operator === 'null' ? 'whereNull' : 'whereNotNull'),
            $operator,
            null,
            $boolean,
            $not,
        );
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private function between(array $where): Where
    {
        [$boolean, $not] = $this->splitBoolean($where);

        $negated = ($where['not'] ?? false) === true;
        $method = $negated ? 'whereNotBetween' : 'whereBetween';
        $field = $this->field($where['column'] ?? null, $method);

        return Where::condition(
            $field,
            $negated ? 'not_between' : 'between',
            $this->valueList($where['values'] ?? null, $method, $field),
            $boolean,
            $not,
        );
    }

    /**
     * A closure handed to where() — and the wrapper Eloquent puts around a scope whose conditions contain an "or".
     *
     * @param  array<string, mixed>  $where
     */
    private function nested(array $where): Where
    {
        [$boolean, $not] = $this->splitBoolean($where);

        return Where::group($this->translate($this->wheresOf($where['query'] ?? null)), $boolean, $not);
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private function hasCondition(array $where): Where
    {
        $condition = $where['remote'] ?? null;

        if ($condition instanceof Where) {
            return $condition;
        }

        throw UnsupportedQueryException::method(
            'A relation condition with nothing behind it',
            sprintf(
                'Something wrote a "%s" clause into the query\'s $wheres without the translated condition it is supposed to carry. Write the relation condition with whereHas() or has() so the "%s" resource is asked a question it can answer.',
                ApiQueryBuilder::HAS_CLAUSE,
                $this->resource,
            ),
            ['where_type' => ApiQueryBuilder::HAS_CLAUSE] + $this->context(),
        );
    }

    /**
     * whereDate() and whereYear() with '=', as a half-open range.
     *
     * @param  array<string, mixed>  $where
     */
    private function datePeriod(array $where): Where
    {
        [$boolean, $not] = $this->splitBoolean($where);

        $isDate = strtolower((string) $where['type']) === 'date';
        $method = $isDate ? 'whereDate' : 'whereYear';
        $field = $this->field($where['column'] ?? null, $method);
        $operator = $this->operator($where['operator'] ?? '=');

        if ($operator !== '=' && $operator !== '<=>') {
            throw UnsupportedQueryException::method(
                sprintf("%s('%s', '%s', ...)", $method, $field, $operator),
                sprintf(
                    'Only %s() with = survives the trip, as a half-open range. Write the range you mean and it goes out unchanged: where(\'%s\', \'>=\', $from)->where(\'%s\', \'<\', $until).',
                    $method,
                    $field,
                    $field,
                ),
                ['field' => $field, 'operator' => $operator] + $this->context(),
            );
        }

        [$from, $until] = $isDate
            ? $this->dayBounds($field, $where['value'] ?? null)
            : $this->yearBounds($field, $where['value'] ?? null);

        return Where::group(
            [
                Where::condition($field, 'gte', $from),
                Where::condition($field, 'lt', $until),
            ],
            $boolean,
            $not,
        );
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private function refuseExists(array $where): never
    {
        $method = strtolower((string) $where['type']) === 'notexists' ? 'whereNotExists' : 'whereExists';
        $table = $this->tableOf($where['query'] ?? null);

        if ($table !== null && ! $this->qualifierMatches($table)) {
            throw UnsupportedQueryException::localRelationConstraint(
                $method,
                $table,
                $this->context(),
            );
        }

        throw UnsupportedQueryException::whereExists(
            ['builder_method' => $method] + $this->context(),
        );
    }

    /**
     * Split Laravel's one boolean string into the two things the wire carries.
     *
     * @param  array<string, mixed>  $where
     * @return array{0: string, 1: bool}
     */
    private function splitBoolean(array $where): array
    {
        $boolean = strtolower(trim((string) ($where['boolean'] ?? 'and')));

        if (str_ends_with($boolean, ' not')) {
            return [trim(substr($boolean, 0, -4)), true];
        }

        return [$boolean, false];
    }

    private function operator(mixed $operator): string
    {
        if ($operator instanceof ExpressionContract) {
            throw UnsupportedQueryException::rawExpression('where', $this->context());
        }

        return strtolower(trim((string) $operator));
    }

    /**
     * A column name, stripped of this resource's own table qualifier.
     */
    private function field(mixed $column, string $method): string
    {
        if ($column instanceof ExpressionContract) {
            throw UnsupportedQueryException::rawExpression($method, $this->context());
        }

        if (! is_string($column)) {
            throw UnsupportedQueryException::method(
                sprintf('%s() with a %s column', $method, get_debug_type($column)),
                'A remote condition filters a field the schema publishes, named as a plain string.',
                $this->context(),
            );
        }

        $name = trim($column);

        if ($name === '') {
            throw UnsupportedQueryException::method(
                sprintf('%s() with no column', $method),
                'A remote condition needs the name of a field the schema publishes.',
                $this->context(),
            );
        }

        if (str_contains($name, '->')) {
            throw UnsupportedQueryException::jsonWhere(
                $method,
                ['field' => $name] + $this->context(),
            );
        }

        if (! str_contains($name, '.')) {
            return $name;
        }

        $segments = explode('.', $name);
        $unqualified = (string) array_pop($segments);

        if (! $this->qualifierMatches(implode('.', $segments))) {
            throw UnsupportedQueryException::method(
                sprintf("%s('%s', ...)", $method, $name),
                sprintf(
                    'The "%s" resource exposes its own fields and nothing else, so [%s] cannot be filtered on — the account service cannot see that table. Resolve it locally first and pass the result to whereIn().',
                    $this->resource,
                    $name,
                ),
                ['field' => $name] + $this->context(),
            );
        }

        return $unqualified;
    }

    /**
     * Is this qualifier the resource's own table?
     */
    private function qualifierMatches(string $qualifier): bool
    {
        $qualifier = strtolower(trim($qualifier, " \t\n\r\0\x0B`\"[]"));

        if ($qualifier === '') {
            return false;
        }

        foreach ([$this->table, $this->resource] as $candidate) {
            $candidate = strtolower(trim($candidate));

            if ($candidate === '') {
                continue;
            }

            $parts = preg_split('/\s+as\s+/i', $candidate) ?: [$candidate];

            foreach ($parts as $part) {
                $part = trim($part);

                if ($part === $qualifier) {
                    return true;
                }

                $tail = strrchr($part, '.');

                if ($tail !== false && substr($tail, 1) === $qualifier) {
                    return true;
                }
            }
        }

        return false;
    }

    private function value(mixed $value, string $method, string $field): mixed
    {
        if ($value instanceof ExpressionContract) {
            throw UnsupportedQueryException::rawExpression(
                $method,
                ['field' => $field] + $this->context(),
            );
        }

        if ($value instanceof EloquentBuilder || $value instanceof QueryBuilder) {
            throw UnsupportedQueryException::subquery(
                $method,
                ['field' => $field] + $this->context(),
            );
        }

        return $value;
    }

    /**
     * The value list behind whereIn() / whereBetween(), normalised to a plain array.
     *
     * @return array<int, mixed>
     */
    private function valueList(mixed $values, string $method, string $field): array
    {
        if ($values instanceof Arrayable) {
            $values = $values->toArray();
        } elseif ($values instanceof Traversable) {
            $values = iterator_to_array($values, false);
        }

        if (! is_array($values)) {
            throw UnsupportedQueryException::method(
                sprintf("%s('%s', ...) with a %s", $method, $field, get_debug_type($values)),
                'Pass a plain array or a Collection of values. A query builder or a closure is a sub-select, which one call cannot carry.',
                ['field' => $field] + $this->context(),
            );
        }

        foreach ($values as $value) {
            $this->value($value, $method, $field);
        }

        return array_values($values);
    }

    /**
     * ['2024-03-05', '2024-03-06'] for whereDate('col', '2024-03-05').
     *
     * @return array{0: string, 1: string}
     */
    private function dayBounds(string $field, mixed $value): array
    {
        $value = $this->value($value, 'whereDate', $field);

        if ($value instanceof DateTimeInterface) {
            $value = $value->format('Y-m-d');
        }

        $date = is_string($value) ? trim($value) : '';

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw UnsupportedQueryException::method(
                sprintf("whereDate('%s', ...) with [%s]", $field, is_scalar($value) ? (string) $value : get_debug_type($value)),
                'whereDate() is sent as the range covering one calendar day, so it needs exactly one day: a Y-m-d string or a DateTimeInterface. A value carrying a time would compare against something no row can equal.',
                ['field' => $field] + $this->context(),
            );
        }

        [$year, $month, $day] = array_map(intval(...), explode('-', $date));

        if (! checkdate($month, $day, $year)) {
            throw UnsupportedQueryException::method(
                sprintf("whereDate('%s', '%s')", $field, $date),
                'That is not a date on the calendar, so no range can be built from it.',
                ['field' => $field] + $this->context(),
            );
        }

        $start = new DateTimeImmutable($date.' 00:00:00', new DateTimeZone('UTC'));

        return [$date, $start->modify('+1 day')->format('Y-m-d')];
    }

    /**
     * ['2024-01-01', '2025-01-01'] for whereYear('col', 2024).
     *
     * @return array{0: string, 1: string}
     */
    private function yearBounds(string $field, mixed $value): array
    {
        $value = $this->value($value, 'whereYear', $field);

        if ($value instanceof DateTimeInterface) {
            $value = $value->format('Y');
        }

        $year = is_int($value) ? (string) $value : (is_string($value) ? trim($value) : '');

        if (preg_match('/^\d{1,4}$/', $year) !== 1 || (int) $year < 1) {
            throw UnsupportedQueryException::method(
                sprintf("whereYear('%s', ...) with [%s]", $field, is_scalar($value) ? (string) $value : get_debug_type($value)),
                'whereYear() is sent as the range covering one calendar year, so it needs a year: an integer, a four-digit string or a DateTimeInterface.',
                ['field' => $field] + $this->context(),
            );
        }

        return [
            sprintf('%04d-01-01', (int) $year),
            sprintf('%04d-01-01', (int) $year + 1),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function wheresOf(mixed $query): array
    {
        if ($query instanceof EloquentBuilder) {
            $query = $query->getQuery();
        }

        if ($query instanceof QueryBuilder) {
            return $query->wheres;
        }

        throw UnsupportedQueryException::method(
            'A nested where group with no query behind it',
            sprintf(
                'The closure passed to where() did not produce conditions this package can read. Write the conditions directly against the "%s" fields.',
                $this->resource,
            ),
            ['builder_method' => 'where'] + $this->context(),
        );
    }

    private function tableOf(mixed $query): ?string
    {
        if ($query instanceof EloquentBuilder) {
            $query = $query->getQuery();
        }

        if (! $query instanceof QueryBuilder || ! is_string($query->from)) {
            return null;
        }

        $from = trim($query->from);

        return $from === '' ? null : $from;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function likePattern(string $field, mixed $value): array
    {
        $value = $this->value($value, 'where', $field);

        if (! is_string($value)) {
            throw $this->badLikePattern($field, get_debug_type($value));
        }

        if (str_contains($value, '\\')) {
            throw $this->badLikePattern($field, $value);
        }

        $leading = str_starts_with($value, '%');
        $trailing = strlen($value) > ($leading ? 1 : 0) && str_ends_with($value, '%');

        if (! $leading && ! $trailing) {
            throw $this->badLikePattern($field, $value);
        }

        $inner = substr(
            $value,
            $leading ? 1 : 0,
            strlen($value) - ($leading ? 1 : 0) - ($trailing ? 1 : 0),
        );

        // An empty match is every row, and a wildcard left in the middle is a pattern with no operator behind it.
        if ($inner === '' || str_contains($inner, '%')) {
            throw $this->badLikePattern($field, $value);
        }

        if (str_contains($inner, '_')) {
            throw $this->badLikePattern($field, $value);
        }

        $likeOperator = match (true) {
            $leading && $trailing => 'contains',
            $trailing => 'starts_with',
            default => 'ends_with',
        };

        return [$likeOperator, $inner];
    }

    private function badLikePattern(string $field, string $pattern): UnsupportedQueryException
    {
        return UnsupportedQueryException::method(
            sprintf("where('%s', 'like', '%s')", $field, $pattern),
            self::LIKE_ALTERNATIVE,
            ['field' => $field] + $this->context(),
        );
    }

    /**
     * @param  array<string, scalar|null>  $extra
     * @return array<string, scalar|null>
     */
    private function context(array $extra = []): array
    {
        return $extra + ['resource' => $this->resource];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeClause(mixed $where): array
    {
        if (is_array($where) && isset($where['type']) && is_string($where['type'])) {
            return $where;
        }

        throw UnsupportedQueryException::method(
            'An unreadable where clause',
            sprintf(
                'Something wrote directly into the query\'s $wheres. Build the conditions with where(), whereIn() and friends so they can be translated for the "%s" resource.',
                $this->resource,
            ),
            $this->context(),
        );
    }
}
