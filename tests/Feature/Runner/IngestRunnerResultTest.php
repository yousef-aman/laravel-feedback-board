<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use YousefAman\FeedbackBoard\Jobs\DeliverToDestination;
use YousefAman\FeedbackBoard\Livewire\FeedbackItemModal;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\TestUser;

// NOTE: the whole Pest suite runs in one process; these helper names (signedR/postR/
// runnerItem) are unique to this file — do NOT reuse signedRunner/postRunner/itemWithRunner
// (Task 8) or signed/developer/regular (existing tests).
function signedR(array $payload): array {
    $body = json_encode($payload);
    $ts = (string) now()->timestamp;
    $sig = hash_hmac('sha256', $ts.'.'.$body, 'shhh');
    return [$body, ['X-Feedback-Timestamp' => $ts, 'X-Feedback-Signature' => $sig, 'Content-Type' => 'application/json']];
}
function postR(array $payload) {
    [$body, $headers] = signedR($payload);
    return test()->call('POST', route('feedback-board.ingest'), [], [], [], test()->transformHeadersToServerVars($headers), $body);
}
function runnerItem(string $status, string $taskId, array $extra = []): FeedbackItem {
    $item = FeedbackItem::factory()->create();
    $item->ai_state = ['runner' => array_merge(['status' => $status, 'task_id' => $taskId], $extra)];
    $item->save();
    return $item;
}

beforeEach(function () {
    config()->set('feedback-board.ai.lock_store', 'array');
    config()->set('feedback-board.destinations.lock_store', 'array'); // backs the github lock used by writePrRef
    config()->set('feedback-board.ingest.enabled', true);
    config()->set('feedback-board.ingest.secret', 'shhh');
});

it('posts the analysis as an INTERNAL AI comment and never leaks it', function () {
    // Make BOTH leak paths LIVE for a non-internal reply, so the guards discriminate:
    //  - destinations.enabled=['webhook'] (default events include feedback.replied) → a
    //    non-internal reply WOULD dispatch DeliverToDestination (DispatchToDestinations).
    //  - a real notifiable reporter → a non-internal Ai reply WOULD notify it
    //    (SendFeedbackNotifications::onReplied elseif ($item->reporter)).
    config()->set('feedback-board.destinations.enabled', ['webhook']);
    config()->set('feedback-board.destinations.webhook.url', 'https://hook.test/x');
    config()->set('feedback-board.destinations.webhook.secret', 'whs');
    Notification::fake();
    Bus::fake();

    $reporter = TestUser::create(['name' => 'Reporter']);
    $item = runnerItem('running', 'T1');
    $item->forceFill(['reporter_type' => $reporter->getMorphClass(), 'reporter_id' => $reporter->getKey()])->save();

    postR(['item_id' => $item->id, 'action' => 'runner_result', 'task_id' => 'T1', 'analysis' => 'AGENT NOTES'])->assertOk();

    $comment = $item->comments()->latest('id')->first();
    expect($comment->body)->toBe('AGENT NOTES')
        ->and($comment->is_internal)->toBeTrue()                        // CRITICAL: internal only
        ->and($item->refresh()->ai_state['runner']['analysis_posted'])->toBeTrue();

    // Internal ⇒ no reporter email, no destination fan-out (now discriminating).
    Notification::assertNothingSent();
    Bus::assertNotDispatched(DeliverToDestination::class);

    // Spec §9: a non-developer (the reporter) viewing the modal must NOT see the internal
    // analysis. FeedbackItemModal::render filters where(is_internal,false) for non-developers.
    $this->actingAs($reporter);
    Livewire::test(FeedbackItemModal::class, ['item' => $item->refresh()])
        ->assertDontSee('AGENT NOTES');
});

it('does not duplicate the analysis comment on a re-post', function () {
    // A byte-identical signed body re-posted in the SAME second is, by design, a replay
    // (VerifyIngestSignature nonces on the signature). Advance the clock (well inside the
    // 300s tolerance) so the second post gets a fresh signature and actually reaches
    // RunnerIngest::result, exercising the analysis_posted compare-and-set.
    $item = runnerItem('running', 'T1');
    postR(['item_id' => $item->id, 'action' => 'runner_result', 'task_id' => 'T1', 'analysis' => 'ONCE'])->assertOk();
    $this->travel(2)->seconds();
    postR(['item_id' => $item->id, 'action' => 'runner_result', 'task_id' => 'T1', 'analysis' => 'ONCE'])->assertOk();
    expect($item->comments()->where('body', 'ONCE')->count())->toBe(1);
});

it('stores the diff under the cap and advances a non-terminal status to completed', function () {
    $item = runnerItem('running', 'T1');
    postR(['item_id' => $item->id, 'action' => 'runner_result', 'task_id' => 'T1', 'diff' => '--- a/x'])->assertOk();
    $fresh = $item->refresh();
    expect($fresh->ai_state['runner']['diff'])->toBe('--- a/x')
        ->and($fresh->ai_state['runner']['status'])->toBe('completed'); // §4.2 opportunistic advance
});

it('accepts a valid https pr_url and advances to completed', function () {
    $item = runnerItem('running', 'T1');
    postR(['item_id' => $item->id, 'action' => 'runner_result', 'task_id' => 'T1', 'pr_url' => 'https://github.com/o/r/pull/5'])->assertOk();
    $fresh = $item->refresh();
    expect($fresh->external_refs['pr_url'])->toBe('https://github.com/o/r/pull/5')
        ->and($fresh->ai_state['runner']['pr_url'])->toBe('https://github.com/o/r/pull/5')
        ->and($fresh->ai_state['runner']['status'])->toBe('completed'); // opportunistic advance
});

it('rejects dangerous pr_urls and stores nothing', function () {
    foreach (['https://github.com@evil.com/x', 'http://github.com/o/r', 'javascript:alert(1)', 'ftp://h/x', 'https:///path'] as $bad) {
        $item = runnerItem('running', 'T'.md5($bad));
        postR(['item_id' => $item->id, 'action' => 'runner_result', 'task_id' => 'T'.md5($bad), 'pr_url' => $bad])->assertOk();
        $fresh = $item->refresh();
        expect($fresh->external_refs['pr_url'] ?? null)->toBeNull()
            ->and($fresh->ai_state['runner']['pr_url'] ?? null)->toBeNull();
    }
});

it('rejects a result whose task_id mismatches', function () {
    $item = runnerItem('running', 'T1');
    postR(['item_id' => $item->id, 'action' => 'runner_result', 'task_id' => 'STALE', 'diff' => 'x'])->assertStatus(409);
    expect($item->refresh()->ai_state['runner']['diff'] ?? null)->toBeNull();
});

it('heals a dispatch-failed item but rejects a runner-failed one', function () {
    $heal = runnerItem('failed', 'T1', ['failed_origin' => 'dispatch']);
    postR(['item_id' => $heal->id, 'action' => 'runner_result', 'task_id' => 'T1', 'pr_url' => 'https://github.com/o/r/pull/9'])->assertOk();
    expect($heal->refresh()->ai_state['runner']['status'])->toBe('completed');

    $reject = runnerItem('failed', 'T2', ['failed_origin' => 'runner']);
    postR(['item_id' => $reject->id, 'action' => 'runner_result', 'task_id' => 'T2', 'pr_url' => 'https://github.com/o/r/pull/9'])->assertOk();
    $fresh = $reject->refresh();
    expect($fresh->ai_state['runner']['status'])->toBe('failed')
        ->and($fresh->ai_state['runner']['pr_url'] ?? null)->toBeNull();
});
