<?php // tests/Unit/EnumsTest.php
use YousefAman\FeedbackBoard\Enums\{ActivityAction, AuthorRole, FeedbackPriority, FeedbackStatus};

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

it('maps priorities to badge colors', function () {
    expect(FeedbackPriority::Low->color())->toBe('gray')
        ->and(FeedbackPriority::Medium->color())->toBe('info')
        ->and(FeedbackPriority::High->color())->toBe('warning')
        ->and(FeedbackPriority::Urgent->color())->toBe('danger');
});

it('labels activity actions and author roles in English and Arabic', function () {
    expect(ActivityAction::StatusChanged->label())->toBe('Status changed')
        ->and(AuthorRole::Developer->label())->toBe('Support team');

    app()->setLocale('ar');

    expect(ActivityAction::StatusChanged->label())->toBe('تغيّرت الحالة')
        ->and(AuthorRole::Reporter->label())->toBe('المُبلِّغ');
});

it('translates every activity action and author role', function () {
    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);

        foreach ([...ActivityAction::cases(), ...AuthorRole::cases()] as $case) {
            expect($case->label())->not->toStartWith('feedback-board::');
        }
    }
});
