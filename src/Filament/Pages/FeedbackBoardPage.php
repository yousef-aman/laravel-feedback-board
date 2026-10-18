<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Filament\Pages;

use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use YousefAman\FeedbackBoard\Enums\FeedbackStatus;
use YousefAman\FeedbackBoard\Facades\FeedbackBoard;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy;
use YousefAman\FeedbackBoard\Support\DeveloperReads;

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

    /** Red with the cards that have something new, else gray with the open count. */
    public static function getNavigationBadge(): ?string
    {
        return self::badgeState()['badge'];
    }

    public static function getNavigationBadgeColor(): string
    {
        return self::badgeState()['color'];
    }

    /** The open count, shown on hover only while the badge is red. */
    public static function getNavigationBadgeTooltip(): ?string
    {
        return self::badgeState()['tooltip'];
    }

    /**
     * One computation per request for all three badge methods. Filament checks canAccess()
     * first, so this never runs for non-developers.
     *
     * @return array{badge: ?string, color: string, tooltip: ?string}
     */
    private static function badgeState(): array
    {
        return once(function (): array {
            $open = FeedbackItem::query()
                ->whereIn('status', array_map(fn (FeedbackStatus $status) => $status->value, FeedbackStatus::openCases()))
                ->count();
            $user = FeedbackBoard::user();
            $news = $user instanceof Model ? DeveloperReads::cardsWithNews($user) : 0;

            if ($news > 0) {
                return [
                    'badge' => (string) $news,
                    'color' => 'danger',
                    'tooltip' => __('feedback-board::feedback-board.nav.open_reports', ['count' => $open]),
                ];
            }

            return ['badge' => $open > 0 ? (string) $open : null, 'color' => 'gray', 'tooltip' => null];
        });
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
