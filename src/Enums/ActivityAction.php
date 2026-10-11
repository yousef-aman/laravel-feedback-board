<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Enums;

enum ActivityAction: string
{
    case Created = 'created';
    case StatusChanged = 'status_changed';
    case Replied = 'replied';
    case Assigned = 'assigned';
    case PriorityChanged = 'priority_changed';
    case TypeChanged = 'type_changed';

    public function label(): string
    {
        return __('feedback-board::feedback-board.activity.'.$this->value);
    }
}
