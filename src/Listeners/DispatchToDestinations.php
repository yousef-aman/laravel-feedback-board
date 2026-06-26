<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\Dispatcher;
use YousefAman\FeedbackBoard\Destinations\DestinationManager;
use YousefAman\FeedbackBoard\Events\FeedbackItemCreated;
use YousefAman\FeedbackBoard\Events\FeedbackReplied;
use YousefAman\FeedbackBoard\Events\FeedbackStatusChanged;

class DispatchToDestinations implements ShouldQueue
{
    public function __construct(protected DestinationManager $manager) {}

    public function onCreated(FeedbackItemCreated $event): void
    {
        $this->dispatchEnabled($event->item, $event);
    }

    public function onReplied(FeedbackReplied $event): void
    {
        $this->dispatchEnabled($event->comment->item, $event);
    }

    public function onStatusChanged(FeedbackStatusChanged $event): void
    {
        $this->dispatchEnabled($event->item, $event);
    }

    protected function dispatchEnabled(\YousefAman\FeedbackBoard\Models\FeedbackItem $item, object $event): void
    {
        $enabled = config('feedback-board.destinations.enabled', []);
        foreach ($enabled as $driverName) {
            $this->manager->driver($driverName)->send($item, $event);
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
