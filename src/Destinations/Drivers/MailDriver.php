<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Destinations\Drivers;

use YousefAman\FeedbackBoard\Destinations\Contracts\Destination;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class MailDriver implements Destination
{
    /**
     * Deliberate no-op.
     *
     * Mail delivery is handled by the notification layer
     * (NewFeedbackNotification / FeedbackRepliedNotification /
     * FeedbackResolvedNotification dispatched via SendFeedbackNotifications).
     * This driver exists for contract parity with the other destination drivers
     * resolved through the DestinationManager.
     */
    public function send(FeedbackItem $item, object $event, string $deliveryId): void
    {
        // No-op: mail is handled by the notifications layer.
    }
}
