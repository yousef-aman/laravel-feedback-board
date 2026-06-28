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
            && is_int($b['max_tokens']) && $b['max_tokens'] > 0
            && is_string($b['system'])
            && $b['tool_choice'] === ['type' => 'tool', 'name' => 'submit_triage']
            && str_contains($b['messages'][0]['content'], 'Login broken');
    });
    // The real data-minimization boundary (the job passes only title+description,
    // never the FeedbackItem) is proven at the job layer in Task 7.
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
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'bad key'], 401)]);

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
