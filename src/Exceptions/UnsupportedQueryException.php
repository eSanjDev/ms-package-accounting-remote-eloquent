<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Exceptions;

class UnsupportedQueryException extends RemoteEloquentException
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function method(string $method, string $alternative, array $context = []): self
    {
        return new self(
            sprintf('%s is not supported against a remote resource. %s', $method, $alternative),
            'unsupported_query',
            0,
            null,
            $context + ['builder_method' => $method],
        );
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function cursorPagination(array $context = []): self
    {
        return new self(
            'cursorPaginate() is not supported: the API pages by limit and offset, and there is no cursor to hand back. Use paginate() for a page with a total, or simplePaginate() for next/previous only.',
            'unsupported_query',
            0,
            null,
            $context + ['builder_method' => 'cursorPaginate'],
        );
    }

    /**
     * chunk(), lazy(), cursor(), each() — everything that walks a table with no stable key order.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function chunking(string $method, array $context = []): self
    {
        return new self(
            sprintf(
                '%s() is not supported, because an offset walk over a table that is being written to skips and repeats rows. Walk the key instead: chunkById(), lazyById() or eachById().',
                $method
            ),
            'unsupported_query',
            0,
            null,
            $context + ['builder_method' => $method],
        );
    }

    /**
     * firstOrCreate(), updateOrCreate(), upsert().
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function atomicUpsert(string $method, array $context = []): self
    {
        return new self(
            sprintf(
                '%s() is not supported: the API has no atomic upsert endpoint, and doing it in two calls here would race — two requests would both find nothing and both create. Ask the resource for an action that owns the decision, or use firstOrNew() and save() when a duplicate is acceptable.',
                $method
            ),
            'unsupported_query',
            0,
            null,
            $context + ['builder_method' => $method],
        );
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function massUpdate(array $context = []): self
    {
        return new self(
            'update() on a query builder is not supported: there is no endpoint that writes every matching row, and the server has domain rules to apply per record. Fetch the page, then save() each model — or ask for an action if this is one operation rather than many.',
            'unsupported_query',
            0,
            null,
            $context + ['builder_method' => 'update'],
        );
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function massDelete(array $context = []): self
    {
        return new self(
            'delete() on a query builder is not supported. Delete by key — Model::destroy($ids) — or fetch the rows and delete them one at a time, so each deletion runs the server\'s own rules.',
            'unsupported_query',
            0,
            null,
            $context + ['builder_method' => 'delete'],
        );
    }

    /**
     * increment(), decrement(), touch().
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function counterMutation(string $method, array $context = []): self
    {
        return new self(
            sprintf(
                '%s() is not supported: a read-modify-write across the network is not atomic, and on a balance that is a lost update. The resource exposes an action for changes like this — $model->remoteAction(...) — which applies it in one place.',
                $method
            ),
            'unsupported_query',
            0,
            null,
            $context + ['builder_method' => $method],
        );
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function whereColumn(array $context = []): self
    {
        return new self(
            'whereColumn() is not supported: the API compares a field to a value, never a field to another field. Filter on what you know, then compare the two attributes in PHP.',
            'unsupported_query',
            0,
            null,
            $context + ['builder_method' => 'whereColumn'],
        );
    }

    /**
     * whereRaw(), selectRaw(), orderByRaw(), havingRaw(), DB::raw().
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function rawExpression(string $method, array $context = []): self
    {
        return new self(
            sprintf(
                '%s() is not supported. Raw SQL is exactly what this package stopped sending: the account service exposes fields and operators, not its table layout. Express it with where()/orderBy() on the fields the schema lists.',
                $method
            ),
            'unsupported_query',
            0,
            null,
            $context + ['builder_method' => $method],
        );
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function whereExists(array $context = []): self
    {
        return new self(
            'whereExists() is not supported. For a remote relation, use whereHas(\'relation\', ...) — it is sent as a "has" condition. For anything else, pluck the ids first and pass them to whereIn().',
            'unsupported_query',
            0,
            null,
            $context + ['builder_method' => 'whereExists'],
        );
    }

    /**
     * A closure or builder passed where a value belongs.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function subquery(string $method, array $context = []): self
    {
        return new self(
            sprintf(
                '%s() was given a subquery, which cannot be sent: one call carries one QuerySpec. Run the inner query first and pass its result — usually ->pluck(\'id\') into whereIn().',
                $method
            ),
            'unsupported_query',
            0,
            null,
            $context + ['builder_method' => $method],
        );
    }

    /**
     * whereJsonContains(), whereJsonLength(), and friends.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function jsonWhere(string $method, array $context = []): self
    {
        return new self(
            sprintf(
                '%s() is not supported: the API filters on fields, and how a field is stored is the server\'s business. If you need to filter inside a JSON document, the resource has to expose it as a field first.',
                $method
            ),
            'unsupported_query',
            0,
            null,
            $context + ['builder_method' => $method],
        );
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function fullText(array $context = []): self
    {
        return new self(
            'whereFullText() is not supported. The nearest thing the API offers is where(\'field\', \'like\', \'%term%\'), which is sent as a "contains" condition.',
            'unsupported_query',
            0,
            null,
            $context + ['builder_method' => 'whereFullText'],
        );
    }

    /**
     * whereMonth(), whereDay(), whereTime().
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function dateComponent(string $method, array $context = []): self
    {
        return new self(
            sprintf(
                '%s() is not supported: only whereDate() and whereYear() with = survive the trip, because the server would otherwise have to guess a timezone. Express it as a range instead: whereBetween(\'created_at\', [$start, $end]).',
                $method
            ),
            'unsupported_query',
            0,
            null,
            $context + ['builder_method' => $method],
        );
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function randomOrder(array $context = []): self
    {
        return new self(
            'inRandomOrder() is not supported: ordering rows at random is a full table scan on a table that is not yours. Order by a field and pick in PHP, or ask the resource for an action that samples.',
            'unsupported_query',
            0,
            null,
            $context + ['builder_method' => 'inRandomOrder'],
        );
    }

    /**
     * groupBy(), having().
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function grouping(string $method, array $context = []): self
    {
        return new self(
            sprintf(
                '%s() is not supported: the API aggregates one function over one filtered set, not grouped buckets. Use count()/sum()/avg() with a where() per bucket, or ask for a reporting endpoint if the buckets are many.',
                $method
            ),
            'unsupported_query',
            0,
            null,
            $context + ['builder_method' => $method],
        );
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function join(string $method = 'join', string $relation = 'orders', array $context = []): self
    {
        return new self(
            sprintf(
                '%s() is not supported: joins are what made the old package depend on the account service\'s table layout. Load the relation instead: User::with(\'%s\').',
                $method,
                $relation
            ),
            'unsupported_query',
            0,
            null,
            $context + ['builder_method' => $method],
        );
    }

    /**
     * whereHas() / has() / withCount() naming a relation that lives in the LOCAL database.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function localRelationConstraint(string $method, string $relation, array $context = []): self
    {
        return new self(
            sprintf(
                '%s(\'%s\') cannot be sent: [%s] is a local relation, and the account service cannot see your tables. Resolve it here first — Local::whereHas(...)->pluck(\'remote_id\') — then filter on the result with whereIn().',
                $method,
                $relation,
                $relation
            ),
            'unsupported_query',
            0,
            null,
            $context + ['builder_method' => $method, 'relation' => $relation],
        );
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function transaction(array $context = []): self
    {
        return new self(
            'DB::transaction() cannot cover a remote resource: each call is its own request and there is nothing here to roll back. A block that looks transactional but is not turns a failed multi-step write into half-applied data. Put the whole operation behind one action on the resource, or write a compensating step.',
            'unsupported_query',
            0,
            null,
            $context + ['builder_method' => 'transaction'],
        );
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function authAttempt(array $context = []): self
    {
        return new self(
            'Auth::attempt() cannot be used against a remote resource: credentials are never sent here, and a password hash is not a readable field. Authenticate through the OAuth flow (esanj/auth-bridge) and read the signed-in user with RemoteUser::me().',
            'unsupported_query',
            0,
            null,
            $context + ['builder_method' => 'attempt'],
        );
    }

    protected function responseStatus(): int
    {
        return 500;
    }
}
