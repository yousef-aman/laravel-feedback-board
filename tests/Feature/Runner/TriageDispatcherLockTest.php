<?php

// Regression test for FIX 1: the MANUAL re-triage path must not clobber ai_state.runner.
// Unique helper name (triageLockItem) to avoid collisions with other test files.

use Illuminate\Support\Facades\Bus;
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
