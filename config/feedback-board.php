<?php

return [
    'project' => env('FEEDBACK_BOARD_PROJECT', 'default'),

    'authorization' => [
        // Who may manage (triage/resolve) feedback. Prefer registering a callback in
        // AppServiceProvider::boot(): FeedbackBoard::resolveDevelopersUsing(...); it takes
        // precedence over this key. A closure here breaks `php artisan config:cache`; use
        // null or a public static method callable, e.g. [DeveloperCheck::class, 'handle'].
        'developer_resolver' => null,
    ],

    'attachments' => [
        'disk' => env('FEEDBACK_BOARD_DISK', 'local'), // private by default
        'directory' => 'feedback-board',
        'max_kb' => 10240, // 10 MB
        'allowed_mimes' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'log'],
    ],

    'route_prefix' => 'feedback-board',
    'route_middleware' => ['web', 'auth'],

    'ui' => [
        // Dark-mode strategy for the package's own screens:
        //   'class'  — dark when an ancestor element has the class "dark" (Filament panels, Laravel's
        //              Livewire/Flux starter kits, Tailwind's class strategy). The package's standalone
        //              pages have no host to set that class, so there they follow the operating system.
        //   'system' — follow the operating system (prefers-color-scheme).
        //   'light' / 'dark' — always light / always dark.
        'theme' => env('FEEDBACK_BOARD_THEME', 'class'),
    ],

    'notifications' => [
        // E-mail addresses to notify (FEEDBACK_BOARD_DEVELOPER_EMAIL may be comma-separated).
        // For notifiable models, register FeedbackBoard::notifyDevelopersUsing(...) in
        // AppServiceProvider::boot() instead; it takes precedence over this key. A closure
        // here breaks `php artisan config:cache`; a public static method callable is safe.
        'developers' => array_filter(array_map('trim', explode(',', (string) env('FEEDBACK_BOARD_DEVELOPER_EMAIL', '')))),

        // Add 'database' for in-app notifications after `php artisan make:notifications-table`
        // and `php artisan migrate` (and ->databaseNotifications() on a Filament panel).
        'channels' => ['mail'],
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

    'runner' => [
        // Opt-in. With this false — or endpoint/secret missing — the manual
        // "Send to agent" action returns 422 and the UI panel is hidden.
        'enabled' => env('FEEDBACK_BOARD_RUNNER_ENABLED', false),

        'endpoint' => env('FEEDBACK_RUNNER_ENDPOINT'),   // runner task-receive URL
        'secret' => env('FEEDBACK_RUNNER_SECRET'),       // HMAC secret for the outbound task

        'timeout' => 8,
        'connect_timeout' => 4,

        'max_analysis_chars' => 10000,   // cap on the analysis comment body
        'max_diff_chars' => 20000,       // cap on the stored suggested diff

        // Optional explicit ingest URL placed in the envelope's callback. If null,
        // the envelope omits it (the runner is pre-configured with its ingest URL).
        'callback_url' => env('FEEDBACK_RUNNER_CALLBACK_URL'),

        // UI-only "may be stale" hint after this many seconds. No background reaper.
        'stale_after' => 1800,

        // NOTE: all ai_state.runner writes reuse feedback-board.ai.lock_store, which
        // MUST be a LockProvider (redis|memcached|dynamodb|database) whenever
        // runner is enabled — a `file` store THROWS on lock(). The first runner write
        // happens synchronously inside the dispatch action.
    ],
];
