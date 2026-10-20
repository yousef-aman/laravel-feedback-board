<?php

return [
    'project' => env('FEEDBACK_BOARD_PROJECT', 'default'),

    'auth' => [
        // Guards whose users the package recognises, reporters and developers alike. null = the guard
        // in use: a Filament panel's guard inside the panel, the app's default guard elsewhere.
        // List every guard your reporters sign in with, e.g. ['web', 'client'].
        'guards' => null,

        // Guards whose users may be developers. Required when `guards` lists more than one guard;
        // without it nobody is a developer. null with a single guard = that guard.
        'developer_guards' => null, // e.g. ['web']
    ],

    'authorization' => [
        // Who may manage (triage/resolve) feedback. Prefer registering a callback in
        // AppServiceProvider::boot(): FeedbackBoard::resolveDevelopersUsing(...); it takes
        // precedence over this key. A closure here breaks `php artisan config:cache`; use
        // null or a public static method callable, e.g. [DeveloperCheck::class, 'handle'].
        'developer_resolver' => null,

        // Who may send reports; null = every signed-in user the package recognises. Same rules as
        // developer_resolver: FeedbackBoard::resolveReportersUsing(...) takes precedence, and a closure
        // here breaks `php artisan config:cache`.
        'reporter_resolver' => null,
    ],

    'attachments' => [
        'disk' => env('FEEDBACK_BOARD_DISK', 'local'), // private by default
        'directory' => 'feedback-board',
        'max_kb' => 10240, // 10 MB
        'allowed_mimes' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'log'],
        // Files per report form and per comment (comment composers accept the image types above only).
        'max_files' => 10,
    ],

    'route_prefix' => 'feedback-board',
    // feedback-board.auth signs the package routes in as a user of auth.guards. Middleware that needs
    // the signed-in user (e.g. 'verified') goes after it; the package appends it when it is missing.
    'route_middleware' => ['web', 'feedback-board.auth'],

    'ui' => [
        // Dark-mode strategy for the package's own screens:
        //   'class'  — dark when an ancestor element has the class "dark" (Filament panels, Laravel's
        //              Livewire/Flux starter kits, Tailwind's class strategy). The package's standalone
        //              pages have no host to set that class, so they stay light.
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

        // Cache store whose lock guards the per-item GitHub delivery, the *_last_error
        // write and the runner's PR link; without Filament it also counts the day of the
        // developers' delivery-failure e-mail. null = app default store. The lock must be
        // shared by every worker: `file` only coordinates workers on one server, so with
        // several servers use redis|memcached|dynamodb|database. `array` is
        // process-local (tests use it).
        'lock_store' => env('FEEDBACK_DESTINATIONS_LOCK_STORE'),

        'webhook' => [
            'url' => env('FEEDBACK_WEBHOOK_URL'),
            'secret' => env('FEEDBACK_WEBHOOK_SECRET'),
            'events' => ['feedback.created', 'feedback.replied', 'feedback.status_changed'],
        ],

        'slack' => [
            'webhook_url' => env('FEEDBACK_SLACK_WEBHOOK_URL'),
            'events' => ['feedback.created', 'feedback.status_changed'],
            // The messages' language, e.g. 'ar'. null = the app's (the current locale when the queue is `sync`).
            'locale' => env('FEEDBACK_SLACK_LOCALE'),
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

        // anthropic | gemini | openai, or a driver added with FeedbackBoard::extendTriage().
        'default' => env('FEEDBACK_BOARD_AI_DRIVER', 'anthropic'),

        'queue' => env('FEEDBACK_BOARD_AI_QUEUE'), // queue for triage jobs; null or '' = the connection's default queue

        // Cache store whose lock guards the per-item ai_state (triage and runner).
        // null = app default. Same rule as destinations.lock_store: with several
        // servers use a shared store; `array` is process-local (tests use it).
        'lock_store' => env('FEEDBACK_BOARD_AI_LOCK_STORE'),

        // Combined title+description budget sent to the model (cost bound).
        'max_input_chars' => 4000,

        'anthropic' => [
            'api_key' => env('FEEDBACK_BOARD_ANTHROPIC_KEY'),
            'model' => env('FEEDBACK_BOARD_ANTHROPIC_MODEL', 'claude-haiku-4-5'),
            'timeout' => 30,
            'max_tokens' => 1024,
        ],

        'gemini' => [
            'api_key' => env('FEEDBACK_BOARD_GEMINI_KEY'),
            'model' => env('FEEDBACK_BOARD_GEMINI_MODEL', 'gemini-3.5-flash-lite'),
            'timeout' => 30,
            // null = Gemini's own limit. Thinking tokens count toward a cap, so a small one can cut the answer off.
            'max_tokens' => null,
        ],

        // OpenAI's Chat Completions; base_url points it at any API that speaks the same protocol.
        'openai' => [
            'api_key' => env('FEEDBACK_BOARD_OPENAI_KEY'),
            'model' => env('FEEDBACK_BOARD_OPENAI_MODEL', 'gpt-6-luna'),
            'base_url' => env('FEEDBACK_BOARD_OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            // OpenAI's reasoning models call functions in Chat Completions only with 'none'.
            // Set it empty for a provider that rejects the field.
            'reasoning_effort' => env('FEEDBACK_BOARD_OPENAI_REASONING_EFFORT', 'none'),
            'timeout' => 30,
            'max_tokens' => null, // null = the provider's own limit; sent as max_completion_tokens
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

        // Seconds without an update after which an in-flight task counts as stale: the card dialog
        // says so and offers to re-send it (a new task_id; the old task's results get 409).
        // 0 = never. No background reaper.
        'stale_after' => 1800,

        // NOTE: all ai_state.runner writes reuse feedback-board.ai.lock_store (shared
        // across servers when there are several). The first runner write happens
        // synchronously inside the dispatch action.
    ],
];
