<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Filament\Pages;

use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy;

class FeedbackBoardPage extends Page
{
    protected string $view = 'feedback-board::filament.board-page';

    // Six 16rem status columns never fit Filament's default 7xl content width.
    protected Width|string|null $maxContentWidth = Width::Full;

    public static function getNavigationLabel(): string
    {
        return static::$navigationLabel ?? __('feedback-board::feedback-board.board.title');
    }

    public function getTitle(): string
    {
        return static::$title ?? __('feedback-board::feedback-board.board.title');
    }

    public static function canAccess(): bool
    {
        return FeedbackItemPolicy::isDeveloper(auth()->user());
    }

    public function mount(): void
    {
        abort_unless(FeedbackItemPolicy::isDeveloper(auth()->user()), 403);
    }
}
