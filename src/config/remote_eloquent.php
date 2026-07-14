<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Transport driver
    |--------------------------------------------------------------------------
    |
    | How compiled SQL travels to the Accounting service: "rest" (turnkey, no
    | extra dependencies) or "grpc" (needs ext-grpc, grpc/grpc and generated
    | protobuf classes — see docs/GUIDE.md). Both speak the same
    | {sql, bindings} -> {rows, affected_rows} contract.
    |
    */

    'driver' => env('REMOTE_ELOQUENT_DRIVER', 'rest'),

    /*
    |--------------------------------------------------------------------------
    | Connection name
    |--------------------------------------------------------------------------
    |
    | The database connection this package registers and RemoteModels resolve
    | by default. You do not need to add it to config/database.php.
    |
    */

    'connection' => env('REMOTE_ELOQUENT_CONNECTION', 'remote'),

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    |
    | Reported to Laravel's MySQL grammar without touching a PDO. "server_version"
    | drives version-specific SQL compilation; keep it in step with the remote
    | server. "prefix" is prepended to every table name.
    |
    */

    'database' => [
        'prefix' => env('REMOTE_ELOQUENT_TABLE_PREFIX', ''),
        'server_version' => env('REMOTE_ELOQUENT_SERVER_VERSION', '8.0.0'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication (OAuth client-credentials)
    |--------------------------------------------------------------------------
    |
    | Credentials default to the esanj/auth-bridge variables, so a service that
    | already logs into Accounting needs almost nothing new. The token is cached
    | until "cache_buffer_seconds" before it expires, then refreshed automatically.
    |
    */

    'auth' => [
        'token_url' => env(
            'REMOTE_ELOQUENT_TOKEN_URL',
            rtrim((string) env('REMOTE_ELOQUENT_BASE_URL', env('ACCOUNTING_BRIDGE_BASE_URL', '')), '/').'/oauth/token'
        ),
        'client_id' => env('REMOTE_ELOQUENT_CLIENT_ID', env('ACCOUNTING_BRIDGE_CLIENT_ID')),
        'client_secret' => env('REMOTE_ELOQUENT_CLIENT_SECRET', env('ACCOUNTING_BRIDGE_CLIENT_SECRET')),
        'scope' => env('REMOTE_ELOQUENT_SCOPE', '*'),

        // Cache store used for the access token (null = the default store).
        'cache_store' => env('REMOTE_ELOQUENT_CACHE_STORE'),
        'cache_prefix' => env('REMOTE_ELOQUENT_CACHE_PREFIX', 'remote_eloquent_token_'),
        'cache_buffer_seconds' => (int) env('REMOTE_ELOQUENT_CACHE_BUFFER', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | REST transport
    |--------------------------------------------------------------------------
    |
    | Used when driver=rest. Statements are POSTed to {base_url}{query_path} with
    | a Bearer token. "retries" only re-sends on genuine connection failures.
    |
    */

    'rest' => [
        'base_url' => env('REMOTE_ELOQUENT_BASE_URL', env('ACCOUNTING_BRIDGE_BASE_URL')),
        'query_path' => env('REMOTE_ELOQUENT_QUERY_PATH', '/api/application/query'),
        'timeout' => (int) env('REMOTE_ELOQUENT_REST_TIMEOUT', 15),
        'connect_timeout' => (int) env('REMOTE_ELOQUENT_REST_CONNECT_TIMEOUT', 5),
        'retries' => (int) env('REMOTE_ELOQUENT_REST_RETRIES', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | gRPC transport
    |--------------------------------------------------------------------------
    |
    | Used when driver=grpc. "request_class"/"response_class" are the FQCNs you
    | generated from proto/eloquent.proto (the shipped proto uses the
    | App\Services\Grpc\Eloquent namespace). "timeout" is in seconds (0 = none).
    |
    */

    'grpc' => [
        'host' => env('REMOTE_ELOQUENT_GRPC_HOST', '127.0.0.1:50051'),
        'secure' => (bool) env('REMOTE_ELOQUENT_GRPC_SECURE', false),
        'timeout' => (int) env('REMOTE_ELOQUENT_GRPC_TIMEOUT', 0),
        'metadata_key' => env('REMOTE_ELOQUENT_GRPC_METADATA_KEY', 'authorization'),
        'request_class' => env('REMOTE_ELOQUENT_GRPC_REQUEST', 'App\Services\Grpc\Eloquent\QueryRequest'),
        'response_class' => env('REMOTE_ELOQUENT_GRPC_RESPONSE', 'App\Services\Grpc\Eloquent\QueryResponse'),
    ],

];