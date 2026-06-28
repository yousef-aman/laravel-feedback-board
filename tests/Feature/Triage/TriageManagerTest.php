<?php

use YousefAman\FeedbackBoard\Enums\FeedbackPriority;
use YousefAman\FeedbackBoard\Enums\FeedbackType;
use YousefAman\FeedbackBoard\Triage\Contracts\TriageDriver;
use YousefAman\FeedbackBoard\Triage\Support\TriageResult;
use YousefAman\FeedbackBoard\Triage\TriageManager;

it('resolves the fake driver and returns a deterministic result', function () {
    $result = app(TriageManager::class)->driver('fake')->triage('Login button broken', 'It does nothing');

    expect($result)->toBeInstanceOf(TriageResult::class)
        ->and($result->type)->toBe(FeedbackType::Bug)
        ->and($result->priority)->toBe(FeedbackPriority::Medium)
        ->and($result->summary)->toBe('Login button broken')
        ->and($result->model)->toBe('fake');
});

it('lets tests register a spy driver resolved by name', function () {
    app(TriageManager::class)->extend('spy', fn () => new class implements TriageDriver {
        public function triage(string $title, ?string $description): TriageResult {
            return new TriageResult(null, null, "ok:{$title}", 'spy');
        }
    });

    $result = app(TriageManager::class)->driver('spy')->triage('t', 'd');
    expect($result->summary)->toBe('ok:t')->and($result->model)->toBe('spy');
});

it('reads the default driver from config', function () {
    config()->set('feedback-board.ai.default', 'fake');
    expect(app(TriageManager::class)->getDefaultDriver())->toBe('fake');
});
