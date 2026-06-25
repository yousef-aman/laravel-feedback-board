<?php

return [
    'project' => env('FEEDBACK_BOARD_PROJECT', 'default'),

    'authorization' => [
        // Return true for users allowed to manage (triage/resolve) feedback.
        'developer_resolver' => null, // e.g. fn ($user) => $user->is_admin === true
    ],
];
