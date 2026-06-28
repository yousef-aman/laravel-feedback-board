<?php

use YousefAman\FeedbackBoard\Triage\Support\TriagePrompt;

it('lists the enum vocabularies in the system prompt', function () {
    $system = TriagePrompt::system();
    foreach (['bug', 'feature', 'question', 'other', 'low', 'medium', 'high', 'urgent'] as $token) {
        expect($system)->toContain($token);
    }
});

it('bounds the combined title and description to max chars', function () {
    $title = str_repeat('T', 500);
    $desc = str_repeat('D', 9000);

    $user = TriagePrompt::user($title, $desc, 4000);

    // Title capped at 200, remainder for description → total well under the raw 9500.
    expect(mb_strlen($user))->toBeLessThanOrEqual(4000)
        ->and($user)->toContain(str_repeat('T', 200))
        ->and($user)->not->toContain(str_repeat('T', 201));
});

it('builds a forced-tool schema requiring summary', function () {
    $tool = TriagePrompt::tool();
    expect($tool['name'])->toBe('submit_triage')
        ->and($tool['input_schema']['required'])->toBe(['summary'])
        ->and($tool['input_schema']['properties']['type']['enum'])->toBe(['bug', 'feature', 'question', 'other'])
        ->and($tool['input_schema']['properties']['priority']['enum'])->toBe(['low', 'medium', 'high', 'urgent']);
});
