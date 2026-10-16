<?php

use Filament\Facades\Filament;
use Livewire\Livewire;
use YousefAman\FeedbackBoard\Facades\FeedbackBoard;
use YousefAman\FeedbackBoard\Filament\Resources\FeedbackItemResource\Pages\ListFeedbackItems;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\Filament\FilamentTestUser;

it('shows the Send to agent action for a stale task and hides it for a fresh one', function () {
    config()->set('feedback-board.ai.lock_store', 'array');
    config()->set('feedback-board.runner.enabled', true);
    config()->set('feedback-board.runner.endpoint', 'https://runner.test/task');
    config()->set('feedback-board.runner.secret', 'rsecret');
    config()->set('feedback-board.ingest.enabled', true);
    config()->set('feedback-board.ingest.secret', 'ingsecret');
    FeedbackBoard::resolveDevelopersUsing(fn ($user) => $user->name === 'Dev');
    $this->actingAs(FilamentTestUser::create(['name' => 'Dev', 'email' => 'dev@x.com']));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $stale = FeedbackItem::factory()->create();
    $stale->ai_state = ['runner' => ['status' => 'running', 'task_id' => 'old', 'updated_at' => now()->subMinutes(40)->toIso8601String()]];
    $stale->save();
    $fresh = FeedbackItem::factory()->create();
    $fresh->ai_state = ['runner' => ['status' => 'running', 'task_id' => 'live', 'updated_at' => now()->subMinute()->toIso8601String()]];
    $fresh->save();

    Livewire::test(ListFeedbackItems::class)
        ->assertTableActionVisible('sendToRunner', $stale)
        ->assertTableActionHidden('sendToRunner', $fresh);
});
