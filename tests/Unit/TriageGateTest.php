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

it('needs a key for each built-in provider', function (string $provider, array $settings, bool $configured) {
    config()->set('feedback-board.ai.enabled', true);
    config()->set('feedback-board.ai.default', $provider);
    foreach ($settings as $key => $value) {
        config()->set("feedback-board.ai.{$provider}.{$key}", $value);
    }

    expect(TriageGate::configured())->toBe($configured);
})->with([
    'gemini with a key' => ['gemini', ['api_key' => 'gm-key'], true],
    'gemini without a key' => ['gemini', ['api_key' => null], false],
    'gemini with an empty model, which falls back to the default' => ['gemini', ['api_key' => 'gm-key', 'model' => ''], true],
    'openai with a key' => ['openai', ['api_key' => 'sk-key'], true],
    'openai without a key' => ['openai', ['api_key' => ''], false],
    'anthropic with an empty model, which falls back to the default' => ['anthropic', ['api_key' => 'sk-ant', 'model' => ''], true],
]);

it('accepts a provider the app adds', function () {
    config()->set('feedback-board.ai.enabled', true);
    config()->set('feedback-board.ai.default', 'mistral');
    config()->set('feedback-board.ai.mistral', ['api_key' => 'm-key']);
    \YousefAman\FeedbackBoard\Facades\FeedbackBoard::extendTriage('mistral', fn (array $config) => new class($config) implements \YousefAman\FeedbackBoard\Triage\Contracts\TriageDriver {
        public function __construct(public array $config) {}

        public function triage(string $title, ?string $description): \YousefAman\FeedbackBoard\Triage\Support\TriageResult
        {
            return new \YousefAman\FeedbackBoard\Triage\Support\TriageResult(null, null, 'key '.$this->config['api_key'], 'mistral-small');
        }
    });

    expect(TriageGate::configured())->toBeTrue()
        ->and(app(\YousefAman\FeedbackBoard\Triage\TriageManager::class)->driver()->triage('t', null)->summary)->toBe('key m-key');
});

it('asks a provider the app adds whether it is configured, when it can tell', function (bool $configured) {
    config()->set('feedback-board.ai.enabled', true);
    config()->set('feedback-board.ai.default', 'mine');
    \YousefAman\FeedbackBoard\Facades\FeedbackBoard::extendTriage('mine', fn () => new class($configured) implements \YousefAman\FeedbackBoard\Triage\Contracts\TriageDriver, \YousefAman\FeedbackBoard\Triage\Contracts\ChecksConfiguration {
        public function __construct(private bool $ready) {}

        public function configured(): bool
        {
            return $this->ready;
        }

        public function triage(string $title, ?string $description): \YousefAman\FeedbackBoard\Triage\Support\TriageResult
        {
            return new \YousefAman\FeedbackBoard\Triage\Support\TriageResult(null, null, 'x', 'mine');
        }
    });

    expect(TriageGate::configured())->toBe($configured);
})->with([true, false]);

it('fails closed, and reports it, when a provider the app adds cannot be built', function () {
    config()->set('feedback-board.ai.enabled', true);
    config()->set('feedback-board.ai.default', 'broken');
    \YousefAman\FeedbackBoard\Facades\FeedbackBoard::extendTriage('broken', fn () => throw new RuntimeException('missing SDK'));
    \Illuminate\Support\Facades\Exceptions::fake();

    expect(TriageGate::configured())->toBeFalse();
    \Illuminate\Support\Facades\Exceptions::assertReported(RuntimeException::class);
});

it('reports a provider whose factory rejects its settings', function () {
    config()->set('feedback-board.ai.enabled', true);
    config()->set('feedback-board.ai.default', 'strict');
    \YousefAman\FeedbackBoard\Facades\FeedbackBoard::extendTriage('strict', fn () => throw new InvalidArgumentException('The strict driver needs a key.'));
    \Illuminate\Support\Facades\Exceptions::fake();

    expect(TriageGate::configured())->toBeFalse();
    \Illuminate\Support\Facades\Exceptions::assertReported(InvalidArgumentException::class);
});

it('reports a broken provider once, however often triage is checked', function () {
    config()->set('feedback-board.ai.enabled', true);
    config()->set('feedback-board.ai.default', 'broken');
    \YousefAman\FeedbackBoard\Facades\FeedbackBoard::extendTriage('broken', fn () => throw new RuntimeException('missing SDK'));
    \Illuminate\Support\Facades\Exceptions::fake();

    TriageGate::configured();
    TriageGate::configured();
    TriageGate::configured();

    \Illuminate\Support\Facades\Exceptions::assertReportedCount(1);
});

it('is not configured, and says why, when a factory returns something other than a driver', function () {
    config()->set('feedback-board.ai.enabled', true);
    config()->set('feedback-board.ai.default', 'nothing');
    \YousefAman\FeedbackBoard\Facades\FeedbackBoard::extendTriage('nothing', fn () => null);
    \Illuminate\Support\Facades\Exceptions::fake();

    expect(TriageGate::configured())->toBeFalse();
    \Illuminate\Support\Facades\Exceptions::assertReported(fn (UnexpectedValueException $e) => str_contains($e->getMessage(), '[nothing]'));
});

it('is not configured, without a report, for an unknown or empty driver name', function (string $name) {
    config()->set('feedback-board.ai.enabled', true);
    config()->set('feedback-board.ai.default', $name);
    \Illuminate\Support\Facades\Exceptions::fake();

    expect(TriageGate::configured())->toBeFalse();
    \Illuminate\Support\Facades\Exceptions::assertNothingReported();
})->with(['unknown' => ['nope'], 'empty' => ['']]);
