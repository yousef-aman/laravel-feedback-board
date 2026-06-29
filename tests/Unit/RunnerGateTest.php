<?php

use YousefAman\FeedbackBoard\Runner\Support\RunnerGate;

function configureRunner(bool $enabled, ?string $endpoint, ?string $secret): void {
    config()->set('feedback-board.runner.enabled', $enabled);
    config()->set('feedback-board.runner.endpoint', $endpoint);
    config()->set('feedback-board.runner.secret', $secret);
}

it('is configured only when enabled + endpoint + secret are all present', function () {
    configureRunner(true, 'https://runner.test/task', 'shh');
    expect(RunnerGate::configured())->toBeTrue();
});

it('is not configured when disabled', function () {
    configureRunner(false, 'https://runner.test/task', 'shh');
    expect(RunnerGate::configured())->toBeFalse();
});

it('is not configured when endpoint or secret is missing', function () {
    configureRunner(true, '', 'shh');
    expect(RunnerGate::configured())->toBeFalse();
    configureRunner(true, 'https://runner.test/task', '');
    expect(RunnerGate::configured())->toBeFalse();
    configureRunner(true, null, null);
    expect(RunnerGate::configured())->toBeFalse();
});

it('treats a truthy enabled flag (=1) as enabled, like TriageGate', function () {
    configureRunner(true, 'https://runner.test/task', 'shh');
    config()->set('feedback-board.runner.enabled', 1);
    expect(RunnerGate::configured())->toBeTrue();
});
