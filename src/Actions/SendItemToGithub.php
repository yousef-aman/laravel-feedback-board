<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use YousefAman\FeedbackBoard\Events\FeedbackItemCreated;
use YousefAman\FeedbackBoard\Jobs\DeliverToDestination;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy;

class SendItemToGithub
{
    /**
     * Hard-authorize, verify configuration, then enqueue the manual GitHub
     * issue creation. Both the Livewire method and the Filament action call this.
     */
    public function handle(FeedbackItem $item, ?Model $actor): void
    {
        abort_unless(FeedbackItemPolicy::isDeveloper($actor), 403);
        abort_unless(
            (bool) config('feedback-board.destinations.github.token')
                && (bool) config('feedback-board.destinations.github.repo'),
            422,
        );

        DeliverToDestination::dispatch(
            new FeedbackItemCreated($item),
            'github',
            (string) Str::uuid(),
        );
    }
}
