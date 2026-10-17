<?php

use YousefAman\FeedbackBoard\Models\FeedbackItem;

it('ships ai config defaults', function () {
    expect(config('feedback-board.ai.enabled'))->toBeFalse()
        ->and(config('feedback-board.ai.default'))->toBe('anthropic')
        ->and(config('feedback-board.ai.max_input_chars'))->toBe(4000)
        ->and(config('feedback-board.ai.anthropic.model'))->toBe('claude-haiku-4-5')
        ->and(config('feedback-board.ai.anthropic.max_tokens'))->toBe(1024)
        ->and(config('feedback-board.ai.gemini.model'))->toBe('gemini-3.5-flash-lite')
        ->and(config('feedback-board.ai.gemini.max_tokens'))->toBeNull()
        ->and(config('feedback-board.ai.openai.model'))->toBe('gpt-6-luna')
        ->and(config('feedback-board.ai.openai.base_url'))->toBe('https://api.openai.com/v1')
        ->and(config('feedback-board.ai.openai.reasoning_effort'))->toBe('none')
        ->and(config('feedback-board.ai.openai.max_tokens'))->toBeNull();
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
