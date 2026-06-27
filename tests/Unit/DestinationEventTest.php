<?php

use YousefAman\FeedbackBoard\Destinations\Support\DestinationEvent;
use YousefAman\FeedbackBoard\Enums\FeedbackStatus;
use YousefAman\FeedbackBoard\Events\{FeedbackItemCreated, FeedbackReplied, FeedbackStatusChanged};
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};

it('maps each event to its canonical name', function () {
    $item = FeedbackItem::factory()->create();
    $comment = FeedbackComment::factory()->create(['feedback_item_id' => $item->id]);

    expect(DestinationEvent::name(new FeedbackItemCreated($item)))->toBe('feedback.created')
        ->and(DestinationEvent::name(new FeedbackReplied($comment)))->toBe('feedback.replied')
        ->and(DestinationEvent::name(new FeedbackStatusChanged($item, FeedbackStatus::New, FeedbackStatus::Resolved, null)))
            ->toBe('feedback.status_changed');
});

it('resolves the item from each event', function () {
    $item = FeedbackItem::factory()->create();
    $comment = FeedbackComment::factory()->create(['feedback_item_id' => $item->id]);

    expect(DestinationEvent::item(new FeedbackItemCreated($item))->id)->toBe($item->id)
        ->and(DestinationEvent::item(new FeedbackReplied($comment))->id)->toBe($item->id)
        ->and(DestinationEvent::item(new FeedbackStatusChanged($item, FeedbackStatus::New, FeedbackStatus::Resolved, null))->id)
            ->toBe($item->id);
});

it('returns null item when the underlying row was deleted', function () {
    $item = FeedbackItem::factory()->create();
    $event = new FeedbackItemCreated($item);
    $item->delete();
    expect(DestinationEvent::item($event))->toBeNull();
});

it('throws on an unknown event type', function () {
    DestinationEvent::name(new stdClass());
})->throws(InvalidArgumentException::class);
