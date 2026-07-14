<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent;

use Esanj\RemoteEloquent\Auth\AccessTokenManager;
use Esanj\RemoteEloquent\Contracts\AccessTokenProviderInterface;
use Esanj\RemoteEloquent\Contracts\TransportInterface;
use Esanj\RemoteEloquent\Database\RemoteConnection;
use Esanj\RemoteEloquent\Grpc\GrpcClientFactory;
use Esanj\RemoteEloquent\Support\RemoteQueryManager;
use Esanj\RemoteEloquent\Transport\GrpcTransport;
use Esanj\RemoteEloquent\Transport\RestTransport;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class RemoteEloquentServiceProvider extends ServiceProvider
{
    /**
     * The connection driver name this package registers.
     */
    private const DRIVER = 'remote';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/remote_eloquent.php', 'esanj.remote_eloquent');

        $this->registerRemoteConnection();
        $this->registerAccessToken();
        $this->registerTransport();
        $this->registerManager();
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/config/remote_eloquent.php' => config_path('esanj/remote_eloquent.php'),
        ], 'esanj-remote-eloquent-config');
    }

    /**
     * Teach Laravel's database layer how to build the "remote" connection, and
     * register the connection itself so RemoteModels resolve without the user
     * editing config/database.php.
     */
    private function registerRemoteConnection(): void
    {
        Connection::resolverFor(self::DRIVER, function ($connection, $database, $prefix, $config) {
            return new RemoteConnection($connection, $database, $prefix, $config);
        });

        $name = (string) config('esanj.remote_eloquent.connection', self::DRIVER);

        config([
            "database.connections.{$name}" => [
                'driver' => self::DRIVER,
                'database' => $name,
                'prefix' => (string) config('esanj.remote_eloquent.database.prefix', ''),
                'server_version' => (string) config('esanj.remote_eloquent.database.server_version', '8.0.0'),
            ],
        ]);
    }

    private function registerAccessToken(): void
    {
        $this->app->singleton(AccessTokenProviderInterface::class, static function (Application $app): AccessTokenProviderInterface {
            return new AccessTokenManager((array) config('esanj.remote_eloquent.auth', []));
        });
    }

    private function registerTransport(): void
    {
        $this->app->singleton(GrpcClientFactory::class, static function (Application $app): GrpcClientFactory {
            return new GrpcClientFactory((array) config('esanj.remote_eloquent.grpc', []));
        });

        $this->app->singleton(TransportInterface::class, function (Application $app): TransportInterface {
            $driver = (string) config('esanj.remote_eloquent.driver', 'rest');
            $token = $app->make(AccessTokenProviderInterface::class);

            return match ($driver) {
                'rest' => new RestTransport($token, (array) config('esanj.remote_eloquent.rest', [])),
                'grpc' => new GrpcTransport(
                    $token,
                    $app->make(GrpcClientFactory::class),
                    (array) config('esanj.remote_eloquent.grpc', []),
                ),
                default => throw new InvalidArgumentException(
                    "Unsupported remote_eloquent transport driver [{$driver}]. Use \"rest\" or \"grpc\"."
                ),
            };
        });
    }

    private function registerManager(): void
    {
        $this->app->singleton('esanj.remote_eloquent', static function (Application $app): RemoteQueryManager {
            return new RemoteQueryManager(
                $app->make(TransportInterface::class),
                $app->make(AccessTokenProviderInterface::class),
                (string) config('esanj.remote_eloquent.connection', self::DRIVER),
            );
        });

        $this->app->alias('esanj.remote_eloquent', RemoteQueryManager::class);
    }
}