<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class FeedbackItemCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public FeedbackItem $item) {}
}
