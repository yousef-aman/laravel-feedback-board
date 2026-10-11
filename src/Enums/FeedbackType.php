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

    /** An Icons name for the type tile. */
    public function icon(): string
    {
        return match ($this) {
            self::Bug => 'exclamation-triangle',
            self::Feature => 'light-bulb',
            self::Question => 'question-mark-circle',
            self::Other => 'ellipsis-horizontal-circle',
        };
    }

    /** One line under the type in the report form. */
    public function hint(): string
    {
        return __('feedback-board::feedback-board.type_hint.'.$this->value);
    }

    /** Filament badge colour (the resource table); the package's own views use type tiles. */
    public function color(): string
    {
        return match ($this) {
            self::Bug => 'danger',
            self::Feature => 'primary',
            self::Question => 'info',
            self::Other => 'gray',
        };
    }
}
