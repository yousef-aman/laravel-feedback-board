<?php

use YousefAman\FeedbackBoard\Destinations\Drivers\MailDriver;
use YousefAman\FeedbackBoard\Events\{FeedbackItemCreated, FeedbackReplied, FeedbackStatusChanged};
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};
use Illuminate\Queue\SerializesModels;

it('MailDriver send accepts a deliveryId and stays a no-op', function () {
    $item = FeedbackItem::factory()->create();
    $event = new FeedbackItemCreated($item);
    expect((new MailDriver())->send($item, $event, 'delivery-1'))->toBeNull();
});

it('reply and status-changed events use SerializesModels', function () {
    $item = FeedbackItem::factory()->create();
    $comment = FeedbackComment::factory()->create(['feedback_item_id' => $item->id]);

    expect(in_array(SerializesModels::class, class_uses_recursive(new FeedbackReplied($comment)), true))->toBeTrue()
        ->and(in_array(SerializesModels::class, class_uses_recursive(
            new FeedbackStatusChanged($item, $item->status, $item->status, null)
        ), true))->toBeTrue();
});

it('a serialized status-changed event reloads the item fresh (not a frozen in-memory snapshot)', function () {
    $item = FeedbackItem::factory()->create(['title' => 'original']);
    $event = new FeedbackStatusChanged($item, $item->status, $item->status, null);

    // Mutate the DB row directly; the in-memory $event->item still holds 'original'.
    FeedbackItem::whereKey($item->id)->update(['title' => 'changed-in-db']);

    // SerializesModels stores only the id and re-queries on unserialize → it sees the
    // new DB value, NOT the stale in-memory snapshot. Remove the trait and plain
    // serialization would freeze 'original', so this assertion genuinely discriminates.
    $restored = unserialize(serialize($event));

    expect($restored->item->title)->toBe('changed-in-db');
});
