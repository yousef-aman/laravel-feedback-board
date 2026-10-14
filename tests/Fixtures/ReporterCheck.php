<?php

namespace YousefAman\FeedbackBoard\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;

/** Config-cache-safe callables for authorization.reporter_resolver (public static methods). */
class ReporterCheck
{
    public static function handle(Authenticatable $user): bool
    {
        return $user->name === 'Allowed';
    }

    public function __invoke(Authenticatable $user): bool
    {
        return true;
    }
}
