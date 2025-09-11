<?php

namespace Esanj\RemoteEloquent\Providers;

use Esanj\RemoteEloquent\Contracts\GrpcClientInterface;
use Esanj\RemoteEloquent\Services\GrpcClient;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for RemoteEloquent module
 */
class RemoteEloquentServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/remote-eloquent.php', 'remote-eloquent'
        );

        // Register gRPC client
        $this->app->bind(GrpcClientInterface::class, function ($app) {
            $config = config('remote-eloquent.grpc', []);

            return new GrpcClient(
                $config['server_address'] ?? 'localhost:50051',
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish configuration file
        $this->publishes([
            __DIR__ . '/../config/remote-eloquent.php' => config_path('remote-eloquent.php'),
        ], 'remote-eloquent-config');
    }
}
