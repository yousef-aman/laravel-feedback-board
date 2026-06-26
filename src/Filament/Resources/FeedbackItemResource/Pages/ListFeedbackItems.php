<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Filament\Resources\FeedbackItemResource\Pages;

use Filament\Resources\Pages\ListRecords;
use YousefAman\FeedbackBoard\Filament\Resources\FeedbackItemResource;
use YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy;

class ListFeedbackItems extends ListRecords
{
    protected static string $resource = FeedbackItemResource::class;

    public function mount(): void
    {
        abort_unless(FeedbackItemPolicy::isDeveloper(auth()->user()), 403);
        parent::mount();
    }
}
