<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Exceptions;

final class InvalidQueryException extends RemoteEloquentException
{
    public static function fromServer(
        string  $code,
        string  $message,
        int     $status = 400,
        ?string $requestId = null,
        array   $context = [],
    ): self
    {
        return new self(
            $message !== '' ? $message : 'The account service refused this query.',
            $code !== '' ? $code : 'invalid_query',
            $status,
            $requestId,
            $context,
        );
    }

    public static function unknownField(
        string  $resource,
        string  $field,
        ?string $requestId = null,
        array   $context = [],
    ): self
    {
        return new self(
            sprintf(
                'The "%s" resource has no field [%s]. Ask it what it does have: RemoteSchema::for(\'%s\')->fields().',
                $resource,
                $field,
                $resource
            ),
            'unknown_field',
            400,
            $requestId,
            $context + ['resource' => $resource, 'field' => $field],
        );
    }

    public static function operatorNotAllowed(
        string  $resource,
        string  $field,
        string  $operator,
        ?string $requestId = null,
        array   $context = [],
    ): self
    {
        return new self(
            sprintf(
                'The "%s" resource does not allow [%s] on [%s]. A field is filterable only with the operators its schema lists.',
                $resource,
                $operator,
                $field
            ),
            'operator_not_allowed',
            400,
            $requestId,
            $context + ['resource' => $resource, 'field' => $field, 'operator' => $operator],
        );
    }

    public static function fieldNotWritable(
        string  $resource,
        string  $field,
        ?string $requestId = null,
        array   $context = [],
    ): self
    {
        return new self(
            sprintf(
                'The field [%s] on "%s" cannot be written directly. If the change is a domain event rather than an assignment, it has an action: $model->remoteAction(\'...\').',
                $field,
                $resource
            ),
            'field_not_writable',
            400,
            $requestId,
            $context + ['resource' => $resource, 'field' => $field],
        );
    }

    public static function tooComplex(
        string  $resource,
        ?string $requestId = null,
        array   $context = [],
    ): self
    {
        return new self(
            sprintf(
                'This query is more than the "%s" resource will evaluate. Flatten the nested where groups, or split it into two queries.',
                $resource
            ),
            'query_too_complex',
            400,
            $requestId,
            $context + ['resource' => $resource],
        );
    }

    public static function payloadTooLarge(
        string  $resource,
        ?string $requestId = null,
        array   $context = [],
    ): self
    {
        return new self(
            sprintf(
                'The request body for "%s" was too large. A whereIn() with thousands of ids is the usual cause: query it in chunks.',
                $resource
            ),
            'payload_too_large',
            413,
            $requestId,
            $context + ['resource' => $resource],
        );
    }

    public static function limitTooHigh(int $requested, int $max, array $context = []): self
    {
        return new self(
            sprintf(
                'A single page may be at most %d rows, not %d. Page through it with paginate(%d) or chunkById(%d).',
                $max,
                $requested,
                $max,
                $max
            ),
            'invalid_query',
            0,
            null,
            $context + ['requested_limit' => $requested, 'max_limit' => $max],
        );
    }

    public static function valueListTooLong(string $field, int $count, int $max, array $context = []): self
    {
        return new self(
            sprintf(
                'whereIn(\'%s\', ...) was given %d values; the limit per call is %d. Chunk the ids and merge the results.',
                $field,
                $count,
                $max
            ),
            'invalid_query',
            0,
            null,
            $context + ['field' => $field, 'value_count' => $count, 'max_values' => $max],
        );
    }

    public static function nullComparison(string $field, string $operator, array $context = []): self
    {
        return new self(
            sprintf(
                'where(\'%s\', \'%s\', null) is not a comparison the API accepts, because null is never equal to anything. Say what you mean: whereNull(\'%s\') or whereNotNull(\'%s\').',
                $field,
                $operator,
                $field,
                $field
            ),
            'invalid_query',
            0,
            null,
            $context + ['field' => $field, 'operator' => $operator],
        );
    }

    public static function betweenNeedsTwoBounds(string $field, int $given, array $context = []): self
    {
        return new self(
            sprintf(
                'whereBetween(\'%s\', ...) needs exactly two bounds, %d given.',
                $field,
                $given
            ),
            'invalid_query',
            0,
            null,
            $context + ['field' => $field, 'bound_count' => $given],
        );
    }

    protected function responseStatus(): int
    {
        return 500;
    }
}
