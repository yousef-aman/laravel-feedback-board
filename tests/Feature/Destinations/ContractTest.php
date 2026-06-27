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

    expect(in_array(SerializesModels::class, class_uses(new FeedbackReplied($comment)), true))->toBeTrue()
        ->and(in_array(SerializesModels::class, class_uses(
            new FeedbackStatusChanged($item, $item->status, $item->status, null)
        ), true))->toBeTrue();
});

it('a serialized status-changed event reloads the item fresh', function () {
    $item = FeedbackItem::factory()->create(['title' => 'original']);
    $event = new FeedbackStatusChanged($item, $item->status, $item->status, null);
    $restored = unserialize(serialize($event));
    $item->update(['title' => 'changed-after-serialize']);
    expect($restored->item->fresh()->title)->toBe('changed-after-serialize'); // reloaded fresh, not the snapshot
});
