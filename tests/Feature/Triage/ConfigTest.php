<?php

use YousefAman\FeedbackBoard\Models\FeedbackItem;

it('ships ai config defaults', function () {
    expect(config('feedback-board.ai.enabled'))->toBeFalse()
        ->and(config('feedback-board.ai.default'))->toBe('anthropic')
        ->and(config('feedback-board.ai.max_input_chars'))->toBe(4000)
        ->and(config('feedback-board.ai.anthropic.model'))->toBe('claude-haiku-4-5')
        ->and(config('feedback-board.ai.anthropic.max_tokens'))->toBe(512);
});

it('does not mass-assign server-authored columns but still allows server writes', function () {
    // Mass assignment (create/ingest path) cannot set them.
    $item = FeedbackItem::create([
        'title' => 'x',
        'ai_state' => ['triage' => ['status' => 'completed']],
        'external_refs' => ['github' => ['issue_number' => 1]],
    ]);
    expect($item->refresh()->ai_state)->toBeNull()
        ->and($item->external_refs)->toBeNull();

    // Positive control: direct property assignment (the server-authored path) still persists.
    $item->ai_state = ['triage' => ['status' => 'pending']];
    $item->save();
    expect($item->refresh()->ai_state)->toBe(['triage' => ['status' => 'pending']]);
});
