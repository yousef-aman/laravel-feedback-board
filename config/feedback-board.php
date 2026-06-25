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
];
