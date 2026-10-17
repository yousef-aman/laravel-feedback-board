<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Triage\Drivers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use YousefAman\FeedbackBoard\Triage\Support\TriagePrompt;

/**
 * Google's Gemini API (generateContent), with the triage as a JSON answer held to a response schema.
 * The key goes in the x-goog-api-key header, never in the URL.
 */
final class GeminiDriver extends HttpTriageDriver
{
    protected function provider(): string
    {
        return 'gemini';
    }

    protected function defaultModel(): string
    {
        return 'gemini-3.5-flash-lite';
    }

    protected function send(PendingRequest $http, string $key, string $model, string $title, ?string $description): Response
    {
        $config = [
            'responseMimeType' => 'application/json',
            'responseSchema' => self::schema(TriagePrompt::schema()),
        ];

        // Thinking tokens count toward maxOutputTokens, so a small cap can cut the answer off:
        // it is sent only when the app sets one.
        $cap = $this->positiveInt('max_tokens');
        if ($cap !== null) {
            $config['maxOutputTokens'] = $cap;
        }

        return $http->withHeaders(['x-goog-api-key' => $key])
            ->post('https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent', [
                'systemInstruction' => ['parts' => [['text' => TriagePrompt::system(viaTool: false)]]],
                'contents' => [[
                    'role' => 'user',
                    'parts' => [['text' => TriagePrompt::user($title, $description, (int) config('feedback-board.ai.max_input_chars', 4000))]],
                ]],
                'generationConfig' => $config,
            ]);
    }

    protected function fields(Response $response): ?array
    {
        $text = '';
        foreach ((array) $response->json('candidates.0.content.parts', []) as $part) {
            // Thought parts are the model's reasoning, not its answer.
            if (is_array($part) && ($part['thought'] ?? false) !== true && is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }

        $fields = json_decode($text, true);

        return is_array($fields) ? $fields : null;
    }

    /** Gemini answers an invalid key with 400 and the reason API_KEY_INVALID, not with 401. */
    protected function errorCode(Response $response): ?string
    {
        foreach ((array) $response->json('error.details', []) as $detail) {
            if (is_array($detail) && ($detail['reason'] ?? null) === 'API_KEY_INVALID') {
                return 'auth_401';
            }
        }

        return null;
    }

    /**
     * A JSON Schema in the Gemini API's Schema form, whose types are upper case (OBJECT, STRING).
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function schema(array $schema): array
    {
        foreach ($schema as $key => $value) {
            $schema[$key] = match (true) {
                $key === 'type' && is_string($value) => strtoupper($value),
                $key === 'properties' && is_array($value) => array_map(self::schema(...), $value),
                $key === 'items' && is_array($value) => self::schema($value),
                default => $value,
            };
        }

        return $schema;
    }
}
