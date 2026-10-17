<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use YousefAman\FeedbackBoard\Enums\FeedbackPriority;
use YousefAman\FeedbackBoard\Enums\FeedbackType;
use YousefAman\FeedbackBoard\Triage\Drivers\AnthropicDriver;
use YousefAman\FeedbackBoard\Triage\Exceptions\TriageException;

beforeEach(function () {
    config()->set('feedback-board.ai.anthropic.api_key', 'sk-ant-secret');
    config()->set('feedback-board.ai.anthropic.model', 'claude-haiku-4-5');
    Sleep::fake();
});

function toolUse(array $input): array {
    return ['content' => [['type' => 'tool_use', 'name' => 'submit_triage', 'input' => $input]]];
}

it('sends the documented body and parses the tool_use result', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(
        toolUse(['type' => 'bug', 'priority' => 'high', 'summary' => 'Login fails']), 200
    )]);

    $result = (new AnthropicDriver())->triage('Login broken', 'It does nothing');

    expect($result->type)->toBe(FeedbackType::Bug)
        ->and($result->priority)->toBe(FeedbackPriority::High)
        ->and($result->summary)->toBe('Login fails')
        ->and($result->model)->toBe('claude-haiku-4-5');

    Http::assertSent(function ($request) {
        $b = $request->data();
        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->hasHeader('x-api-key', 'sk-ant-secret')
            && $request->hasHeader('anthropic-version', '2023-06-01')
            && $b['model'] === 'claude-haiku-4-5'
            && $b['max_tokens'] === 1024
            && is_string($b['system'])
            && $b['tool_choice'] === ['type' => 'tool', 'name' => 'submit_triage']
            && str_contains($b['messages'][0]['content'], 'Login broken');
    });
    // The real data-minimization boundary (the job passes only title+description,
    // never the FeedbackItem) is proven at the job layer.
});

it('coerces invalid enums to null', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(
        toolUse(['type' => 'banana', 'summary' => 'x']), 200
    )]);

    $result = (new AnthropicDriver())->triage('t', 'd');
    expect($result->type)->toBeNull()->and($result->priority)->toBeNull();
});

it('throws invalid_output when summary is missing', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(toolUse(['type' => 'bug']), 200)]);

    expect(fn () => (new AnthropicDriver())->triage('t', 'd'))
        ->toThrow(TriageException::class, 'invalid_output');
});

it('sanitizes a non-2xx response and never leaks the key', function () {
    \Illuminate\Support\Facades\Log::spy();
    // The fake body embeds the literal key so the not->toContain assertion is non-vacuous.
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'invalid x-api-key: sk-ant-secret'], 401)]);

    try {
        (new AnthropicDriver())->triage('t', 'd');
        $this->fail('expected TriageException');
    } catch (TriageException $e) {
        // The string form is what Laravel persists to failed_jobs — it must be key-free.
        expect($e->errorCode)->toBe('auth_401')
            ->and((string) $e)->not->toContain('sk-ant-secret')
            ->and($e->getMessage())->not->toContain('sk-ant-secret')
            ->and($e->getPrevious())->toBeNull();
    }
    // The driver never logs the key (it does not log at all on this path).
    \Illuminate\Support\Facades\Log::shouldNotHaveReceived('error');
});

it('sanitizes a connection error without leaking the key', function () {
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 7 with x-api-key: sk-ant-secret'));

    try {
        (new AnthropicDriver())->triage('t', 'd');
        $this->fail('expected TriageException');
    } catch (TriageException $e) {
        expect($e->errorCode)->toBe('connection_error')
            ->and((string) $e)->not->toContain('sk-ant-secret')
            ->and($e->getPrevious())->toBeNull();
    }
});

it('throws not_configured when the key is absent', function () {
    config()->set('feedback-board.ai.anthropic.api_key', null);

    expect(fn () => (new AnthropicDriver())->triage('t', 'd'))
        ->toThrow(TriageException::class, 'not_configured');
});

it('tries a quick transient failure once more', function () {
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;
        Illuminate\Support\Carbon::setTestNow(now()->addSecond());

        return Http::response('overloaded', 529);
    });

    expect(fn () => (new AnthropicDriver())->triage('Login broken', null))->toThrow(TriageException::class);
    expect($calls)->toBe(2);
});

it('does not start a second attempt that could run past the time budget', function () {
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;
        Illuminate\Support\Carbon::setTestNow(now()->addSeconds(20));

        return Http::response('overloaded', 529);
    });

    expect(fn () => (new AnthropicDriver())->triage('Login broken', null))->toThrow(TriageException::class);
    expect($calls)->toBe(1);
});

it('keeps each attempt within the budget, whatever timeout is configured', function (?int $configured, int $used) {
    config()->set('feedback-board.ai.anthropic.timeout', $configured);
    $timeout = null;
    Http::fake(function ($request, array $options) use (&$timeout) {
        $timeout = $options['timeout'] ?? null;

        return Http::response(toolUse(['type' => 'bug', 'priority' => 'high', 'summary' => 'x']), 200);
    });

    (new AnthropicDriver())->triage('Login broken', null);

    expect($timeout)->toBe($used);
})->with(['default' => [30, 30], 'too long' => [120, 40], 'unset' => [null, 30]]);

it('gives the triage job more time than the call and its lock wait can take', function () {
    expect((new YousefAman\FeedbackBoard\Jobs\TriageFeedbackItem(1))->timeout)->toBeGreaterThan(40 + 8);
});
