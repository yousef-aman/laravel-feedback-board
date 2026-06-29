<?php

// Regression test for FIX 1: the MANUAL re-triage path must not clobber ai_state.runner.
// Unique helper name (triageLockItem) to avoid collisions with other test files.

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use YousefAman\FeedbackBoard\Jobs\TriageFeedbackItem;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Triage\Support\TriageDispatcher;

beforeEach(function () {
    config()->set('feedback-board.ai.lock_store', 'array');
});

function triageLockItem(): FeedbackItem
{
    $item = FeedbackItem::factory()->create();
    // Runner has already completed for this item; triage has not run yet.
    $item->ai_state = [
        'runner' => [
            'status' => 'completed',
            'task_id' => 'T1',
            'analysis_posted' => true,
        ],
        // 'triage' key intentionally absent
    ];
    $item->save();

    return $item;
}

// ── Discriminating lock test ───────────────────────────────────────────────
// Proves the MANUAL path (locked: true) actually acquires the per-item lock.
// The OLD unlocked code held no lock and would NOT throw even when a concurrent
// writer holds it — this test would have PASSED on the old code only if the lock
// were NOT required. With the locked path, block(8) times out and throws.
it('locked dispatch throws LockTimeoutException when the per-item lock is held', function () {
    // syncWithCarbon: true — each 250ms fake sleep advances now() so Lock::block(8) exhausts
    // after ~32 tight iterations instead of 8s of real wall-clock waiting.
    Sleep::fake(syncWithCarbon: true);

    Bus::fake(); // no job machinery should run

    $item = triageLockItem();
    $originalAiState = $item->ai_state;

    // Acquire and HOLD the per-item lock from the test, simulating a concurrent runner write.
    $lock = Cache::store('array')->lock('feedback-board:ai_state:'.$item->id, 10);
    $held = $lock->get();
    expect($held)->toBeTrue(); // confirm we actually hold it

    // The manual locked path must block on the held lock and throw LockTimeoutException.
    // (The old unlocked code acquired NO lock and would write successfully — NOT throw.)
    expect(fn () => TriageDispatcher::dispatch($item, locked: true))
        ->toThrow(LockTimeoutException::class);

    // The write never ran because the lock was never acquired — ai_state is unchanged.
    expect($item->refresh()->ai_state)->toBe($originalAiState);

    // The job was never dispatched.
    Bus::assertNotDispatched(TriageFeedbackItem::class);

    $lock->forceRelease();
});

it('unlocked dispatch does NOT throw even when the per-item lock is held', function () {
    Sleep::fake(syncWithCarbon: true); // insurance — unlocked path never calls block() so Sleep is a no-op here

    Bus::fake();

    $item = triageLockItem();

    // Hold the per-item lock from the test.
    $lock = Cache::store('array')->lock('feedback-board:ai_state:'.$item->id, 10);
    $lock->get();

    // The listener (unlocked) path must succeed even while the lock is held.
    // It is genuinely lock-free: new items cannot race a runner write.
    // Call directly — if it throws, Pest fails the test. No ->not->toThrow() needed.
    TriageDispatcher::dispatch($item);

    $fresh = $item->refresh();
    expect($fresh->ai_state['triage']['status'])->toBe('pending');
    Bus::assertDispatched(TriageFeedbackItem::class);

    $lock->forceRelease();
});

// ── Original merge-safety test ─────────────────────────────────────────────
it('locked dispatch preserves ai_state.runner sub-key while writing triage pending', function () {
    Bus::fake(); // prevent the job from actually running so we can inspect ai_state

    $item = triageLockItem();

    TriageDispatcher::dispatch($item, locked: true);

    $fresh = $item->refresh();

    // Runner sub-key must survive the triage pending write.
    expect($fresh->ai_state['runner']['status'])->toBe('completed')
        ->and($fresh->ai_state['runner']['analysis_posted'])->toBeTrue()
        ->and($fresh->ai_state['runner']['task_id'])->toBe('T1');

    // Triage sub-key must now be pending.
    expect($fresh->ai_state['triage']['status'])->toBe('pending');

    // Job must have been dispatched.
    Bus::assertDispatched(TriageFeedbackItem::class, fn ($job) => $job->itemId === $item->id);
});
