<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Filament\Pages;

use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;
use Throwable;
use YousefAman\FeedbackBoard\Filament\FeedbackBoardPlugin;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

/** The reporter's "My reports" inside the panel, for every signed-in user unless the plugin turns it off. */
class MyFeedbackPage extends Page
{
    protected string $view = 'feedback-board::filament.my-feedback-page';

    protected static ?string $slug = 'my-feedback';

    private static bool $warned = false;

    public static function getNavigationLabel(): string
    {
        return static::$navigationLabel ?? __('feedback-board::feedback-board.nav.my_reports');
    }

    public static function getNavigationGroup(): string
    {
        return __('feedback-board::feedback-board.nav.group');
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-chat-bubble-left-right';
    }

    public static function getNavigationSort(): int
    {
        return 1;
    }

    public function getTitle(): string
    {
        return static::$title ?? __('feedback-board::feedback-board.board.my_reports');
    }

    public function getSubheading(): string
    {
        return __('feedback-board::feedback-board.reports.subheading');
    }

    /** Read at call time from the current panel's plugin, so two panels can differ. */
    public static function canAccess(): bool
    {
        return auth()->check() && (FeedbackBoardPlugin::current()?->hasMyReports() ?? false);
    }

    /**
     * Reports with unread replies: one query on every panel page. A host that updated the package
     * but has not migrated yet keeps a working panel and gets one warning per process.
     */
    public static function getNavigationBadge(): ?string
    {
        return rescue(function (): ?string {
            $count = FeedbackItem::query()->ownedBy(auth()->user())->hasUnread()->count();

            return $count > 0 ? (string) $count : null;
        }, function (Throwable $e): ?string {
            if (! self::$warned) {
                self::$warned = true;
                Log::warning('feedback-board: the "My reports" badge failed; publish and run the package migrations.', ['exception' => $e->getMessage()]);
            }

            return null;
        }, report: false);
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'primary';
    }

    /** @internal Test seam: the warning is logged once per process. */
    public static function resetMigrationWarning(): void
    {
        self::$warned = false;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newReport')
                ->label(__('feedback-board::feedback-board.board.new_report'))
                ->icon('heroicon-o-plus')
                // MyReportsBoard owns the form; only it listens to this browser event.
                ->alpineClickHandler("\$dispatch('fb-open-report-form')"),
        ];
    }
}
