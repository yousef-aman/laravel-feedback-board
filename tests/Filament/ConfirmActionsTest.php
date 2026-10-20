<?php

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use YousefAman\FeedbackBoard\Facades\FeedbackBoard;
use YousefAman\FeedbackBoard\Filament\Resources\FeedbackItemResource\Pages\ListFeedbackItems;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\Filament\FilamentTestUser;

beforeEach(function () {
    Bus::fake();
    config()->set('feedback-board.ai.lock_store', 'array');
    config()->set('feedback-board.destinations.github.token', 'ghp_test');
    config()->set('feedback-board.destinations.github.repo', 'acme/app');
    config()->set('feedback-board.runner.enabled', true);
    config()->set('feedback-board.runner.endpoint', 'https://runner.test/task');
    config()->set('feedback-board.runner.secret', 'rsecret');
    config()->set('feedback-board.ingest.enabled', true);
    config()->set('feedback-board.ingest.secret', 'ingsecret');
    FeedbackBoard::resolveDevelopersUsing(fn ($user) => $user->name === 'Dev');
    $this->actingAs(FilamentTestUser::create(['name' => 'Dev', 'email' => 'dev@x.com']));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('asks before sending a report to GitHub from All reports', function () {
    $item = FeedbackItem::factory()->create();

    Livewire::test(ListFeedbackItems::class)
        ->assertTableActionExists('sendToGithub', fn (Action $action) => $action->isConfirmationRequired()
            && $action->getModalDescription() === 'Open a GitHub issue for this report? Its title and description are sent to GitHub.', $item)
        ->mountTableAction('sendToGithub', $item);

    Bus::assertNothingDispatched();
});

it('asks before sending a report to the agent from All reports, and says when it is again', function () {
    $new = FeedbackItem::factory()->create();
    $done = FeedbackItem::factory()->create();
    $done->ai_state = ['runner' => ['status' => 'completed', 'task_id' => 'T1']];
    $done->save();

    Livewire::test(ListFeedbackItems::class)
        ->assertTableActionExists('sendToRunner', fn (Action $action) => $action->isConfirmationRequired()
            && $action->getModalDescription() === 'Send this report to the agent? It reads the title and description, and may open a pull request.', $new)
        ->assertTableActionExists('sendToRunner', fn (Action $action) => $action->getModalDescription()
            === 'Send this report to the agent again? A new run starts, and may open another pull request.', $done)
        ->mountTableAction('sendToRunner', $new);

    Bus::assertNothingDispatched();
});
