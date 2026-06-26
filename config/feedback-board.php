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
        // drivers are opt-in; Phase 2 enables webhook/github/slack/etc.
        // (Mail already flows through the notification layer; the MailDriver stays for contract parity.)
        'default' => 'mail',
        'enabled' => [],
    ],

    'ingest' => [
        'enabled' => env('FEEDBACK_BOARD_INGEST_ENABLED', false),
        'secret' => env('FEEDBACK_BOARD_INGEST_SECRET'),
        'tolerance' => 300, // seconds
    ],
];
