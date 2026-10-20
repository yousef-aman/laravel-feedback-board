<?php

use Livewire\Livewire;
use YousefAman\FeedbackBoard\Livewire\FeedbackDetail;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\TestUser;

// Sending a report out (to GitHub, or to the agent, which may open a pull request) asks first.

beforeEach(function () {
    config()->set('feedback-board.ai.lock_store', 'array');
    config()->set('feedback-board.destinations.github.token', 'ghp_test');
    config()->set('feedback-board.destinations.github.repo', 'acme/app');
    config()->set('feedback-board.runner.enabled', true);
    config()->set('feedback-board.runner.endpoint', 'https://runner.test/task');
    config()->set('feedback-board.runner.secret', 'rsecret');
    config()->set('feedback-board.ingest.enabled', true);
    config()->set('feedback-board.ingest.secret', 'ingsecret');
    config()->set('feedback-board.authorization.developer_resolver', fn ($u) => ($u->name ?? null) === 'Dev');
    $this->actingAs(TestUser::create(['name' => 'Dev']));
});

it('asks before sending a report to GitHub or to the agent', function () {
    $item = FeedbackItem::factory()->create();

    Livewire::test(FeedbackDetail::class, ['item' => $item])
        ->assertSeeHtml('wire:click="sendToGithub" wire:confirm="Open a GitHub issue for this report? Its title and description are sent to GitHub."')
        ->assertSeeHtml('wire:click="dispatchToRunner" wire:confirm="Send this report to the agent? It reads the title and description, and may open a pull request."');
});

it('asks before sending it to the agent again', function () {
    $item = FeedbackItem::factory()->create();
    $item->ai_state = ['runner' => ['status' => 'completed', 'task_id' => 'T1']];
    $item->save();

    Livewire::test(FeedbackDetail::class, ['item' => $item])
        ->assertSeeHtml('wire:click="dispatchToRunner" wire:confirm="Send this report to the agent again? A new run starts, and may open another pull request."');
});

it('asks in the app’s language', function () {
    app()->setLocale('ar');
    $item = FeedbackItem::factory()->create();

    Livewire::test(FeedbackDetail::class, ['item' => $item])
        ->assertSeeHtml('wire:confirm="إنشاء issue على GitHub لهذا البلاغ؟ سيُرسل عنوانه ووصفه إلى GitHub."')
        ->assertSeeHtml('wire:confirm="إرسال هذا البلاغ للوكيل؟ سيقرأ عنوانه ووصفه، وقد يفتح طلب دمج (pull request)."');
});
