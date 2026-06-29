<?php

use YousefAman\FeedbackBoard\Models\FeedbackItem;

function signedRunner(array $payload): array {
    $body = json_encode($payload);
    $ts = (string) now()->timestamp;
    $sig = hash_hmac('sha256', $ts.'.'.$body, 'shhh');
    return [$body, ['X-Feedback-Timestamp' => $ts, 'X-Feedback-Signature' => $sig, 'Content-Type' => 'application/json']];
}

function postRunner(array $payload) {
    [$body, $headers] = signedRunner($payload);
    return test()->call('POST', route('feedback-board.ingest'), [], [], [], test()->transformHeadersToServerVars($headers), $body);
}

function itemWithRunner(string $status, string $taskId, array $extra = []): FeedbackItem {
    $item = FeedbackItem::factory()->create();
    $item->ai_state = ['runner' => array_merge(['status' => $status, 'task_id' => $taskId], $extra)];
    $item->save();
    return $item;
}

beforeEach(function () {
    config()->set('feedback-board.ai.lock_store', 'array');
    config()->set('feedback-board.ingest.enabled', true);
    config()->set('feedback-board.ingest.secret', 'shhh');
});

it('advances dispatched -> running -> completed for a matching task_id', function () {
    $item = itemWithRunner('dispatched', 'T1');
    postRunner(['item_id' => $item->id, 'action' => 'runner_status', 'task_id' => 'T1', 'status' => 'running'])->assertOk();
    expect($item->refresh()->ai_state['runner']['status'])->toBe('running');
    postRunner(['item_id' => $item->id, 'action' => 'runner_status', 'task_id' => 'T1', 'status' => 'completed'])->assertOk();
    expect($item->refresh()->ai_state['runner']['status'])->toBe('completed');
});

it('rejects a post-back whose task_id does not match', function () {
    $item = itemWithRunner('dispatched', 'T1');
    postRunner(['item_id' => $item->id, 'action' => 'runner_status', 'task_id' => 'STALE', 'status' => 'running'])->assertStatus(409);
    expect($item->refresh()->ai_state['runner']['status'])->toBe('dispatched');
});

it('does not regress a terminal status (late running after completed is a no-op)', function () {
    $item = itemWithRunner('completed', 'T1');
    postRunner(['item_id' => $item->id, 'action' => 'runner_status', 'task_id' => 'T1', 'status' => 'running'])->assertOk();
    expect($item->refresh()->ai_state['runner']['status'])->toBe('completed');
});

it('does not flip completed to failed (equal rank no-op)', function () {
    $item = itemWithRunner('completed', 'T1');
    postRunner(['item_id' => $item->id, 'action' => 'runner_status', 'task_id' => 'T1', 'status' => 'failed'])->assertOk();
    expect($item->refresh()->ai_state['runner']['status'])->toBe('completed');
});

it('stores a runner-reported failed with origin and the safe slug', function () {
    $item = itemWithRunner('running', 'T1');
    postRunner(['item_id' => $item->id, 'action' => 'runner_status', 'task_id' => 'T1', 'status' => 'failed', 'error_code' => 'build_broke'])->assertOk();
    $runner = $item->refresh()->ai_state['runner'];
    expect($runner['status'])->toBe('failed')
        ->and($runner['failed_origin'])->toBe('runner')
        ->and($runner['error_code'])->toBe('build_broke');
});

it('defaults a missing failed error_code to unknown', function () {
    $item = itemWithRunner('running', 'T1');
    postRunner(['item_id' => $item->id, 'action' => 'runner_status', 'task_id' => 'T1', 'status' => 'failed'])->assertOk();
    expect($item->refresh()->ai_state['runner']['error_code'])->toBe('unknown');
});

it('does not let a runner un-fail itself (failed -> completed is an equal-rank no-op)', function () {
    $item = itemWithRunner('failed', 'T1', ['failed_origin' => 'runner', 'error_code' => 'x']);
    postRunner(['item_id' => $item->id, 'action' => 'runner_status', 'task_id' => 'T1', 'status' => 'completed'])->assertOk();
    $runner = $item->refresh()->ai_state['runner'];
    expect($runner['status'])->toBe('failed')          // rank(completed) == rank(failed): no transition
        ->and($runner['error_code'])->toBe('x');
});

it('still rejects an unsigned runner_status', function () {
    $item = itemWithRunner('dispatched', 'T1');
    test()->postJson(route('feedback-board.ingest'), ['item_id' => $item->id, 'action' => 'runner_status', 'task_id' => 'T1', 'status' => 'running'])
        ->assertStatus(403);
});
