<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Client Type
    |--------------------------------------------------------------------------
    |
    | This value determines the default client type to use when no specific
    | client type is set on the model.
    |
    */
    'default_client' => env('REMOTE_ELOQUENT_DEFAULT_CLIENT', 'rest'),

    /*
    |--------------------------------------------------------------------------
    | REST API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for REST API clients
    |
    */
    'rest' => [
        'base_url' => env('REMOTE_ELOQUENT_REST_BASE_URL', ''),
        'headers' => [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ],
        'timeout' => env('REMOTE_ELOQUENT_REST_TIMEOUT', 30),
        'oauth' => env('REMOTE_ELOQUENT_REST_OAUTH', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | gRPC Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for gRPC clients
    |
    */
    'grpc' => [
        'server_address' => env('REMOTE_ELOQUENT_GRPC_SERVER_ADDRESS', 'localhost:50051'),
        'service_name' => env('REMOTE_ELOQUENT_GRPC_SERVICE_NAME', ''),
        'credentials' => [
            'type' => env('REMOTE_ELOQUENT_GRPC_CREDENTIALS_TYPE', 'insecure'),
            'cert_path' => env('REMOTE_ELOQUENT_GRPC_CERT_PATH', ''),
            'key_path' => env('REMOTE_ELOQUENT_GRPC_KEY_PATH', ''),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for logging API requests and responses
    |
    */
    'logging' => [
        'enabled' => env('REMOTE_ELOQUENT_LOGGING_ENABLED', false),
        'channel' => env('REMOTE_ELOQUENT_LOGGING_CHANNEL', 'stack'),
        'level' => env('REMOTE_ELOQUENT_LOGGING_LEVEL', 'info'),
    ],
]; 