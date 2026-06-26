<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Enums;

enum FeedbackType: string
{
    case Bug = 'bug';
    case Feature = 'feature';
    case Question = 'question';
    case Other = 'other';

    public function label(): string
    {
        return __('feedback-board::feedback-board.type.'.$this->value);
    }
}
