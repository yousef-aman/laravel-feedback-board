<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Destinations\Drivers;

use YousefAman\FeedbackBoard\Destinations\Contracts\Destination;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class MailDriver implements Destination
{
    /**
     * Deliberate NO-OP in the MVP.
     *
     * Mail delivery is already handled by Task 9's notification layer
     * (NewFeedbackNotification / FeedbackRepliedNotification /
     * FeedbackResolvedNotification dispatched via SendFeedbackNotifications).
     * This driver exists solely for contract parity so that future drivers
     * (Slack, webhook, …) can be swapped in via the DestinationManager
     * without touching any call-site code.
     */
    public function send(FeedbackItem $item, object $event): void
    {
        // No-op: mail is handled by the notifications layer.
    }
}
