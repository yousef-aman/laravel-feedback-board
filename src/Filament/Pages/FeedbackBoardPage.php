<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Filament\Pages;

use Filament\Pages\Page;
use YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy;

class FeedbackBoardPage extends Page
{
    protected static ?string $navigationLabel = 'Feedback board';

    protected string $view = 'feedback-board::filament.board-page';

    public static function canAccess(): bool
    {
        return FeedbackItemPolicy::isDeveloper(auth()->user());
    }

    public function mount(): void
    {
        abort_unless(FeedbackItemPolicy::isDeveloper(auth()->user()), 403);
    }
}
