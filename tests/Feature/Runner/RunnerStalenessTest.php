<?php

use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use YousefAman\FeedbackBoard\Actions\DispatchToRunner;
use YousefAman\FeedbackBoard\Jobs\DispatchToRunnerJob;
use YousefAman\FeedbackBoard\Livewire\FeedbackDetail;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Runner\Support\RunnerState;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    config()->set('feedback-board.ai.lock_store', 'array');
    config()->set('feedback-board.runner.enabled', true);
    config()->set('feedback-board.runner.endpoint', 'https://runner.test/task');
    config()->set('feedback-board.runner.secret', 'rsecret');
    config()->set('feedback-board.ingest.enabled', true);
    config()->set('feedback-board.ingest.secret', 'ingsecret');
    config()->set('feedback-board.authorization.developer_resolver', fn ($u) => ($u->name ?? null) === 'Dev');
    Bus::fake();
});

function hRunnerItem(array $runner): FeedbackItem
{
    $item = FeedbackItem::factory()->create();
    $item->ai_state = ['runner' => $runner];
    $item->save();

    return $item;
}

function hRunnerDev(): TestUser
{
    return TestUser::firstOrCreate(['name' => 'Dev']);
}

function hAgo(int $minutes): string
{
    return now()->subMinutes($minutes)->toIso8601String();
}

it('keeps debouncing a task with recent activity, however long ago it was dispatched', function () {
    $item = hRunnerItem(['status' => 'running', 'task_id' => 'live', 'dispatched_at' => hAgo(180), 'updated_at' => hAgo(5)]);

    (new DispatchToRunner())->handle($item, hRunnerDev());

    expect($item->refresh()->ai_state['runner']['task_id'])->toBe('live');
    Bus::assertNotDispatched(DispatchToRunnerJob::class);
});

it('re-sends a task with no activity for stale_after seconds', function () {
    $item = hRunnerItem(['status' => 'dispatched', 'task_id' => 'old', 'dispatched_at' => hAgo(31), 'updated_at' => hAgo(31)]);

    (new DispatchToRunner())->handle($item, hRunnerDev());

    $runner = $item->refresh()->ai_state['runner'];
    expect($runner['task_id'])->not->toBe('old')
        ->and($runner['status'])->toBe('dispatched');
    Bus::assertDispatchedTimes(DispatchToRunnerJob::class, 1);
});

it('starts one new task when two developers re-send the same stale task', function () {
    $item = hRunnerItem(['status' => 'running', 'task_id' => 'old', 'dispatched_at' => hAgo(40), 'updated_at' => hAgo(40)]);

    (new DispatchToRunner())->handle($item, hRunnerDev());
    (new DispatchToRunner())->handle(FeedbackItem::find($item->id), hRunnerDev());

    Bus::assertDispatchedTimes(DispatchToRunnerJob::class, 1);
});

it('decides staleness from the last activity', function () {
    expect(RunnerState::isStale(['status' => 'running', 'updated_at' => 'garbage']))->toBeTrue()
        ->and(RunnerState::isStale(['status' => 'running', 'task_id' => 'live']))->toBeFalse() // no timestamp: rows written by hand
        ->and(RunnerState::isStale(['status' => 'dispatched', 'dispatched_at' => hAgo(31)]))->toBeTrue()
        ->and(RunnerState::isStale(['status' => 'completed', 'updated_at' => hAgo(600)]))->toBeFalse()
        ->and(RunnerState::isStale(null))->toBeFalse()
        ->and(RunnerState::canDispatch(null))->toBeTrue()
        ->and(RunnerState::canDispatch(['status' => 'failed']))->toBeTrue()
        ->and(RunnerState::canDispatch(['status' => 'running', 'updated_at' => hAgo(5)]))->toBeFalse()
        ->and(RunnerState::canDispatch(['status' => 'running', 'updated_at' => hAgo(31)]))->toBeTrue();
});

it('never goes stale when stale_after is 0', function () {
    config()->set('feedback-board.runner.stale_after', 0);

    expect(RunnerState::isStale(['status' => 'running', 'updated_at' => hAgo(10000)]))->toBeFalse();
});

it('refuses a late result from the replaced task with 409', function () {
    $item = hRunnerItem(['status' => 'running', 'task_id' => 'old', 'dispatched_at' => hAgo(31), 'updated_at' => hAgo(31)]);
    (new DispatchToRunner())->handle($item, hRunnerDev());

    $body = json_encode(['item_id' => $item->id, 'action' => 'runner_status', 'task_id' => 'old', 'status' => 'completed']);
    $ts = (string) now()->timestamp;

    $this->call('POST', route('feedback-board.ingest'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_FEEDBACK_TIMESTAMP' => $ts,
        'HTTP_X_FEEDBACK_SIGNATURE' => hash_hmac('sha256', $ts.'.'.$body, 'ingsecret'),
    ], $body)->assertStatus(409);
});

it('shows the stale line and the re-send button in the card dialog', function () {
    $this->actingAs(hRunnerDev());
    $item = hRunnerItem(['status' => 'running', 'task_id' => 'old', 'dispatched_at' => hAgo(40), 'updated_at' => hAgo(35)]);

    Livewire::test(FeedbackDetail::class, ['item' => $item])
        ->assertSee('No update for')
        ->assertSee(__('feedback-board::feedback-board.runner.resend'));
});

it('hides the re-send button while a task is fresh', function () {
    $this->actingAs(hRunnerDev());
    $item = hRunnerItem(['status' => 'running', 'task_id' => 'live', 'dispatched_at' => hAgo(2), 'updated_at' => hAgo(1)]);

    Livewire::test(FeedbackDetail::class, ['item' => $item])
        ->assertDontSee('No update for')
        ->assertDontSee(__('feedback-board::feedback-board.runner.resend'));
});
