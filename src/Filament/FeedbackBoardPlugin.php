<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use YousefAman\FeedbackBoard\Filament\Pages\FeedbackBoardPage;
use YousefAman\FeedbackBoard\Filament\Resources\FeedbackItemResource;

class FeedbackBoardPlugin implements Plugin
{
    public function getId(): string
    {
        return 'feedback-board';
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public function register(Panel $panel): void
    {
        $panel->resources([FeedbackItemResource::class])
            ->pages([FeedbackBoardPage::class]);
    }

    public function boot(Panel $panel): void {}
}
