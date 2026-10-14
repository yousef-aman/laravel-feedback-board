<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Support\Concerns;

use Illuminate\Support\Facades\Gate;
use YousefAman\FeedbackBoard\Facades\FeedbackBoard;

/**
 * Authorizes as FeedbackBoard::user(). Livewire's own authorize() reads the default guard only, which
 * on an update from a host page without guard middleware is the app default. The failure is the
 * usual AuthorizationException, rendered as a 403.
 */
trait AuthorizesFeedbackUser
{
    protected function authorizeFeedback(string $ability, mixed $arguments = []): void
    {
        Gate::forUser(FeedbackBoard::user())->authorize($ability, $arguments);
    }
}
