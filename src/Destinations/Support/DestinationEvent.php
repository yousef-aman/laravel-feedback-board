<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Destinations\Support;

use InvalidArgumentException;
use YousefAman\FeedbackBoard\Events\FeedbackItemCreated;
use YousefAman\FeedbackBoard\Events\FeedbackReplied;
use YousefAman\FeedbackBoard\Events\FeedbackStatusChanged;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

final class DestinationEvent
{
    public static function name(object $event): string
    {
        return match (true) {
            $event instanceof FeedbackItemCreated => 'feedback.created',
            $event instanceof FeedbackReplied => 'feedback.replied',
            $event instanceof FeedbackStatusChanged => 'feedback.status_changed',
            default => throw new InvalidArgumentException('Unknown feedback event: '.$event::class),
        };
    }

    /**
     * Resolve the item fresh; null if the item (or the comment's parent) was deleted.
     */
    public static function item(object $event): ?FeedbackItem
    {
        $id = match (true) {
            $event instanceof FeedbackItemCreated => $event->item->id,
            $event instanceof FeedbackStatusChanged => $event->item->id,
            $event instanceof FeedbackReplied => $event->comment->feedback_item_id,
            default => throw new InvalidArgumentException('Unknown feedback event: '.$event::class),
        };

        return $id === null ? null : FeedbackItem::find($id);
    }
}
