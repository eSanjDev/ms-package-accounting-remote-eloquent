<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Database;

use Closure;
use Esanj\RemoteEloquent\Exceptions\UnsupportedQueryException;
use Illuminate\Database\Connection;

final class ApiConnection extends Connection
{
    public function __construct(string $name = 'remote-eloquent')
    {
        parent::__construct(
            static fn(): never => throw self::refuse('PDO'),
            '',
            '',
            ['name' => $name, 'driver' => 'remote-eloquent'],
        );
    }

    public function select($query, $bindings = [], $useReadPdo = true): never
    {
        throw self::refuse('select');
    }

    public function selectOne($query, $bindings = [], $useReadPdo = true): never
    {
        throw self::refuse('selectOne');
    }

    public function selectResultSets($query, $bindings = [], $useReadPdo = true): never
    {
        throw self::refuse('selectResultSets');
    }

    public function scalar($query, $bindings = [], $useReadPdo = true): never
    {
        throw self::refuse('scalar');
    }

    public function selectFromWriteConnection($query, $bindings = []): never
    {
        throw self::refuse('selectFromWriteConnection');
    }

    public function cursor($query, $bindings = [], $useReadPdo = true): never
    {
        throw self::refuse('cursor');
    }

    public function insert($query, $bindings = []): never
    {
        throw self::refuse('insert');
    }

    public function update($query, $bindings = []): never
    {
        throw self::refuse('update');
    }

    public function delete($query, $bindings = []): never
    {
        throw self::refuse('delete');
    }

    public function statement($query, $bindings = []): never
    {
        throw self::refuse('statement');
    }

    public function affectingStatement($query, $bindings = []): never
    {
        throw self::refuse('affectingStatement');
    }

    public function unprepared($query): never
    {
        throw self::refuse('unprepared');
    }

    public function getPdo(): never
    {
        throw self::refuse('PDO');
    }

    public function getReadPdo(): never
    {
        throw self::refuse('PDO');
    }

    public function transaction(Closure $callback, $attempts = 1): never
    {
        throw UnsupportedQueryException::transaction(['connection' => $this->getName()]);
    }

    public function beginTransaction(): never
    {
        throw UnsupportedQueryException::transaction([
            'connection' => $this->getName(),
            'builder_method' => 'beginTransaction',
        ]);
    }

    public function rollBack($toLevel = null): never
    {
        throw UnsupportedQueryException::transaction([
            'connection' => $this->getName(),
            'builder_method' => 'rollBack',
        ]);
    }

    public function commit(): never
    {
        throw UnsupportedQueryException::transaction([
            'connection' => $this->getName(),
            'builder_method' => 'commit',
        ]);
    }

    public function transactionLevel(): int
    {
        return 0;
    }

    private static function refuse(string $method): UnsupportedQueryException
    {
        return UnsupportedQueryException::method(
            sprintf('%s() on the remote connection', $method),
            'There is no database behind this connection — a remote resource is reached by posting a QuerySpec, not by running SQL. Reaching this means a query compiled to SQL instead of being translated: query the model (User::query()->where(...)), and if a condition has no equivalent the builder will say so by name.',
            ['builder_method' => $method],
        );
    }
}
