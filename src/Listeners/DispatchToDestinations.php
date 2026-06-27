<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Listeners;

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Str;
use YousefAman\FeedbackBoard\Destinations\Support\DestinationEvent;
use YousefAman\FeedbackBoard\Events\FeedbackItemCreated;
use YousefAman\FeedbackBoard\Events\FeedbackReplied;
use YousefAman\FeedbackBoard\Events\FeedbackStatusChanged;
use YousefAman\FeedbackBoard\Jobs\DeliverToDestination;

/**
 * Sole owner of event-name filtering. Synchronously fans an event out to one
 * queued DeliverToDestination job per (driver) that should receive it. Not
 * ShouldQueue itself — the queued unit is the per-destination job.
 */
class DispatchToDestinations
{
    public function onCreated(FeedbackItemCreated $event): void
    {
        $this->fanOut($event);
    }

    public function onReplied(FeedbackReplied $event): void
    {
        // Internal notes never leave the app — drop before any job is enqueued.
        if ($event->comment->is_internal) {
            return;
        }
        $this->fanOut($event);
    }

    public function onStatusChanged(FeedbackStatusChanged $event): void
    {
        $this->fanOut($event);
    }

    protected function fanOut(object $event): void
    {
        $item = DestinationEvent::item($event);
        if ($item === null) {
            return;
        }
        $name = DestinationEvent::name($event);

        // Automatic drivers (webhook, slack) listed in `enabled`, filtered by their event list.
        foreach ((array) config('feedback-board.destinations.enabled', []) as $driver) {
            $events = (array) config("feedback-board.destinations.{$driver}.events", []);
            if (in_array($name, $events, true)) {
                DeliverToDestination::dispatch($event, (string) $driver, (string) Str::uuid());
            }
        }

        // GitHub auto-comment gate: never in `enabled`; only after a ref exists
        // (which guarantees the first create came from the manual action).
        $this->maybeDispatchGithub($event, $name, $item);
    }

    protected function maybeDispatchGithub(object $event, string $name, \YousefAman\FeedbackBoard\Models\FeedbackItem $item): void
    {
        $configured = (string) config('feedback-board.destinations.github.token') !== ''
            && (string) config('feedback-board.destinations.github.repo') !== '';
        $events = (array) config('feedback-board.destinations.github.events', []);
        $hasRef = isset(($item->external_refs ?? [])['github']);

        if ($configured && $hasRef && in_array($name, $events, true)) {
            DeliverToDestination::dispatch($event, 'github', (string) Str::uuid());
        }
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            FeedbackItemCreated::class => 'onCreated',
            FeedbackReplied::class => 'onReplied',
            FeedbackStatusChanged::class => 'onStatusChanged',
        ];
    }
}
