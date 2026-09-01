<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Support;

use Esanj\RemoteEloquent\Contracts\AccessTokenProviderInterface;
use Esanj\RemoteEloquent\Contracts\TransportInterface;
use Esanj\RemoteEloquent\DTOs\QueryResult;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * The object behind the RemoteQuery facade. Convenience access to raw remote
 * queries, the underlying connection, and the cached access token — for code
 * that wants the remote pipe without an Eloquent model.
 */
final class RemoteQueryManager
{
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly AccessTokenProviderInterface $token,
        private readonly string $connectionName,
    ) {}

    /**
     * Run one raw SQL statement remotely and return the full result.
     *
     * @param  array<int, scalar|null>  $bindings
     */
    public function run(string $sql, array $bindings = []): QueryResult
    {
        return $this->transport->runQuery($sql, $bindings);
    }

    /**
     * Run a raw SELECT and return just the rows. Over gRPC every value is a
     * string; over REST the JSON types (int/bool/null) survive as they are.
     *
     * @param  array<int, scalar|null>  $bindings
     * @return list<array<string, scalar|null>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        return $this->run($sql, $bindings)->rows;
    }

    /**
     * Run a raw INSERT/UPDATE/DELETE and return the affected row count.
     *
     * @param  array<int, scalar|null>  $bindings
     */
    public function affectingStatement(string $sql, array $bindings = []): int
    {
        return $this->run($sql, $bindings)->affectedRows;
    }

    public function transport(): TransportInterface
    {
        return $this->transport;
    }

    public function token(): AccessTokenProviderInterface
    {
        return $this->token;
    }

    /**
     * Drop the cached access token so the next call re-authenticates.
     */
    public function forgetToken(): void
    {
        $this->token->forget();
    }

    public function connectionName(): string
    {
        return $this->connectionName;
    }

    public function connection(): ConnectionInterface
    {
        return DB::connection($this->connectionName);
    }
}