<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Events;

use Illuminate\Foundation\Events\Dispatchable;
use YousefAman\FeedbackBoard\Models\FeedbackComment;

class FeedbackReplied
{
    use Dispatchable;
    public function __construct(public FeedbackComment $comment) {}
}
