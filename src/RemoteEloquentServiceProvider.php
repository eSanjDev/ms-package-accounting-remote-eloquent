<?php

declare(strict_types=1);

namespace Esanj\RemoteEloquent;

use Esanj\RemoteEloquent\Auth\AccessTokenManager;
use Esanj\RemoteEloquent\Contracts\AccessTokenProviderInterface;
use Esanj\RemoteEloquent\Contracts\TransportInterface;
use Esanj\RemoteEloquent\Database\RemoteConnection;
use Esanj\RemoteEloquent\Grpc\GrpcClientFactory;
use Esanj\RemoteEloquent\Support\RemoteQueryManager;
use Esanj\RemoteEloquent\Transport\TransportManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;

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
     * register the connections themselves so RemoteModels resolve without the
     * user editing config/database.php.
     *
     * Besides the default connection (which uses the configured default
     * transport), one connection is registered per shipped transport — e.g.
     * "remote_rest" and "remote_grpc" — so a model can pin itself to a specific
     * transport with `protected $transport = 'grpc'` (see RemoteModel).
     *
     * Each of those also gets a "_fallback" and a "_nofallback" variant, so
     * `protected $transportFallback = false` can keep one model on its own
     * transport while the rest of the service still fails over.
     */
    private function registerRemoteConnection(): void
    {
        Connection::resolverFor(self::DRIVER, function ($connection, $database, $prefix, $config) {
            return new RemoteConnection($connection, $database, $prefix, $config);
        });

        $base = (string) config('esanj.remote_eloquent.connection', self::DRIVER);

        // A null transport (or fallback) leaves that decision to the package
        // default, resolved at query time.
        foreach ([null, ...TransportManager::DRIVERS] as $transport) {
            $name = $transport === null ? $base : $base.'_'.$transport;

            $this->registerConnection($name, $transport, null);
            $this->registerConnection($name.'_fallback', $transport, true);
            $this->registerConnection($name.'_nofallback', $transport, false);
        }
    }

    /**
     * Register a single "remote" database connection, optionally pinned to a
     * transport ("rest"/"grpc") and to a fallback decision.
     */
    private function registerConnection(string $name, ?string $transport, ?bool $fallback): void
    {
        config([
            "database.connections.{$name}" => [
                'driver' => self::DRIVER,
                'database' => $name,
                'prefix' => (string) config('esanj.remote_eloquent.database.prefix', ''),
                'server_version' => (string) config('esanj.remote_eloquent.database.server_version', '8.0.0'),
                'transport' => $transport,
                'transport_fallback' => $fallback,
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

        $this->app->singleton(TransportManager::class, static function (Application $app): TransportManager {
            return new TransportManager($app);
        });

        $this->app->singleton(TransportInterface::class, static function (Application $app): TransportInterface {
            return $app->make(TransportManager::class)->resolve();
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