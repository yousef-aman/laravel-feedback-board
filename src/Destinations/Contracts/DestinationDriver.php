<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Destinations\Contracts;

use YousefAman\FeedbackBoard\Models\FeedbackItem;

interface DestinationDriver
{
    /**
     * Send (or forward) a feedback item to the destination.
     */
    public function send(FeedbackItem $item): void;
}
