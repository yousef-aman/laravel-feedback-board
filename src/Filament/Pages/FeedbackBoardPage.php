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

    private const BADGE_MEMO = 'feedback-board.board-badge.';

    /**
     * Memoised on the current request's attributes, per developer: one computation per request
     * for all three badge methods, never shared across requests or users in a long-lived worker.
     * Filament checks canAccess() first, so this never runs for non-developers.
     *
     * @return array{badge: ?string, color: string, tooltip: ?string}
     */
    private static function badgeState(): array
    {
        $user = FeedbackBoard::user();
        $key = self::BADGE_MEMO.($user instanceof Model ? $user->getMorphClass().'.'.$user->getKey() : 'none');
        $attributes = request()->attributes;

        if ($attributes->has($key)) {
            return $attributes->get($key);
        }

        $open = FeedbackItem::query()
            ->whereIn('status', array_map(fn (FeedbackStatus $status) => $status->value, FeedbackStatus::openCases()))
            ->count();
        $news = $user instanceof Model ? DeveloperReads::cardsWithNews($user) : 0;

        $state = $news > 0
            ? [
                'badge' => (string) $news,
                'color' => 'danger',
                'tooltip' => __('feedback-board::feedback-board.nav.open_reports', ['count' => $open]),
            ]
            : ['badge' => $open > 0 ? (string) $open : null, 'color' => 'gray', 'tooltip' => null];

        $attributes->set($key, $state);

        return $state;
    }

    /** @internal Test seam: forgets the badge memo of the current request. */
    public static function forgetBadgeState(): void
    {
        $attributes = request()->attributes;

        foreach (array_keys($attributes->all()) as $key) {
            if (str_starts_with((string) $key, self::BADGE_MEMO)) {
                $attributes->remove($key);
            }
        }
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
