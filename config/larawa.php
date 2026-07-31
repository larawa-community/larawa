<?php

$workerEvents = [
    'qr',
    'authenticated',
    'ready',
    'auth_failure',
    'disconnected',
    'worker.error',
    'message.received',
    'message.created',
    'message.status',
    'message.reaction',
    'group.join',
    'group.leave',
    'status',
];

return [
    'version' => env('LARAWA_VERSION', '13.0.0'),
    'installed' => filter_var(env('LARAWA_INSTALLED', false), FILTER_VALIDATE_BOOL),
    'env_path' => env('LARAWA_ENV_PATH', base_path('.env')),
    'env_seed_path' => env('LARAWA_ENV_SEED_PATH', base_path('.env')),
    'worker_url' => rtrim(env('WA_WORKER_URL', 'http://127.0.0.1:3001'), '/'),
    'worker_token' => env('WA_WORKER_INTERNAL_TOKEN', 'change-me-worker-token'),
    'worker_callback_url' => env('WA_WORKER_CALLBACK_URL', env('APP_URL').'/internal/worker/events'),
    'meta' => [
        'graph_url' => rtrim(env('META_GRAPH_API_URL', 'https://graph.facebook.com'), '/'),
        'graph_version' => env('META_GRAPH_API_VERSION', 'v25.0'),
        'timeout' => (int) env('META_WHATSAPP_TIMEOUT', 30),
    ],
    'api_rate_limit_per_minute' => (int) env('API_RATE_LIMIT_PER_MINUTE', 120),
    'webhook_timeout' => (int) env('WEBHOOK_TIMEOUT', 10),
    'webhook_retry_attempts' => (int) env('WEBHOOK_RETRY_ATTEMPTS', 3),
    'webhook_retry_backoff' => array_map(
        'intval',
        array_filter(explode(',', env('WEBHOOK_RETRY_BACKOFF', '30,120,300')), fn ($value) => trim($value) !== '')
    ),
    'media_base64_max_bytes' => (int) env('LARAWA_MEDIA_BASE64_MAX_BYTES', 25 * 1024 * 1024),
    'media_url_allow_private' => filter_var(env('MEDIA_URL_ALLOW_PRIVATE', false), FILTER_VALIDATE_BOOL),
    'webhook_url_allow_private' => filter_var(env('WEBHOOK_URL_ALLOW_PRIVATE', false), FILTER_VALIDATE_BOOL),
    'api_key_scopes' => [
        '*',
        'sessions:read',
        'sessions:write',
        'messages:read',
        'messages:send',
        'webhooks:read',
        'webhooks:write',
        'api-keys:read',
        'api-keys:write',
    ],
    'worker_events' => $workerEvents,
    'webhook_events' => array_merge(['*', 'webhook.test'], $workerEvents),
    'default_workspace' => env('LARAWA_DEFAULT_WORKSPACE', 'LaraWA'),
];
