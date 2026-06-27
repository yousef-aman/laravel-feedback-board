<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Destinations\Contracts;

use YousefAman\FeedbackBoard\Models\FeedbackItem;

interface Destination
{
    /**
     * Send (or forward) a feedback item to the destination.
     *
     * @param  string  $deliveryId  Stable UUID for this delivery (constant across retries);
     *                              used for receiver-side dedup and idempotency markers.
     */
    public function send(FeedbackItem $item, object $event, string $deliveryId): void;
}
