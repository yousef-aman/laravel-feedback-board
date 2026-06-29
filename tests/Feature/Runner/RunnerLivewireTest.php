<?php

use Livewire\Livewire;
use YousefAman\FeedbackBoard\Livewire\FeedbackDetail;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    config()->set('feedback-board.ai.lock_store', 'array');
    config()->set('feedback-board.runner.enabled', true);
    config()->set('feedback-board.runner.endpoint', 'https://runner.test/task');
    config()->set('feedback-board.runner.secret', 'rsecret');
    config()->set('feedback-board.ingest.enabled', true);
    config()->set('feedback-board.ingest.secret', 'ingsecret');
    config()->set('feedback-board.authorization.developer_resolver', fn ($u) => ($u->name ?? null) === 'Dev');
    $this->actingAs(TestUser::create(['name' => 'Dev']));
});

it('renders the completed runner panel and escapes the diff and pr_url', function () {
    $item = FeedbackItem::factory()->create();
    $item->ai_state = ['runner' => [
        'status' => 'completed', 'task_id' => 'T1',
        'diff' => '<script>x</script>', 'pr_url' => 'https://github.com/o/r/pull/3',
    ]];
    $item->save();

    Livewire::test(FeedbackDetail::class, ['item' => $item])
        ->assertSee('https://github.com/o/r/pull/3')
        ->assertDontSee('<script>x</script>', false);   // escaped, not raw
});

it('shows diff/pr_url even while status is running', function () {
    $item = FeedbackItem::factory()->create();
    $item->ai_state = ['runner' => ['status' => 'running', 'task_id' => 'T1', 'pr_url' => 'https://github.com/o/r/pull/4']];
    $item->save();

    Livewire::test(FeedbackDetail::class, ['item' => $item])->assertSee('https://github.com/o/r/pull/4');
});

it('dispatchToRunner is a no-op while in flight (no error)', function () {
    $item = FeedbackItem::factory()->create();
    $item->ai_state = ['runner' => ['status' => 'running', 'task_id' => 'live']];
    $item->save();

    Livewire::test(FeedbackDetail::class, ['item' => $item])
        ->call('dispatchToRunner')
        ->assertOk();
    expect($item->refresh()->ai_state['runner']['task_id'])->toBe('live');
});
