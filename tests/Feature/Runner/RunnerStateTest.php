<?php

use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Runner\Support\RunnerState;

beforeEach(fn () => config()->set('feedback-board.ai.lock_store', 'array'));

it('merges into ai_state.runner under the lock without touching triage or external_refs', function () {
    $item = FeedbackItem::factory()->create();
    $item->ai_state = ['triage' => ['status' => 'completed']];
    $item->external_refs = ['github' => ['issue_number' => 7]];
    $item->save();

    RunnerState::lock($item->id, fn (FeedbackItem $fresh) => RunnerState::merge($fresh, ['status' => 'dispatched', 'task_id' => 'abc']));

    $fresh = $item->refresh();
    expect($fresh->ai_state['runner']['status'])->toBe('dispatched')
        ->and($fresh->ai_state['runner']['task_id'])->toBe('abc')
        ->and($fresh->ai_state['runner']['updated_at'])->not->toBeNull()
        ->and($fresh->ai_state['triage']['status'])->toBe('completed')   // untouched
        ->and($fresh->external_refs['github']['issue_number'])->toBe(7); // untouched
});

it('is a no-op when the item is gone', function () {
    RunnerState::lock(999999, fn (FeedbackItem $fresh) => RunnerState::merge($fresh, ['status' => 'x']));
    expect(true)->toBeTrue(); // no exception
});
