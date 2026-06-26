<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Destinations\Contracts;

use YousefAman\FeedbackBoard\Models\FeedbackItem;

interface Destination
{
    /**
     * Send (or forward) a feedback item to the destination.
     */
    public function send(FeedbackItem $item, object $event): void;
}
