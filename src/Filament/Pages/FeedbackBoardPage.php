<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Filament\Pages;

use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use YousefAman\FeedbackBoard\Enums\FeedbackStatus;
use YousefAman\FeedbackBoard\Facades\FeedbackBoard;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
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

    public function getSubheading(): string
    {
        return __('feedback-board::feedback-board.kanban.subheading');
    }

    public static function getNavigationGroup(): string
    {
        return __('feedback-board::feedback-board.nav.group');
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-view-columns';
    }

    public static function getNavigationSort(): int
    {
        return 2;
    }

    /** Open reports. Filament checks canAccess() first, so this never runs for non-developers. */
    public static function getNavigationBadge(): ?string
    {
        $count = FeedbackItem::query()
            ->whereIn('status', array_map(fn (FeedbackStatus $status) => $status->value, FeedbackStatus::openCases()))
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'gray';
    }

    public static function canAccess(): bool
    {
        return FeedbackItemPolicy::isDeveloper(FeedbackBoard::user());
    }

    public function mount(): void
    {
        abort_unless(FeedbackItemPolicy::isDeveloper(FeedbackBoard::user()), 403);
    }
}
