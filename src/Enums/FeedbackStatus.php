<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Enums;

enum FeedbackStatus: string
{
    case New = 'new';
    case InReview = 'in_review';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case Declined = 'declined';

    public static function default(): self { return self::New; }

    public function label(): string
    {
        return __('feedback-board::feedback-board.status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'gray',
            self::InReview => 'info',
            self::InProgress => 'warning',
            self::Resolved => 'success',
            self::Closed => 'gray',
            self::Declined => 'danger',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Resolved, self::Closed, self::Declined], true);
    }
}
