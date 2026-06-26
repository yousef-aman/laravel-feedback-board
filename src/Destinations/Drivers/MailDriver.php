<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Destinations\Drivers;

use YousefAman\FeedbackBoard\Destinations\Contracts\DestinationDriver;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class MailDriver implements DestinationDriver
{
    /**
     * Deliberate NO-OP in the MVP.
     *
     * Mail delivery is already handled by the notification layer
     * (NewFeedbackNotification / FeedbackRepliedNotification /
     * FeedbackResolvedNotification dispatched via SendFeedbackNotifications).
     * This driver exists solely for contract parity so that future drivers
     * (Slack, webhook, …) can be swapped in via the DestinationManager
     * without touching any call-site code.
     */
    public function send(FeedbackItem $item): void
    {
        // No-op: mail is handled by the notifications layer.
    }
}
