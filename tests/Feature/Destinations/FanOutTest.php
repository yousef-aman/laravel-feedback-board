<?php

use Illuminate\Support\Facades\Bus;
use YousefAman\FeedbackBoard\Events\{FeedbackItemCreated, FeedbackReplied, FeedbackStatusChanged};
use YousefAman\FeedbackBoard\Enums\FeedbackStatus;
use YousefAman\FeedbackBoard\Jobs\DeliverToDestination;
use YousefAman\FeedbackBoard\Listeners\DispatchToDestinations;
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};

function fanout(): DispatchToDestinations {
    return app(DispatchToDestinations::class);
}

beforeEach(function () {
    config()->set('feedback-board.destinations.enabled', ['webhook', 'slack']);
});

it('fans out only to drivers whose event filter matches', function () {
    Bus::fake();
    $item = FeedbackItem::factory()->create();

    fanout()->onCreated(new FeedbackItemCreated($item)); // webhook+slack both list feedback.created

    Bus::assertDispatched(DeliverToDestination::class, fn ($j) => $j->driver === 'webhook' && $j->deliveryId !== '');
    Bus::assertDispatched(DeliverToDestination::class, fn ($j) => $j->driver === 'slack');
    Bus::assertDispatchedTimes(DeliverToDestination::class, 2);
});

it('drops an internal-note reply for every driver', function () {
    Bus::fake();
    $item = FeedbackItem::factory()->create();
    $comment = FeedbackComment::factory()->create(['feedback_item_id' => $item->id, 'is_internal' => true]);

    fanout()->onReplied(new FeedbackReplied($comment));

    Bus::assertNotDispatched(DeliverToDestination::class);
});

it('auto-dispatches GitHub only when a github ref already exists', function () {
    config()->set('feedback-board.destinations.github.token', 'ghp');
    config()->set('feedback-board.destinations.github.repo', 'a/b');
    Bus::fake();

    $without = FeedbackItem::factory()->create();
    fanout()->onStatusChanged(new FeedbackStatusChanged($without, FeedbackStatus::New, FeedbackStatus::Resolved, null));
    Bus::assertNotDispatched(DeliverToDestination::class, fn ($j) => $j->driver === 'github');

    $with = FeedbackItem::factory()->create(['external_refs' => ['github' => ['issue_number' => 1]]]);
    fanout()->onStatusChanged(new FeedbackStatusChanged($with, FeedbackStatus::New, FeedbackStatus::Resolved, null));
    Bus::assertDispatched(DeliverToDestination::class, fn ($j) => $j->driver === 'github');
});

it('never auto-dispatches GitHub on a created event', function () {
    config()->set('feedback-board.destinations.github.token', 'ghp');
    config()->set('feedback-board.destinations.github.repo', 'a/b');
    Bus::fake();
    $item = FeedbackItem::factory()->create(['external_refs' => ['github' => ['issue_number' => 1]]]);

    fanout()->onCreated(new FeedbackItemCreated($item));

    Bus::assertNotDispatched(DeliverToDestination::class, fn ($j) => $j->driver === 'github');
});
