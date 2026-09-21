<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent\Facades;

use Esanj\RemoteEloquent\Contracts\ResourceTransport;
use Esanj\RemoteEloquent\Testing\RemoteResourceFake;
use Illuminate\Support\Facades\Facade;

/**
 * The resource API itself, for the handful of calls a model cannot express.
 *
 * @method static string name()
 * @method static \Esanj\RemoteEloquent\Contracts\ResourceTransport withActor(?\Illuminate\Contracts\Auth\Authenticatable $actor)
 * @method static \Esanj\RemoteEloquent\Transport\RemoteResponse schema(string $resource)
 * @method static \Esanj\RemoteEloquent\Transport\RemoteResponse query(string $resource, array $spec)
 * @method static \Esanj\RemoteEloquent\Transport\RemoteResponse aggregate(string $resource, array $spec)
 * @method static \Esanj\RemoteEloquent\Transport\RemoteResponse find(string $resource, string|int $id, array $options = [])
 * @method static \Esanj\RemoteEloquent\Transport\RemoteResponse create(string $resource, array $payload, string $idempotencyKey)
 * @method static \Esanj\RemoteEloquent\Transport\RemoteResponse update(string $resource, string|int $id, array $payload, string $idempotencyKey)
 * @method static \Esanj\RemoteEloquent\Transport\RemoteResponse delete(string $resource, string|int $id, bool $force, string $idempotencyKey)
 * @method static \Esanj\RemoteEloquent\Transport\RemoteResponse restore(string $resource, string|int $id, array $payload, string $idempotencyKey)
 * @method static \Esanj\RemoteEloquent\Transport\RemoteResponse action(string $resource, string|int $id, string $action, array $payload, string $idempotencyKey)
 * @method static \Esanj\RemoteEloquent\Transport\RemoteResponse validate(string $resource, array $payload)
 * @method static \Esanj\RemoteEloquent\Transport\RemoteResponse me(array $options = [])
 * @method static \Esanj\RemoteEloquent\Transport\RemoteResponse access()
 */
final class RemoteResource extends Facade
{
    /**
     * Replace the transport with one backed by rows in memory.
     *
     * @param  array<int|string, mixed>  $rows
     */
    public static function fake(array $rows = []): RemoteResourceFake
    {
        static::clearResolvedInstance(ResourceTransport::class);

        return RemoteResourceFake::install($rows, static::getFacadeApplication());
    }

    /**
     * Give the container's real transport back.
     */
    public static function stopFaking(): void
    {
        $app = static::getFacadeApplication();

        if ($app !== null) {
            $app->forgetInstance(ResourceTransport::class);
        }

        static::clearResolvedInstance(ResourceTransport::class);
    }

    protected static function getFacadeAccessor(): string
    {
        return ResourceTransport::class;
    }
}
