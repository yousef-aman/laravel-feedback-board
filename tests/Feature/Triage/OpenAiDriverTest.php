<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use YousefAman\FeedbackBoard\Enums\FeedbackPriority;
use YousefAman\FeedbackBoard\Enums\FeedbackType;
use YousefAman\FeedbackBoard\Triage\Drivers\OpenAiDriver;
use YousefAman\FeedbackBoard\Triage\Exceptions\TriageException;

beforeEach(function () {
    config()->set('feedback-board.ai.openai.api_key', 'sk-openai-secret');
    config()->set('feedback-board.ai.openai.model', 'gpt-6-luna');
    Sleep::fake();
});

/** A Chat Completions answer that calls the triage function with $arguments (a JSON string). */
function openAiAnswer(string $arguments, string $name = 'submit_triage'): array
{
    return ['choices' => [[
        'index' => 0,
        'message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
            'id' => 'call_1',
            'type' => 'function',
            'function' => ['name' => $name, 'arguments' => $arguments],
        ]]],
        'finish_reason' => 'tool_calls',
    ]]];
}

it('asks for the triage through a forced function call and reads its arguments', function () {
    Http::fake(['api.openai.com/*' => Http::response(
        openAiAnswer('{"type":"bug","priority":"high","summary":"Login fails"}')
    )]);

    $result = (new OpenAiDriver)->triage('Login broken', 'It does nothing');

    expect($result->type)->toBe(FeedbackType::Bug)
        ->and($result->priority)->toBe(FeedbackPriority::High)
        ->and($result->summary)->toBe('Login fails')
        ->and($result->model)->toBe('gpt-6-luna');

    Http::assertSent(function ($request) {
        $body = $request->data();
        $function = $body['tools'][0]['function'];

        return $request->url() === 'https://api.openai.com/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer sk-openai-secret')
            && $body['model'] === 'gpt-6-luna'
            && $body['messages'][0]['role'] === 'system'
            && str_contains($body['messages'][0]['content'], 'You triage user feedback')
            && $body['messages'][1] === ['role' => 'user', 'content' => "Title: Login broken\n\nDescription: It does nothing"]
            && $body['tools'][0]['type'] === 'function'
            && $function['name'] === 'submit_triage'
            && $function['parameters']['properties']['type']['enum'] === ['bug', 'feature', 'question', 'other']
            && $body['tool_choice'] === ['type' => 'function', 'function' => ['name' => 'submit_triage']];
    });
});

it('turns reasoning off by default, which OpenAI requires for function calls', function () {
    Http::fake(['api.openai.com/*' => Http::response(openAiAnswer('{"summary":"x"}'))]);

    (new OpenAiDriver)->triage('t', null);

    Http::assertSent(fn ($request) => $request->data()['reasoning_effort'] === 'none');
});

it('leaves reasoning_effort out when it is set empty, for a provider that rejects it', function (?string $effort) {
    config()->set('feedback-board.ai.openai.reasoning_effort', $effort);
    Http::fake(['*' => Http::response(openAiAnswer('{"summary":"x"}'))]);

    (new OpenAiDriver)->triage('t', null);

    Http::assertSent(fn ($request) => ! array_key_exists('reasoning_effort', $request->data()));
})->with(['empty' => [''], 'null' => [null]]);

it('caps the output only when a cap is configured, as max_completion_tokens', function (?int $configured) {
    config()->set('feedback-board.ai.openai.max_tokens', $configured);
    Http::fake(['api.openai.com/*' => Http::response(openAiAnswer('{"summary":"x"}'))]);

    (new OpenAiDriver)->triage('t', null);

    Http::assertSent(fn ($request) => ($request->data()['max_completion_tokens'] ?? null) === $configured
        && ! array_key_exists('max_tokens', $request->data()));
})->with(['not configured' => [null], 'configured' => [512]]);

it('talks to any OpenAI-compatible API through base_url', function () {
    config()->set('feedback-board.ai.openai.base_url', 'https://api.deepseek.com/v1/');
    Http::fake(['api.deepseek.com/*' => Http::response(openAiAnswer('{"summary":"x"}'))]);

    (new OpenAiDriver)->triage('t', null);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.deepseek.com/v1/chat/completions');
});

it('turns a value outside the lists into no suggestion', function () {
    Http::fake(['api.openai.com/*' => Http::response(openAiAnswer('{"type":"banana","priority":"asap","summary":"x"}'))]);

    $result = (new OpenAiDriver)->triage('t', 'd');

    expect($result->type)->toBeNull()->and($result->priority)->toBeNull()->and($result->summary)->toBe('x');
});

it('rejects an answer it cannot use', function (array $body) {
    Http::fake(['api.openai.com/*' => Http::response($body)]);

    expect(fn () => (new OpenAiDriver)->triage('t', 'd'))->toThrow(TriageException::class, 'invalid_output');
})->with([
    'no summary' => [openAiAnswer('{"type":"bug"}')],
    'arguments not JSON' => [openAiAnswer('{"summary": "x"')],
    'another function' => [openAiAnswer('{"summary":"x"}', 'something_else')],
    'plain text' => [['choices' => [['message' => ['role' => 'assistant', 'content' => '{"summary":"x"}'], 'finish_reason' => 'stop']]]],
    'no choices' => [['choices' => []]],
]);

it('reports a refused key as auth_401 without leaking it', function () {
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Incorrect API key provided: sk-openai-secret', 'code' => 'invalid_api_key']], 401)]);

    try {
        (new OpenAiDriver)->triage('t', 'd');
        $this->fail('expected TriageException');
    } catch (TriageException $e) {
        expect($e->errorCode)->toBe('auth_401')
            ->and((string) $e)->not->toContain('sk-openai-secret')
            ->and($e->getPrevious())->toBeNull();
    }
});

it('reports a connection error without leaking the key', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 7 with Authorization: Bearer sk-openai-secret'));

    try {
        (new OpenAiDriver)->triage('t', 'd');
        $this->fail('expected TriageException');
    } catch (TriageException $e) {
        expect($e->errorCode)->toBe('connection_error')
            ->and((string) $e)->not->toContain('sk-openai-secret')
            ->and($e->getPrevious())->toBeNull();
    }
});

it('is not configured without a key', function (string $key, ?string $value) {
    config()->set("feedback-board.ai.openai.{$key}", $value);
    Http::fake();

    expect(fn () => (new OpenAiDriver)->triage('t', 'd'))->toThrow(TriageException::class, 'not_configured');
    Http::assertNothingSent();
})->with([['api_key', null], ['api_key', '']]);

it('uses the default model when the model is empty or missing', function (?string $model) {
    config()->set('feedback-board.ai.openai.model', $model);
    Http::fake(['api.openai.com/*' => Http::response(openAiAnswer('{"summary":"x"}'))]);

    $result = (new OpenAiDriver)->triage('t', null);

    expect($result->model)->toBe('gpt-6-luna');
    Http::assertSent(fn ($request) => str_contains($request->url().json_encode($request->data()), 'gpt-6-luna'));
})->with(['empty' => [''], 'missing' => [null]]);

it('tries a quick transient failure once more', function () {
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;
        Illuminate\Support\Carbon::setTestNow(now()->addSecond());

        return Http::response(['error' => ['message' => 'Rate limit']], 429);
    });

    expect(fn () => (new OpenAiDriver)->triage('Login broken', null))->toThrow(TriageException::class, 'http_429');
    expect($calls)->toBe(2);
});

it('reads arguments given as an object, as some compatible APIs send them', function () {
    $answer = openAiAnswer('{}');
    $answer['choices'][0]['message']['tool_calls'][0]['function']['arguments'] = ['type' => 'bug', 'summary' => 'x'];
    Http::fake(['api.openai.com/*' => Http::response($answer)]);

    $result = (new OpenAiDriver)->triage('t', null);

    expect($result->type)->toBe(FeedbackType::Bug)->and($result->summary)->toBe('x');
});
