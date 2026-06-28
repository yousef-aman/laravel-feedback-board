<?php

return [
    'project' => env('FEEDBACK_BOARD_PROJECT', 'default'),

    'authorization' => [
        // Return true for users allowed to manage (triage/resolve) feedback.
        'developer_resolver' => null, // e.g. fn ($user) => $user->is_admin === true
    ],

    'attachments' => [
        'disk' => env('FEEDBACK_BOARD_DISK', 'local'), // private by default
        'directory' => 'feedback-board',
        'max_kb' => 10240, // 10 MB
        'allowed_mimes' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'log'],
    ],

    'route_prefix' => 'feedback-board',
    'route_middleware' => ['web', 'auth'],

    'notifications' => [
        // array of emails OR a callable returning a Collection of notifiables
        'developers' => array_filter([env('FEEDBACK_BOARD_DEVELOPER_EMAIL')]),
        'channels' => ['mail', 'database'],
    ],

    'destinations' => [
        'default' => 'mail',        // retained for contract parity (MailDriver no-op)

        // Fully-automatic drivers only. GitHub is intentionally absent:
        // its first issue is created by the manual developer action.
        'enabled' => [],            // e.g. ['webhook', 'slack']

        // Atomic cache store backing the per-item GitHub lock and the *_last_error
        // write. MUST be one of redis|memcached|dynamodb|database. null = app default
        // store. WARNING: if the resolved store is not a LockProvider (e.g. the `file`
        // driver), Cache::store(...)->lock() THROWS on the first GitHub delivery and the
        // first failure write — set this to an atomic store in any app whose default
        // cache is `file`. `array` is atomic but process-local (tests use it).
        'lock_store' => env('FEEDBACK_DESTINATIONS_LOCK_STORE'),

        'webhook' => [
            'url' => env('FEEDBACK_WEBHOOK_URL'),
            'secret' => env('FEEDBACK_WEBHOOK_SECRET'),
            'events' => ['feedback.created', 'feedback.replied', 'feedback.status_changed'],
        ],

        'slack' => [
            'webhook_url' => env('FEEDBACK_SLACK_WEBHOOK_URL'),
            'events' => ['feedback.created', 'feedback.status_changed'],
        ],

        'github' => [
            'token' => env('FEEDBACK_GITHUB_TOKEN'),
            'repo' => env('FEEDBACK_GITHUB_REPO'),          // "owner/name"
            'events' => ['feedback.replied', 'feedback.status_changed'],  // auto-comment gate only
            'close_on_resolved' => false,
        ],
    ],

    'ingest' => [
        'enabled' => env('FEEDBACK_BOARD_INGEST_ENABLED', false),
        'secret' => env('FEEDBACK_BOARD_INGEST_SECRET'),
        'tolerance' => 300, // seconds
    ],

    'ai' => [
        // Opt-in. With this false — or the selected driver unconfigured — the
        // listener does not dispatch and the manual action returns 422.
        'enabled' => env('FEEDBACK_BOARD_AI_ENABLED', false),

        'default' => env('FEEDBACK_BOARD_AI_DRIVER', 'anthropic'),

        'queue' => env('FEEDBACK_BOARD_AI_QUEUE'), // null = default queue

        // Atomic cache store backing the per-item ai_state lock. MUST be a
        // LockProvider (redis|memcached|dynamodb|database). null = app default.
        // `array` is atomic but process-local (tests use it). Same caveat as
        // destinations.lock_store: a `file` store THROWS on lock().
        'lock_store' => env('FEEDBACK_BOARD_AI_LOCK_STORE'),

        // Combined title+description budget sent to the model (cost bound).
        'max_input_chars' => 4000,

        'anthropic' => [
            'api_key' => env('FEEDBACK_BOARD_ANTHROPIC_KEY'),
            'model' => env('FEEDBACK_BOARD_ANTHROPIC_MODEL', 'claude-haiku-4-5'),
            'timeout' => 30,
            'max_tokens' => 1024,
        ],
    ],
];
