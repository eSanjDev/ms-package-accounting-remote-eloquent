<?php

declare(strict_types=1);

$baseUrl = rtrim((string) env('REMOTE_ELOQUENT_BASE_URL', (string) env('ACCOUNTING_BRIDGE_BASE_URL', '')), '/');

return [
    'driver' => env('REMOTE_ELOQUENT_DRIVER', 'rest'),

    'rest' => [
        'base_url' => $baseUrl !== '' ? $baseUrl : null,
        'prefix' => env('REMOTE_ELOQUENT_PREFIX', '/api/remote/v1'),
        'timeout' => (float) env('REMOTE_ELOQUENT_TIMEOUT', 5),
        'connect_timeout' => (float) env('REMOTE_ELOQUENT_CONNECT_TIMEOUT', 2),

        'client_version' => env('REMOTE_ELOQUENT_CLIENT_VERSION', '2.0'),

        'headers' => [],
    ],

    'auth' => [
        'token_url' => env(
            'REMOTE_ELOQUENT_TOKEN_URL',
            $baseUrl !== '' ? $baseUrl . '/oauth/token' : null
        ),
        'client_id' => env('REMOTE_ELOQUENT_CLIENT_ID', env('ACCOUNTING_BRIDGE_CLIENT_ID')),
        'client_secret' => env('REMOTE_ELOQUENT_CLIENT_SECRET', env('ACCOUNTING_BRIDGE_CLIENT_SECRET')),
        'scope' => env('REMOTE_ELOQUENT_SCOPE', ''),

        // Cache store holding the access token (null = the default store).
        'cache_store' => env('REMOTE_ELOQUENT_CACHE_STORE'),
        'cache_key' => env('REMOTE_ELOQUENT_TOKEN_CACHE_KEY', 'esanj:remote_eloquent:token'),
        'refresh_buffer_seconds' => (int) env('REMOTE_ELOQUENT_REFRESH_BUFFER', 60),
    ],

    'actor' => [
        'exchange_url' => env('REMOTE_ELOQUENT_ACTOR_EXCHANGE_URL'),
        'token_type' => env('REMOTE_ELOQUENT_ACTOR_TOKEN_TYPE', 'Bearer'),

        // Seconds an exchanged actor token stays usable.
        'ttl' => (int) env('REMOTE_ELOQUENT_ACTOR_TTL', 60),
    ],

    'limits' => [
        'max_query_limit' => (int) env('REMOTE_ELOQUENT_MAX_LIMIT', 100),
        'in_chunk' => (int) env('REMOTE_ELOQUENT_IN_CHUNK', 500),
    ],

    'cache' => [
        'store' => env('REMOTE_ELOQUENT_CACHE_STORE'),
        'prefix' => env('REMOTE_ELOQUENT_CACHE_PREFIX', 'esanj:remote_eloquent:'),
        'identity_map' => (bool) env('REMOTE_ELOQUENT_IDENTITY_MAP', true),
        'find_ttl' => (int) env('REMOTE_ELOQUENT_FIND_TTL', 0),
        'schema_ttl' => (int) env('REMOTE_ELOQUENT_SCHEMA_TTL', 3600),
        'access_ttl' => (int) env('REMOTE_ELOQUENT_ACCESS_TTL', 600),
    ],

    'errors' => [
        'render' => (bool) env('REMOTE_ELOQUENT_RENDER_ERRORS', true),
    ],

    'telemetry' => [
        'call_warning_threshold' => (int) env('REMOTE_ELOQUENT_CALL_WARNING_THRESHOLD', 20),
        'log_channel' => env('REMOTE_ELOQUENT_LOG_CHANNEL'),
    ],

    'fallback' => false,

];
