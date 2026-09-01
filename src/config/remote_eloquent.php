<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Transport driver
    |--------------------------------------------------------------------------
    |
    | How compiled SQL travels to the Accounting service: "rest" (turnkey, no
    | extra dependencies) or "grpc" (needs ext-grpc + grpc/grpc + google/protobuf
    | — see docs/GUIDE.md). Both speak the same {sql, bindings} ->
    | {rows, affected_rows} contract.
    |
    | This is the default for every RemoteModel. An individual model may pin
    | itself to a specific transport with `protected $transport = 'grpc';`
    | (or 'rest'), independent of this default — see docs/GUIDE.md.
    |
    */

    'driver' => env('REMOTE_ELOQUENT_DRIVER', 'rest'),

    /*
    |--------------------------------------------------------------------------
    | Transport fallback
    |--------------------------------------------------------------------------
    |
    | When a statement fails because its transport is unreachable, misconfigured
    | or answering with an unexpected status, replay it on the next transport in
    | "chain" — REST takes over for gRPC and the other way round. A rejected
    | query (422) or a denied table (403) is a server verdict, not a transport
    | failure, and is never retried elsewhere.
    |
    | An individual model may opt out with `protected $transportFallback = false;`
    | (or force it on with true) — see docs/GUIDE.md.
    |
    */

    'fallback' => [
        'enabled' => (bool) env('REMOTE_ELOQUENT_FALLBACK', true),

        // Which transports take over, per primary, in order.
        'chain' => [
            'rest' => ['grpc'],
            'grpc' => ['rest'],
        ],

        // Whether INSERT/UPDATE/DELETE may be replayed on the fallback transport.
        // Off by default: a write that timed out may already have been applied,
        // and replaying it would apply it twice. Writes that provably never left
        // the process (e.g. a missing gRPC stack) always fall back regardless.
        'retry_writes' => (bool) env('REMOTE_ELOQUENT_FALLBACK_RETRY_WRITES', false),

        // Log a warning on every handover so an outage is never silent.
        'log' => (bool) env('REMOTE_ELOQUENT_FALLBACK_LOG', true),
        'log_channel' => env('REMOTE_ELOQUENT_FALLBACK_LOG_CHANNEL'),
    ],

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
    | a Bearer token. "retries" is the total number of attempts on connection
    | failure, and applies to reads only — a write is never re-sent, because a
    | timed-out INSERT/UPDATE may already have been applied server-side.
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
    | Unsafe transactions
    |--------------------------------------------------------------------------
    |
    | The remote connection cannot open a real database transaction: each
    | statement is a separate call and there is nothing to roll back. By default
    | transaction()/beginTransaction() therefore throw, because a block that looks
    | transactional but silently is not turns a failed multi-step write into
    | half-applied data — on an accounting database, a half-finished transfer.
    |
    | Turning this on restores the old behaviour: the callback simply runs, with
    | no atomicity whatsoever. Only do that if every such block is a single
    | statement or is safe to leave partially applied. For real atomicity, put the
    | whole operation behind one Accounting endpoint that opens a local
    | transaction, or write a compensating action.
    |
    */

    'allow_unsafe_transactions' => (bool) env('REMOTE_ELOQUENT_ALLOW_UNSAFE_TRANSACTIONS', false),

    /*
    |--------------------------------------------------------------------------
    | gRPC transport
    |--------------------------------------------------------------------------
    |
    | Used when driver=grpc. "request_class"/"response_class" default to the
    | protobuf message classes shipped with this package (namespace
    | Esanj\RemoteEloquent\Grpc) — no code generation is required, just install
    | ext-grpc + grpc/grpc + google/protobuf. Override them only to point at your
    | own generated classes. "timeout" is in seconds (0 = none).
    |
    */

    'grpc' => [
        'host' => env('REMOTE_ELOQUENT_GRPC_HOST', '127.0.0.1:50051'),
        'secure' => (bool) env('REMOTE_ELOQUENT_GRPC_SECURE', false),
        'timeout' => (int) env('REMOTE_ELOQUENT_GRPC_TIMEOUT', 0),
        'metadata_key' => env('REMOTE_ELOQUENT_GRPC_METADATA_KEY', 'authorization'),
        'request_class' => env('REMOTE_ELOQUENT_GRPC_REQUEST', 'Esanj\RemoteEloquent\Grpc\QueryRequest'),
        'response_class' => env('REMOTE_ELOQUENT_GRPC_RESPONSE', 'Esanj\RemoteEloquent\Grpc\QueryResponse'),
    ],

];
