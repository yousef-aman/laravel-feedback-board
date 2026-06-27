<?php

it('exposes the destinations config shape with safe defaults', function () {
    expect(config('feedback-board.destinations.default'))->toBe('mail')
        ->and(config('feedback-board.destinations.enabled'))->toBe([])
        ->and(config('feedback-board.destinations.webhook.events'))
            ->toBe(['feedback.created', 'feedback.replied', 'feedback.status_changed'])
        ->and(config('feedback-board.destinations.slack.events'))
            ->toBe(['feedback.created', 'feedback.status_changed'])
        ->and(config('feedback-board.destinations.github.events'))
            ->toBe(['feedback.replied', 'feedback.status_changed'])
        ->and(config('feedback-board.destinations.github.close_on_resolved'))->toBeFalse()
        ->and(array_key_exists('lock_store', config('feedback-board.destinations')))->toBeTrue();
});
