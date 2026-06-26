<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use YousefAman\FeedbackBoard\Enums\FeedbackStatus;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class FeedbackStatusChanged
{
    use Dispatchable;

    public function __construct(
        public FeedbackItem $item,
        public FeedbackStatus $from,
        public FeedbackStatus $to,
        public ?Model $actor,
    ) {}
}
