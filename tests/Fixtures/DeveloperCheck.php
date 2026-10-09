<?php

namespace YousefAman\FeedbackBoard\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;

/** Config-cache-safe callables for feedback-board config keys (public static methods). */
class DeveloperCheck
{
    public static function handle(Authenticatable $user): bool
    {
        return $user->name === 'Dev';
    }

    public function __invoke(Authenticatable $user): bool
    {
        return true;
    }
}
