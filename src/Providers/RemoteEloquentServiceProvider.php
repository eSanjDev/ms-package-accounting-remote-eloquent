<?php

namespace Esanj\RemoteEloquent\Providers;

use Esanj\RemoteEloquent\Contracts\GrpcClientInterface;
use Esanj\RemoteEloquent\Contracts\RestClientInterface;
use Esanj\RemoteEloquent\Services\ApiOAuth2Client;
use Esanj\RemoteEloquent\Services\GrpcClient;
use Esanj\RemoteEloquent\Services\RestClient;
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

        // Register REST client
        $this->app->bind(RestClientInterface::class, function ($app) {
            $config = config('remote-eloquent.rest', []);

            if (isset($config['oauth']) && $config['oauth']) {
                return new ApiOAuth2Client();
            }

            return new RestClient(
                $config['base_url'] ?? '',
                $config['headers'] ?? []
            );
        });

        // Register gRPC client
        $this->app->bind(GrpcClientInterface::class, function ($app) {
            $config = config('remote-eloquent.grpc', []);

            $client = new GrpcClient(
                $config['server_address'] ?? 'localhost:50051',
                $config['service_name'] ?? ''
            );

            return $client;
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
