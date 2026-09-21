<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Query;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Esanj\RemoteEloquent\Exceptions\InvalidQueryException;

/**
 * One condition inside a QuerySpec, in one of the three shapes the server reads:
 */
final class Where
{
    /**
     * The whole operator vocabulary.
     */
    public const OPERATORS = [
        'eq', 'ne', 'gt', 'gte', 'lt', 'lte',
        'in', 'not_in', 'between', 'not_between',
        'null', 'not_null',
        'starts_with', 'contains', 'ends_with',
    ];

    /**
     * The longest list an "in" / "not_in" may carry, per the API contract.
     */
    public const MAX_IN = 500;

    private const BOOLEANS = ['and', 'or'];

    private const VALUELESS_OPERATORS = ['null', 'not_null'];

    private const LIST_OPERATORS = ['in', 'not_in'];

    private const RANGE_OPERATORS = ['between', 'not_between'];

    private const STRING_OPERATORS = ['starts_with', 'contains', 'ends_with'];

    private const HAS_OPERATORS = ['>=', '>', '<=', '<', '=', '!='];

    /**
     * @param  list<self>  $group
     * @param  array{relation: string, where: list<self>, operator: string, count: int}|null  $has
     */
    private function __construct(
        private readonly string $boolean,
        private readonly bool $not,
        private readonly ?string $field,
        private readonly ?string $op,
        private readonly mixed $value,
        private readonly bool $carriesValue,
        private readonly array $group,
        private readonly ?array $has,
    ) {
    }

    /**
     * A plain field comparison.
     */
    public static function condition(
        string $field,
        string $op,
        mixed $value = null,
        string $boolean = 'and',
        bool $not = false,
    ): self {
        $field = trim($field);

        if ($field === '') {
            throw new InvalidQueryException('A remote condition needs a field name.', 'invalid_query');
        }

        $operator = strtolower(trim($op));

        if (! in_array($operator, self::OPERATORS, true)) {
            throw new InvalidQueryException(
                sprintf(
                    'Operator "%s" is not part of the remote query contract. Supported operators: %s.',
                    $op,
                    implode(', ', self::OPERATORS),
                ),
                'operator_not_allowed',
            );
        }

        $carriesValue = ! in_array($operator, self::VALUELESS_OPERATORS, true);

        if (! $carriesValue && $value !== null) {
            throw new InvalidQueryException(
                sprintf(
                    'Operator "%s" on "%s" carries no value, but one was given. Drop the value, or compare with an operator that takes one.',
                    $operator,
                    $field,
                ),
                'invalid_query',
            );
        }

        return new self(
            self::normalizeBoolean($boolean),
            $not,
            $field,
            $operator,
            $carriesValue ? self::prepareValue($field, $operator, $value) : null,
            $carriesValue,
            [],
            null,
        );
    }

    /**
     * A nested group, which is one closure handed to where() / orWhere().
     *
     * @param  array<int, self>  $conditions
     */
    public static function group(array $conditions, string $boolean = 'and', bool $not = false): self
    {
        $nested = self::normalizeNested($conditions, 'A where group');

        if ($nested === []) {
            throw new InvalidQueryException(
                'A where group cannot be empty. An empty group matches every row, so sending one would return rows the query never asked for.',
                'invalid_query',
            );
        }

        return new self(self::normalizeBoolean($boolean), $not, null, null, null, false, $nested, null);
    }

    /**
     * A relation-existence condition, against a relation the REMOTE resource publishes.
     *
     * @param  array<int, self>  $conditions
     */
    public static function has(
        string $relation,
        array $conditions = [],
        string $operator = '>=',
        int $count = 1,
        string $boolean = 'and',
        bool $not = false,
    ): self {
        $relation = trim($relation);

        if ($relation === '') {
            throw new InvalidQueryException('A relation condition needs a relation name.', 'invalid_query');
        }

        if (! in_array($operator, self::HAS_OPERATORS, true)) {
            throw new InvalidQueryException(
                sprintf(
                    'Relation condition "%s" got the count operator "%s". Supported operators: %s.',
                    $relation,
                    $operator,
                    implode(', ', self::HAS_OPERATORS),
                ),
                'invalid_query',
            );
        }

        if ($count < 0) {
            throw new InvalidQueryException(
                sprintf('Relation condition "%s" got a negative count (%d).', $relation, $count),
                'invalid_query',
            );
        }

        return new self(
            self::normalizeBoolean($boolean),
            $not,
            null,
            null,
            null,
            false,
            [],
            [
                'relation' => $relation,
                'where' => self::normalizeNested($conditions, sprintf('The relation condition "%s"', $relation)),
                'operator' => $operator,
                'count' => $count,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'boolean' => $this->boolean,
            'not' => $this->not,
        ];

        if ($this->group !== []) {
            $payload['group'] = array_map(static fn (self $where): array => $where->toArray(), $this->group);

            return $payload;
        }

        if ($this->has !== null) {
            $payload['has'] = [
                'relation' => $this->has['relation'],
                'where' => array_map(static fn (self $where): array => $where->toArray(), $this->has['where']),
                'operator' => $this->has['operator'],
                'count' => $this->has['count'],
            ];

            return $payload;
        }

        $payload['field'] = $this->field;
        $payload['op'] = $this->op;

        if ($this->carriesValue) {
            $payload['value'] = $this->value;
        }

        return $payload;
    }

    private static function normalizeBoolean(string $boolean): string
    {
        $normalized = strtolower(trim($boolean));

        if (! in_array($normalized, self::BOOLEANS, true)) {
            throw new InvalidQueryException(
                sprintf('A where boolean is either "and" or "or", "%s" given.', $boolean),
                'invalid_query',
            );
        }

        return $normalized;
    }

    /**
     * @param  array<int, mixed>  $conditions
     * @return list<self>
     */
    private static function normalizeNested(array $conditions, string $context): array
    {
        $nested = [];

        foreach ($conditions as $condition) {
            if (! $condition instanceof self) {
                throw new InvalidQueryException(
                    sprintf('%s takes %s instances, %s given.', $context, self::class, get_debug_type($condition)),
                    'invalid_query',
                );
            }

            $nested[] = $condition;
        }

        return $nested;
    }

    private static function prepareValue(string $field, string $operator, mixed $value): mixed
    {
        if (in_array($operator, self::LIST_OPERATORS, true)) {
            return self::prepareList($field, $operator, $value);
        }

        if (in_array($operator, self::RANGE_OPERATORS, true)) {
            return self::prepareRange($field, $operator, $value);
        }

        if (is_array($value)) {
            throw new InvalidQueryException(
                sprintf(
                    'Operator "%s" on "%s" takes a single value. Use "in" for a list or "between" for a range.',
                    $operator,
                    $field,
                ),
                'invalid_query',
            );
        }

        $prepared = self::scalar($field, $operator, $value);

        if (in_array($operator, self::STRING_OPERATORS, true)) {
            if (is_bool($prepared)) {
                throw new InvalidQueryException(
                    sprintf('Operator "%s" on "%s" matches text and was given a bool.', $operator, $field),
                    'invalid_query',
                );
            }

            return (string) $prepared;
        }

        return $prepared;
    }

    /**
     * @return list<string|int|float|bool>
     */
    private static function prepareList(string $field, string $operator, mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidQueryException(
                sprintf(
                    'Operator "%s" on "%s" needs a list of values, %s given.',
                    $operator,
                    $field,
                    get_debug_type($value),
                ),
                'invalid_query',
            );
        }

        if (count($value) > self::MAX_IN) {
            throw InvalidQueryException::valueListTooLong($field, count($value), self::MAX_IN, [
                'operator' => $operator,
            ]);
        }

        $list = [];

        foreach ($value as $item) {
            $list[] = self::scalar($field, $operator, $item);
        }

        return $list;
    }

    /**
     * @return array{0: string|int|float|bool, 1: string|int|float|bool}
     */
    private static function prepareRange(string $field, string $operator, mixed $value): array
    {
        if (! is_array($value) || count($value) !== 2) {
            throw InvalidQueryException::betweenNeedsTwoBounds(
                $field,
                is_array($value) ? count($value) : 1,
                ['operator' => $operator],
            );
        }

        $bounds = array_values($value);

        return [
            self::scalar($field, $operator, $bounds[0]),
            self::scalar($field, $operator, $bounds[1]),
        ];
    }

    /**
     * Reduce one value to something JSON carries, refusing null outright.
     */
    private static function scalar(string $field, string $operator, mixed $value): string|int|float|bool
    {
        if ($value === null) {
            throw InvalidQueryException::nullComparison($field, $operator);
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        throw new InvalidQueryException(
            sprintf(
                'Operator "%s" on "%s" cannot send a %s value. Pass a scalar, a BackedEnum or a DateTimeInterface.',
                $operator,
                $field,
                get_debug_type($value),
            ),
            'invalid_query',
        );
    }
}