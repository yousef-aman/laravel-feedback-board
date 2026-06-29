<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Filament\Resources;

use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use YousefAman\FeedbackBoard\Actions\ApplyTriageSuggestion;
use YousefAman\FeedbackBoard\Actions\DispatchToRunner;
use YousefAman\FeedbackBoard\Actions\RequestTriage;
use YousefAman\FeedbackBoard\Actions\SendItemToGithub;
use YousefAman\FeedbackBoard\Runner\Support\RunnerGate;
use YousefAman\FeedbackBoard\Triage\Support\TriageGate;
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

            Action::make('triage')
                ->label(__('feedback-board::feedback-board.triage.triage'))
                ->visible(fn (): bool => FeedbackItemPolicy::isDeveloper(auth()->user()) && TriageGate::configured())
                ->action(fn (FeedbackItem $record) =>
                    app(RequestTriage::class)->handle($record, auth()->user())),

            Action::make('applyType')
                ->label(__('feedback-board::feedback-board.triage.apply_type'))
                ->visible(fn (FeedbackItem $record): bool =>
                    FeedbackItemPolicy::isDeveloper(auth()->user())
                    && ($record->ai_state['triage']['status'] ?? null) === 'completed'
                    && ($t = $record->ai_state['triage']['suggested_type'] ?? null) !== null
                    && $t !== $record->type?->value)
                ->action(fn (FeedbackItem $record) =>
                    app(ApplyTriageSuggestion::class)->handle($record, auth()->user(), ['type'])),

            Action::make('applyPriority')
                ->label(__('feedback-board::feedback-board.triage.apply_priority'))
                ->visible(fn (FeedbackItem $record): bool =>
                    FeedbackItemPolicy::isDeveloper(auth()->user())
                    && ($record->ai_state['triage']['status'] ?? null) === 'completed'
                    && ($p = $record->ai_state['triage']['suggested_priority'] ?? null) !== null
                    && $p !== $record->priority?->value)
                ->action(fn (FeedbackItem $record) =>
                    app(ApplyTriageSuggestion::class)->handle($record, auth()->user(), ['priority'])),

            Action::make('viewSuggestion')
                ->label(__('feedback-board::feedback-board.triage.view_suggestion'))
                ->visible(fn (FeedbackItem $record): bool =>
                    FeedbackItemPolicy::isDeveloper(auth()->user())
                    && ($record->ai_state['triage']['status'] ?? null) === 'completed')
                ->modalContent(fn (FeedbackItem $record) => view(
                    'feedback-board::filament.triage-suggestion',
                    ['triage' => $record->ai_state['triage'] ?? []],
                ))
                ->modalSubmitAction(false),

            Action::make('sendToRunner')
                ->label(__('feedback-board::feedback-board.runner.send'))
                ->visible(fn (FeedbackItem $record): bool =>
                    FeedbackItemPolicy::isDeveloper(auth()->user())
                    && RunnerGate::configured()
                    && (bool) config('feedback-board.ingest.enabled')
                    && ! in_array(($record->ai_state['runner']['status'] ?? null), ['dispatched', 'running'], true))
                ->action(fn (FeedbackItem $record) =>
                    // Hard authorization lives inside DispatchToRunner::handle() (abort_unless).
                    app(DispatchToRunner::class)->handle($record, auth()->user())),

            Action::make('viewRunnerResult')
                ->label(__('feedback-board::feedback-board.runner.view_result'))
                ->visible(fn (FeedbackItem $record): bool =>
                    FeedbackItemPolicy::isDeveloper(auth()->user())
                    && ($record->ai_state['runner']['status'] ?? null) !== null)
                ->modalContent(fn (FeedbackItem $record) => view(
                    'feedback-board::filament.runner-result',
                    ['runner' => $record->ai_state['runner'] ?? []],
                ))
                ->modalSubmitAction(false),
        ])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListFeedbackItems::route('/')];
    }
}
