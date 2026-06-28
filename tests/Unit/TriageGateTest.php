<?php

use YousefAman\FeedbackBoard\Triage\Support\TriageGate;

it('is false when disabled', function () {
    config()->set('feedback-board.ai.enabled', false);
    expect(TriageGate::configured())->toBeFalse();
});

it('is false when anthropic is selected without a key', function () {
    config()->set('feedback-board.ai.enabled', true);
    config()->set('feedback-board.ai.default', 'anthropic');
    config()->set('feedback-board.ai.anthropic.api_key', null);
    expect(TriageGate::configured())->toBeFalse();
});

it('is true for anthropic with a key', function () {
    config()->set('feedback-board.ai.enabled', true);
    config()->set('feedback-board.ai.default', 'anthropic');
    config()->set('feedback-board.ai.anthropic.api_key', 'sk-ant');
    expect(TriageGate::configured())->toBeTrue();
});

it('is true for the fake driver with no key', function () {
    config()->set('feedback-board.ai.enabled', true);
    config()->set('feedback-board.ai.default', 'fake');
    expect(TriageGate::configured())->toBeTrue();
});

it('is false for an unknown/typo driver name (fail closed)', function () {
    config()->set('feedback-board.ai.enabled', true);
    config()->set('feedback-board.ai.default', 'nope');
    expect(TriageGate::configured())->toBeFalse();
});
