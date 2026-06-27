<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Filament\Resources;

use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use YousefAman\FeedbackBoard\Actions\SendItemToGithub;
use YousefAman\FeedbackBoard\Filament\Resources\FeedbackItemResource\Pages\ListFeedbackItems;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy;

class FeedbackItemResource extends Resource
{
    protected static ?string $model = FeedbackItem::class;

    public static function canViewAny(): bool
    {
        return FeedbackItemPolicy::isDeveloper(auth()->user());
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->searchable()->limit(40),
            TextColumn::make('type')->badge(),
            TextColumn::make('status')->badge(),
            TextColumn::make('priority')->badge()->toggleable(),
            TextColumn::make('reporter_name')->toggleable(),
            TextColumn::make('created_at')->dateTime()->sortable(),
        ])->recordActions([
            Action::make('sendToGithub')
                ->label('Send to GitHub')
                ->visible(fn (FeedbackItem $record): bool =>
                    FeedbackItemPolicy::isDeveloper(auth()->user())
                    && (bool) config('feedback-board.destinations.github.token')
                    && (bool) config('feedback-board.destinations.github.repo')
                    && ! isset(($record->external_refs ?? [])['github']))
                ->action(fn (FeedbackItem $record) =>
                    // Hard authorization lives inside SendItemToGithub::handle()
                    // (abort_unless) — UI visibility above is not the gate.
                    app(SendItemToGithub::class)->handle($record, auth()->user())),
        ])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListFeedbackItems::route('/')];
    }
}
