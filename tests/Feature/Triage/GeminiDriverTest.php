<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use YousefAman\FeedbackBoard\Enums\FeedbackPriority;
use YousefAman\FeedbackBoard\Enums\FeedbackType;
use YousefAman\FeedbackBoard\Triage\Drivers\GeminiDriver;
use YousefAman\FeedbackBoard\Triage\Exceptions\TriageException;

beforeEach(function () {
    config()->set('feedback-board.ai.gemini.api_key', 'gm-secret-key');
    config()->set('feedback-board.ai.gemini.model', 'gemini-3.5-flash-lite');
    Sleep::fake();
});

/** A generateContent answer whose text is $text, after a thought part as a thinking model sends one. */
function geminiAnswer(string $text, string $finish = 'STOP'): array
{
    return ['candidates' => [[
        'content' => ['role' => 'model', 'parts' => [
            ['text' => 'Considering the report.', 'thought' => true],
            ['text' => $text],
        ]],
        'finishReason' => $finish,
    ]]];
}

it('asks Gemini for the triage as JSON and reads the answer', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(
        geminiAnswer('{"type":"bug","priority":"high","summary":"Login fails"}')
    )]);

    $result = (new GeminiDriver)->triage('Login broken', 'It does nothing');

    expect($result->type)->toBe(FeedbackType::Bug)
        ->and($result->priority)->toBe(FeedbackPriority::High)
        ->and($result->summary)->toBe('Login fails')
        ->and($result->model)->toBe('gemini-3.5-flash-lite');

    Http::assertSent(function ($request) {
        $body = $request->data();
        $schema = $body['generationConfig']['responseSchema'];

        return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent'
            && $request->hasHeader('x-goog-api-key', 'gm-secret-key')
            && str_contains($body['systemInstruction']['parts'][0]['text'], 'You triage user feedback')
            && str_contains($body['contents'][0]['parts'][0]['text'], 'Title: Login broken')
            && $body['contents'][0]['role'] === 'user'
            && $body['generationConfig']['responseMimeType'] === 'application/json'
            && $schema['type'] === 'OBJECT'
            && $schema['properties']['type'] === ['type' => 'STRING', 'enum' => ['bug', 'feature', 'question', 'other']]
            && $schema['properties']['priority'] === ['type' => 'STRING', 'enum' => ['low', 'medium', 'high', 'urgent']]
            && $schema['required'] === ['summary'];
    });
});

it('keeps the key out of the URL', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiAnswer('{"summary":"x"}'))]);

    (new GeminiDriver)->triage('t', null);

    Http::assertSent(fn ($request) => ! str_contains($request->url(), 'gm-secret-key'));
});

it('leaves the output cap to Gemini unless one is configured, since thinking counts toward it', function (?int $configured, ?int $sent) {
    config()->set('feedback-board.ai.gemini.max_tokens', $configured);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiAnswer('{"summary":"x"}'))]);

    (new GeminiDriver)->triage('t', null);

    Http::assertSent(fn ($request) => ($request->data()['generationConfig']['maxOutputTokens'] ?? null) === $sent);
})->with(['not configured' => [null, null], 'configured' => [2048, 2048]]);

it('turns a value outside the lists into no suggestion', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(
        geminiAnswer('{"type":"banana","priority":"asap","summary":"x"}')
    )]);

    $result = (new GeminiDriver)->triage('t', 'd');

    expect($result->type)->toBeNull()->and($result->priority)->toBeNull()->and($result->summary)->toBe('x');
});

it('rejects an answer it cannot use', function (array $body) {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response($body)]);

    expect(fn () => (new GeminiDriver)->triage('t', 'd'))->toThrow(TriageException::class, 'invalid_output');
})->with([
    'no summary' => [geminiAnswer('{"type":"bug"}')],
    'blank summary' => [geminiAnswer('{"summary":"  "}')],
    'not JSON' => [geminiAnswer('Here is the triage: bug')],
    'cut off' => [geminiAnswer('{"type":"bug","summ', 'MAX_TOKENS')],
    'blocked prompt' => [['promptFeedback' => ['blockReason' => 'SAFETY']]],
    'thought only' => [['candidates' => [['content' => ['parts' => [['text' => '{"summary":"x"}', 'thought' => true]]], 'finishReason' => 'STOP']]]],
]);

it('reports a refused key as auth_401 without leaking it', function (int $status, array $body) {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response($body, $status)]);

    try {
        (new GeminiDriver)->triage('t', 'd');
        $this->fail('expected TriageException');
    } catch (TriageException $e) {
        expect($e->errorCode)->toBe('auth_401')
            ->and((string) $e)->not->toContain('gm-secret-key')
            ->and($e->getPrevious())->toBeNull();
    }
})->with([
    // Gemini answers an invalid key with 400 and the reason API_KEY_INVALID, not with 401.
    'invalid key' => [400, ['error' => ['code' => 400, 'message' => 'API key not valid: gm-secret-key', 'status' => 'INVALID_ARGUMENT', 'details' => [['reason' => 'API_KEY_INVALID']]]]],
    'forbidden' => [403, ['error' => ['code' => 403, 'message' => 'Permission denied for gm-secret-key', 'status' => 'PERMISSION_DENIED']]],
]);

it('reports a connection error without leaking the key', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 7 with x-goog-api-key: gm-secret-key'));

    try {
        (new GeminiDriver)->triage('t', 'd');
        $this->fail('expected TriageException');
    } catch (TriageException $e) {
        expect($e->errorCode)->toBe('connection_error')
            ->and((string) $e)->not->toContain('gm-secret-key')
            ->and($e->getPrevious())->toBeNull();
    }
});

it('is not configured without a key or a model', function (string $key, ?string $value) {
    config()->set("feedback-board.ai.gemini.{$key}", $value);
    Http::fake();

    expect(fn () => (new GeminiDriver)->triage('t', 'd'))->toThrow(TriageException::class, 'not_configured');
    Http::assertNothingSent();
})->with([['api_key', null], ['api_key', ''], ['model', '']]);

it('tries a quick transient failure once more', function () {
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;
        Illuminate\Support\Carbon::setTestNow(now()->addSecond());

        return Http::response(['error' => ['code' => 503, 'status' => 'UNAVAILABLE']], 503);
    });

    expect(fn () => (new GeminiDriver)->triage('Login broken', null))->toThrow(TriageException::class, 'http_5xx');
    expect($calls)->toBe(2);
});
