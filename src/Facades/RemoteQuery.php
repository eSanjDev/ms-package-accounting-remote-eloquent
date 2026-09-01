<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Facades;

use Esanj\RemoteEloquent\Contracts\AccessTokenProviderInterface;
use Esanj\RemoteEloquent\Contracts\TransportInterface;
use Esanj\RemoteEloquent\DTOs\QueryResult;
use Illuminate\Support\Facades\Facade;

/**
 * @method static QueryResult run(string $sql, array $bindings = [])
 * @method static array<int, array<string, scalar|null>> select(string $sql, array $bindings = [])
 * @method static int affectingStatement(string $sql, array $bindings = [])
 * @method static TransportInterface transport()
 * @method static AccessTokenProviderInterface token()
 * @method static void forgetToken()
 * @method static string connectionName()
 * @method static \Illuminate\Database\ConnectionInterface connection()
 *
 * @see \Esanj\RemoteEloquent\Support\RemoteQueryManager
 */
class RemoteQuery extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'esanj.remote_eloquent';
    }
}