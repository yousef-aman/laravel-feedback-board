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
use YousefAman\FeedbackBoard\Enums\{FeedbackPriority, FeedbackStatus, FeedbackType};
use YousefAman\FeedbackBoard\Facades\FeedbackBoard;
use YousefAman\FeedbackBoard\Runner\Support\RunnerGate;
use YousefAman\FeedbackBoard\Runner\Support\RunnerState;
use YousefAman\FeedbackBoard\Triage\Support\TriageGate;
use YousefAman\FeedbackBoard\Filament\Resources\FeedbackItemResource\Pages\ListFeedbackItems;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy;
use YousefAman\FeedbackBoard\Support\Ui;

class FeedbackItemResource extends Resource
{
    protected static ?string $model = FeedbackItem::class;

    // Reports belong to the app, not to a tenant. FeedbackItem has no ownership relationship, so
    // Filament's tenancy scope would throw on every query in a panel with ->tenant(...).
    protected static bool $isScopedToTenant = false;

    public static function canViewAny(): bool
    {
        return FeedbackItemPolicy::isDeveloper(FeedbackBoard::user());
    }

    public static function getModelLabel(): string
    {
        return __('feedback-board::feedback-board.resource.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('feedback-board::feedback-board.resource.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('feedback-board::feedback-board.nav.all_reports');
    }

    public static function getNavigationGroup(): string
    {
        return __('feedback-board::feedback-board.nav.group');
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-inbox-stack';
    }

    public static function getNavigationSort(): int
    {
        return 3;
    }

    /** The report's GitHub issue address, when it is http(s). */
    private static function githubIssueUrl(FeedbackItem $record): ?string
    {
        return Ui::safeUrl(($record->external_refs ?? [])['github']['html_url'] ?? null);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->label(__('feedback-board::feedback-board.resource.columns.title'))->searchable()->limit(40),
            // The enums do not implement Filament's HasLabel/HasColor: Filament is optional.
            TextColumn::make('type')->label(__('feedback-board::feedback-board.resource.columns.type'))->badge()
                ->formatStateUsing(fn (?FeedbackType $state): string => $state?->label() ?? '')
                ->color(fn (?FeedbackType $state): string => $state?->color() ?? 'gray'),
            TextColumn::make('status')->label(__('feedback-board::feedback-board.resource.columns.status'))->badge()
                ->formatStateUsing(fn (?FeedbackStatus $state): string => $state?->label() ?? '')
                ->color(fn (?FeedbackStatus $state): string => $state?->color() ?? 'gray'),
            TextColumn::make('priority')->label(__('feedback-board::feedback-board.resource.columns.priority'))->badge()->toggleable()
                ->formatStateUsing(fn (?FeedbackPriority $state): string => $state?->label() ?? '')
                ->color(fn (?FeedbackPriority $state): string => $state?->color() ?? 'gray'),
            TextColumn::make('reporter_name')->label(__('feedback-board::feedback-board.resource.columns.reporter'))->toggleable(),
            TextColumn::make('created_at')->label(__('feedback-board::feedback-board.resource.columns.created_at'))
                ->since()->dateTimeTooltip()->sortable(),
        ])->recordActions([
            Action::make('openGithubIssue')
                ->label(fn (FeedbackItem $record): string => __('feedback-board::feedback-board.destinations.github_issue', [
                    'number' => ($record->external_refs ?? [])['github']['issue_number'] ?? '',
                ]))
                ->visible(fn (FeedbackItem $record): bool => self::githubIssueUrl($record) !== null)
                ->url(fn (FeedbackItem $record): ?string => self::githubIssueUrl($record))
                ->openUrlInNewTab(),

            Action::make('sendToGithub')
                ->label(__('feedback-board::feedback-board.detail.send_github'))
                ->requiresConfirmation()
                ->modalDescription(__('feedback-board::feedback-board.detail.send_github_confirm'))
                ->visible(fn (FeedbackItem $record): bool =>
                    FeedbackItemPolicy::isDeveloper(FeedbackBoard::user())
                    && (bool) config('feedback-board.destinations.github.token')
                    && (bool) config('feedback-board.destinations.github.repo')
                    && ! isset(($record->external_refs ?? [])['github']))
                ->action(fn (FeedbackItem $record) =>
                    // Hard authorization lives inside SendItemToGithub::handle()
                    // (abort_unless) — UI visibility above is not the gate.
                    app(SendItemToGithub::class)->handle($record, FeedbackBoard::user())),

            Action::make('triage')
                ->label(__('feedback-board::feedback-board.triage.triage'))
                ->visible(fn (): bool => FeedbackItemPolicy::isDeveloper(FeedbackBoard::user()) && TriageGate::configured())
                ->action(fn (FeedbackItem $record) =>
                    app(RequestTriage::class)->handle($record, FeedbackBoard::user())),

            Action::make('applyType')
                ->label(__('feedback-board::feedback-board.triage.apply_type'))
                ->visible(fn (FeedbackItem $record): bool =>
                    FeedbackItemPolicy::isDeveloper(FeedbackBoard::user())
                    && ($record->ai_state['triage']['status'] ?? null) === 'completed'
                    && ($t = $record->ai_state['triage']['suggested_type'] ?? null) !== null
                    && $t !== $record->type?->value)
                ->action(fn (FeedbackItem $record) =>
                    app(ApplyTriageSuggestion::class)->handle($record, FeedbackBoard::user(), ['type'])),

            Action::make('applyPriority')
                ->label(__('feedback-board::feedback-board.triage.apply_priority'))
                ->visible(fn (FeedbackItem $record): bool =>
                    FeedbackItemPolicy::isDeveloper(FeedbackBoard::user())
                    && ($record->ai_state['triage']['status'] ?? null) === 'completed'
                    && ($p = $record->ai_state['triage']['suggested_priority'] ?? null) !== null
                    && $p !== $record->priority?->value)
                ->action(fn (FeedbackItem $record) =>
                    app(ApplyTriageSuggestion::class)->handle($record, FeedbackBoard::user(), ['priority'])),

            Action::make('viewSuggestion')
                ->label(__('feedback-board::feedback-board.triage.view_suggestion'))
                ->visible(fn (FeedbackItem $record): bool =>
                    FeedbackItemPolicy::isDeveloper(FeedbackBoard::user())
                    && ($record->ai_state['triage']['status'] ?? null) === 'completed')
                ->modalContent(fn (FeedbackItem $record) => view(
                    'feedback-board::filament.triage-suggestion',
                    ['triage' => $record->ai_state['triage'] ?? []],
                ))
                ->modalSubmitAction(false),

            Action::make('sendToRunner')
                ->label(__('feedback-board::feedback-board.runner.send'))
                ->requiresConfirmation()
                ->modalDescription(fn (FeedbackItem $record): string => __(isset($record->ai_state['runner'])
                    ? 'feedback-board::feedback-board.runner.resend_confirm'
                    : 'feedback-board::feedback-board.runner.send_confirm'))
                ->visible(fn (FeedbackItem $record): bool =>
                    FeedbackItemPolicy::isDeveloper(FeedbackBoard::user())
                    && RunnerGate::configured()
                    && (bool) config('feedback-board.ingest.enabled')
                    && RunnerState::canDispatch($record->ai_state['runner'] ?? null))
                ->action(fn (FeedbackItem $record) =>
                    // Hard authorization lives inside DispatchToRunner::handle() (abort_unless).
                    app(DispatchToRunner::class)->handle($record, FeedbackBoard::user())),

            Action::make('viewRunnerResult')
                ->label(__('feedback-board::feedback-board.runner.view_result'))
                ->visible(fn (FeedbackItem $record): bool =>
                    FeedbackItemPolicy::isDeveloper(FeedbackBoard::user())
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
