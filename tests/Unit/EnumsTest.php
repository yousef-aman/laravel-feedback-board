<?php // tests/Unit/EnumsTest.php
use YousefAman\FeedbackBoard\Enums\{FeedbackStatus, AuthorRole};

it('exposes status metadata and defaults', function () {
    expect(FeedbackStatus::default())->toBe(FeedbackStatus::New)
        ->and(FeedbackStatus::New->value)->toBe('new')
        ->and(FeedbackStatus::Resolved->isTerminal())->toBeTrue()
        ->and(FeedbackStatus::InProgress->isTerminal())->toBeFalse()
        ->and(FeedbackStatus::New->label())->toBeString();
});

it('distinguishes author roles', function () {
    expect(AuthorRole::Ai->value)->toBe('ai');
});
