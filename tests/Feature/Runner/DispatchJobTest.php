<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use YousefAman\FeedbackBoard\Jobs\DispatchToRunnerJob;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Runner\Exceptions\RunnerException;

beforeEach(function () {
    config()->set('feedback-board.ai.lock_store', 'array');
    config()->set('feedback-board.runner.endpoint', 'https://runner.test/task');
    config()->set('feedback-board.runner.secret', 'rsecret');
    Sleep::fake(); // the 503 retry case sleeps ~200ms via Http::retry; fake it (matches GithubIssueDriverTest)
});

function dispatchedItem(): FeedbackItem {
    $item = FeedbackItem::factory()->create();
    $item->ai_state = ['runner' => ['status' => 'dispatched', 'task_id' => 'live']];
    $item->save();
    return $item;
}

it('sends the signed envelope and leaves status dispatched on success', function () {
    Http::fake(['https://runner.test/*' => Http::response(['ok' => true], 200)]);
    $item = dispatchedItem();

    (new DispatchToRunnerJob($item->id, 'live'))->handle(app(\YousefAman\FeedbackBoard\Runner\RunnerDispatcher::class));

    Http::assertSent(fn ($r) => $r->header('X-Feedback-Delivery')[0] === 'live');
    expect($item->refresh()->ai_state['runner']['status'])->toBe('dispatched');
});

it('does NOT send when the task_id was superseded (stale dispatch)', function () {
    Http::fake();
    $item = dispatchedItem(); // task_id = 'live'

    (new DispatchToRunnerJob($item->id, 'OLD'))->handle(app(\YousefAman\FeedbackBoard\Runner\RunnerDispatcher::class));

    Http::assertNothingSent();
});

it('writes terminal failed with a sanitized code on a permanent error', function () {
    Http::fake(['https://runner.test/*' => Http::response('no', 404)]);
    $item = dispatchedItem();

    (new DispatchToRunnerJob($item->id, 'live'))->handle(app(\YousefAman\FeedbackBoard\Runner\RunnerDispatcher::class));

    $runner = $item->refresh()->ai_state['runner'];
    expect($runner['status'])->toBe('failed')
        ->and($runner['failed_origin'])->toBe('dispatch')
        ->and($runner['error_code'])->toBe('not_found_404');
});

it('rethrows a transient error so the queue retries', function () {
    Http::fake(['https://runner.test/*' => Http::response('boom', 503)]);
    $item = dispatchedItem();

    expect(fn () => (new DispatchToRunnerJob($item->id, 'live'))->handle(app(\YousefAman\FeedbackBoard\Runner\RunnerDispatcher::class)))
        ->toThrow(RunnerException::class, 'http_5xx');
    expect($item->refresh()->ai_state['runner']['status'])->toBe('dispatched'); // not terminal yet
});

it('failed() writes the terminal failed state', function () {
    $item = dispatchedItem();

    (new DispatchToRunnerJob($item->id, 'live'))->failed(new RunnerException('http_5xx'));

    expect($item->refresh()->ai_state['runner']['status'])->toBe('failed');
});

it('sets afterCommit on the job', function () {
    expect((new DispatchToRunnerJob(1, 't'))->afterCommit)->toBeTrue();
});

// FIX 2 — monotonicity guard: writeFailed must not regress a runner-advanced status.

it('failed() does NOT overwrite status=running when the runner already advanced past dispatched', function () {
    $item = FeedbackItem::factory()->create();
    $item->ai_state = ['runner' => ['status' => 'running', 'task_id' => 'live']];
    $item->save();

    (new DispatchToRunnerJob($item->id, 'live'))->failed(new RunnerException('http_5xx'));

    expect($item->refresh()->ai_state['runner']['status'])->toBe('running');
});

it('failed() does NOT overwrite status=completed when the runner already completed', function () {
    $item = FeedbackItem::factory()->create();
    $item->ai_state = ['runner' => ['status' => 'completed', 'task_id' => 'live', 'analysis_posted' => true]];
    $item->save();

    (new DispatchToRunnerJob($item->id, 'live'))->failed(new RunnerException('http_5xx'));

    $runner = $item->refresh()->ai_state['runner'];
    expect($runner['status'])->toBe('completed')
        ->and($runner['analysis_posted'])->toBeTrue();
});
