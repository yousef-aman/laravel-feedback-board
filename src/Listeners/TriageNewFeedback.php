<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Listeners;

use Illuminate\Events\Dispatcher;
use YousefAman\FeedbackBoard\Events\FeedbackItemCreated;
use YousefAman\FeedbackBoard\Triage\Support\TriageDispatcher;
use YousefAman\FeedbackBoard\Triage\Support\TriageGate;

/**
 * Synchronous listener (intentionally NOT ShouldQueue — the only queued unit is
 * TriageFeedbackItem, which carries the scalar id). Dispatches nothing unless
 * triage is fully configured, so enabling AI without a key marks nothing failed.
 */
class TriageNewFeedback
{
    public function handle(FeedbackItemCreated $event): void
    {
        if (! TriageGate::configured()) {
            return;
        }
        TriageDispatcher::dispatch($event->item);
    }

    public function subscribe(Dispatcher $events): array
    {
        return [FeedbackItemCreated::class => 'handle'];
    }
}
