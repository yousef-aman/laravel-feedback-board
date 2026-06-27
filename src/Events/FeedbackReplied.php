<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use YousefAman\FeedbackBoard\Models\FeedbackComment;

class FeedbackReplied
{
    use Dispatchable, SerializesModels;
    public function __construct(public FeedbackComment $comment) {}
}
