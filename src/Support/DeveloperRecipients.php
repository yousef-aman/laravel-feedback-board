<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

class DeveloperRecipients
{
    public function resolve(): Collection
    {
        $config = config('feedback-board.notifications.developers');
        if (is_callable($config)) {
            return collect($config());
        }
        return collect($config ?? [])->map(fn ($email) => Notification::route('mail', $email));
    }
}
