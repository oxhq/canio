<?php

declare(strict_types=1);

return [
    'runtime' => [
        'binary' => env('CANIO_RUNTIME_BINARY', 'stagehand'),
        'install_path' => env('CANIO_RUNTIME_INSTALL_PATH', 'bin/stagehand'),
        'working_directory' => env('CANIO_RUNTIME_WORKING_DIRECTORY', base_path()),
        'base_url' => env('CANIO_RUNTIME_BASE_URL', 'http://127.0.0.1:9514'),
        'timeout' => (int) env('CANIO_RUNTIME_TIMEOUT', 30),
        'host' => env('CANIO_RUNTIME_HOST', '127.0.0.1'),
        'port' => (int) env('CANIO_RUNTIME_PORT', 9514),
        'state_path' => env('CANIO_RUNTIME_STATE_PATH', storage_path('app/canio/runtime')),
        'log_path' => env('CANIO_RUNTIME_LOG_PATH', storage_path('logs/canio-runtime.log')),
        'chromium' => [
            'path' => env('CANIO_CHROMIUM_PATH'),
            'channel' => env('CANIO_CHROMIUM_CHANNEL', 'stable'),
            'headless' => (bool) env('CANIO_CHROMIUM_HEADLESS', true),
            'ignore_https_errors' => (bool) env('CANIO_CHROMIUM_IGNORE_HTTPS_ERRORS', true),
            'user_data_dir' => env('CANIO_CHROMIUM_USER_DATA_DIR'),
        ],
        'pool' => [
            'size' => (int) env('CANIO_RUNTIME_BROWSER_POOL_SIZE', 2),
            'warm' => (int) env('CANIO_RUNTIME_BROWSER_POOL_WARM', 1),
            'queue_depth' => (int) env('CANIO_RUNTIME_BROWSER_QUEUE_DEPTH', 16),
            'acquire_timeout' => (int) env('CANIO_RUNTIME_BROWSER_ACQUIRE_TIMEOUT', 15),
        ],
        'jobs' => [
            'backend' => env('CANIO_RUNTIME_JOB_BACKEND', 'memory'),
            'workers' => (int) env('CANIO_RUNTIME_JOB_WORKERS', 2),
            'queue_depth' => (int) env('CANIO_RUNTIME_JOB_QUEUE_DEPTH', 64),
            'lease_timeout' => (int) env('CANIO_RUNTIME_JOB_LEASE_TIMEOUT', 45),
            'heartbeat_interval' => (int) env('CANIO_RUNTIME_JOB_HEARTBEAT_INTERVAL', 10),
            'ttl_days' => (int) env('CANIO_RUNTIME_JOB_TTL_DAYS', 14),
            'dead_letter_ttl_days' => (int) env('CANIO_RUNTIME_DEADLETTER_TTL_DAYS', 30),
            'redis' => [
                'host' => env('CANIO_RUNTIME_REDIS_HOST', env('REDIS_HOST', '127.0.0.1')),
                'port' => (int) env('CANIO_RUNTIME_REDIS_PORT', env('REDIS_PORT', 6379)),
                'password' => env('CANIO_RUNTIME_REDIS_PASSWORD', env('REDIS_PASSWORD')),
                'db' => (int) env('CANIO_RUNTIME_REDIS_DB', env('REDIS_DB', 0)),
                'queue_key' => env('CANIO_RUNTIME_REDIS_QUEUE_KEY', 'canio:jobs:queue'),
                'block_timeout' => (int) env('CANIO_RUNTIME_REDIS_BLOCK_TIMEOUT', 1),
            ],
        ],
        'artifacts' => [
            'ttl_days' => (int) env('CANIO_RUNTIME_ARTIFACT_TTL_DAYS', 14),
        ],
        'observability' => [
            'log_format' => env('CANIO_RUNTIME_LOG_FORMAT', 'json'),
            'request_logging' => (bool) env('CANIO_RUNTIME_REQUEST_LOGGING', true),
        ],
        'auth' => [
            'shared_secret' => env('CANIO_RUNTIME_SHARED_SECRET'),
            'algorithm' => env('CANIO_RUNTIME_AUTH_ALGORITHM', 'canio-v1'),
            'timestamp_header' => env('CANIO_RUNTIME_AUTH_TIMESTAMP_HEADER', 'X-Canio-Timestamp'),
            'signature_header' => env('CANIO_RUNTIME_AUTH_SIGNATURE_HEADER', 'X-Canio-Signature'),
            'max_skew_seconds' => (int) env('CANIO_RUNTIME_AUTH_MAX_SKEW', 300),
        ],
        'push' => [
            'webhook' => [
                'enabled' => (bool) env('CANIO_PUSH_WEBHOOK_ENABLED', false),
                'url' => env('CANIO_PUSH_WEBHOOK_URL'),
                'path' => env('CANIO_PUSH_WEBHOOK_PATH', '/canio/webhooks/stagehand/jobs'),
                'secret' => env('CANIO_PUSH_WEBHOOK_SECRET', env('CANIO_RUNTIME_SHARED_SECRET')),
            ],
        ],
        'release' => [
            'repository' => env('CANIO_RUNTIME_RELEASE_REPOSITORY', 'oxhq/canio'),
            'base_url' => env('CANIO_RUNTIME_RELEASE_BASE_URL', 'https://github.com'),
            'version' => env('CANIO_RUNTIME_RELEASE_VERSION'),
        ],
    ],

    'defaults' => [
        'profile' => env('CANIO_DEFAULT_PROFILE', 'invoice'),
        'format' => env('CANIO_DEFAULT_FORMAT', 'a4'),
        'timeout' => (int) env('CANIO_DEFAULT_TIMEOUT', 30),
        'retries' => (int) env('CANIO_DEFAULT_RETRIES', 0),
        'debug' => (bool) env('CANIO_DEBUG', false),
        'watch' => (bool) env('CANIO_WATCH', false),
        'disk' => env('CANIO_DEFAULT_DISK'),
    ],

    'ops' => [
        'enabled' => env('CANIO_OPS_ENABLED', in_array((string) env('APP_ENV', 'production'), ['local', 'testing'], true)),
        'path' => env('CANIO_OPS_PATH', '/canio/ops'),
        'title' => env('CANIO_OPS_TITLE', 'Canio Ops'),
        'refresh_seconds' => (int) env('CANIO_OPS_REFRESH_SECONDS', 3),
        'middleware' => array_values(array_filter(array_map(
            static fn (string $middleware): string => trim($middleware),
            explode(',', (string) env('CANIO_OPS_MIDDLEWARE', 'web')),
        ))),
        'access' => [
            'preset' => env('CANIO_OPS_PRESET', in_array((string) env('APP_ENV', 'production'), ['local', 'testing'], true) ? 'local-open' : 'laravel-auth'),
            'require_auth' => env('CANIO_OPS_REQUIRE_AUTH'),
            'guards' => array_values(array_filter(array_map(
                static fn (string $guard): string => trim($guard),
                explode(',', (string) env('CANIO_OPS_GUARDS', 'web')),
            ))),
            'ability' => env('CANIO_OPS_ABILITY'),
            'authorizer' => env('CANIO_OPS_AUTHORIZER'),
            'basic' => [
                'enabled' => env('CANIO_OPS_BASIC_ENABLED'),
                'username' => env('CANIO_OPS_BASIC_USERNAME'),
                'password' => env('CANIO_OPS_BASIC_PASSWORD'),
                'realm' => env('CANIO_OPS_BASIC_REALM', 'Canio Ops'),
            ],
        ],
    ],

    'profiles_path' => env('CANIO_PROFILES_PATH', dirname(__DIR__, 3).'/resources/profiles'),
];
