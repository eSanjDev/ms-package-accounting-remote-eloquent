<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Exceptions;

/**
 * A query that asked for "everything" with no limit, refused before dispatch.
 */
final class UnboundedQueryException extends UnsupportedQueryException
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function all(string $model, int $maxLimit = 100, array $context = []): self
    {
        $name = self::shortName($model);

        return new self(
            sprintf(
                '%s::all() is not supported: the account service would answer with one page and nothing would say so. Say how many you want — %s::query()->limit(%d)->get() — or walk the whole set with chunkById().',
                $name,
                $name,
                $maxLimit
            ),
            'unbounded_query',
            0,
            null,
            $context + ['model' => $model, 'builder_method' => 'all'],
        );
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function get(string $model, int $maxLimit = 100, array $context = []): self
    {
        return new self(
            sprintf(
                'get() needs an explicit limit against a remote resource, otherwise the page size is the server\'s guess rather than your decision. Add ->limit(%d) (up to %d) to the %s query, or use chunkById() when you really do want all of them.',
                $maxLimit,
                $maxLimit,
                self::shortName($model)
            ),
            'unbounded_query',
            0,
            null,
            $context + ['model' => $model, 'builder_method' => 'get'],
        );
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function pluck(string $model, string $column, int $maxLimit = 100, array $context = []): self
    {
        return new self(
            sprintf(
                'pluck(\'%s\') needs an explicit limit: it is a get() that hides how many rows it fetched. Add ->limit(%d) before it.',
                $column,
                $maxLimit
            ),
            'unbounded_query',
            0,
            null,
            $context + ['model' => $model, 'field' => $column, 'builder_method' => 'pluck'],
        );
    }

    private static function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
