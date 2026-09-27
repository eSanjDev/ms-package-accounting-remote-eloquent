<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Exceptions;

use Throwable;

/**
 * An exists / unique rule that cannot be asked of a remote resource.
 */
final class UnsupportedValidationRuleException extends UnsupportedQueryException
{
    /**
     * A ->where('col', $value) style condition whose value is not a scalar.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function condition(
        string $collection,
        string $column,
        string $reason,
        array $context = [],
    ): self {
        return new self(
            sprintf(
                'The exists/unique rule on [%s] carries a condition on "%s" that cannot be sent to the resource API: %s Express it with Rule::exists(...)->where(\'%s\', $scalar), ->whereIn(\'%s\', $values) or ->whereNull(\'%s\').',
                $collection,
                $column,
                rtrim($reason, ' .') . '.',
                $column,
                $column,
                $column,
            ),
            'unsupported_validation_rule',
            0,
            null,
            $context + ['collection' => $collection, 'field' => $column],
        );
    }

    /**
     * A list of values the resource API can only check one request at a time, longer than the package will send.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function tooManyValues(
        string $collection,
        string $field,
        int $count,
        int $limit,
        array $context = [],
    ): self {
        return new self(
            sprintf(
                'The exists rule on [%s] checks %d values of "%s". The resource API publishes no distinct count for that field, so each value costs a request, and more than %d are refused. Limit the list with a "max:%d" rule, or have the account service publish the field as distinct.',
                $collection,
                $count,
                $field,
                $limit,
                $limit,
            ),
            'unsupported_validation_rule',
            0,
            null,
            $context + ['collection' => $collection, 'field' => $field, 'value_count' => $count],
        );
    }

    /**
     * A using() callback, or a whereIn()/whereNotIn() closure that turned out to do more than a list comparison.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function queryCallback(
        string $collection,
        string $reason = '',
        array $context = [],
        ?Throwable $previous = null,
    ): self {
        return new self(
            sprintf(
                'The exists/unique rule on [%s] has a query callback the resource API cannot express. Only scalar comparisons, whereIn()/whereNotIn(), null checks and withoutTrashed() survive the trip; for anything else, resolve the ids with a query of your own and validate against Rule::in($ids).%s',
                $collection,
                $reason === '' ? '' : ' The callback was refused because: ' . rtrim($reason, ' .') . '.',
            ),
            'unsupported_validation_rule',
            0,
            null,
            $context + ['collection' => $collection],
            $previous,
        );
    }
}
