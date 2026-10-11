<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Filament;

use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use YousefAman\FeedbackBoard\Filament\Pages\{FeedbackBoardPage, MyFeedbackPage};
use YousefAman\FeedbackBoard\Filament\Resources\FeedbackItemResource;

class FeedbackBoardPlugin implements Plugin
{
    protected bool $myReports = true;

    public function getId(): string
    {
        return 'feedback-board';
    }

    public static function make(): static
    {
        return app(static::class);
    }

    /** "My reports" for every user of this panel; on by default. */
    public function myReports(bool $condition = true): static
    {
        $this->myReports = $condition;

        return $this;
    }

    public function hasMyReports(): bool
    {
        return $this->myReports;
    }

    /**
     * This plugin on the current panel; null outside a panel or on a panel without it. Uses the
     * panel's own hasPlugin(): the Filament facade's helpers fall back to the default panel, which
     * could send a host-page user to a panel they cannot open.
     */
    public static function current(): ?self
    {
        $panel = Filament::getCurrentPanel();

        if ($panel === null || ! $panel->hasPlugin('feedback-board')) {
            return null;
        }

        $plugin = $panel->getPlugin('feedback-board');

        return $plugin instanceof self ? $plugin : null;
    }

    public function register(Panel $panel): void
    {
        $panel->resources([FeedbackItemResource::class])
            ->pages([MyFeedbackPage::class, FeedbackBoardPage::class])
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn (): string => PanelColors::style());
    }

    public function boot(Panel $panel): void {}
}
