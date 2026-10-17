<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Triage\Drivers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use YousefAman\FeedbackBoard\Triage\Support\TriagePrompt;

/**
 * OpenAI's Chat Completions, with the triage as a forced function call. base_url points it at any API
 * that speaks the same protocol (DeepSeek, Mistral, OpenRouter, Ollama, ...).
 */
final class OpenAiDriver extends HttpTriageDriver
{
    protected function provider(): string
    {
        return 'openai';
    }

    protected function defaultModel(): string
    {
        return 'gpt-6-luna';
    }

    protected function send(PendingRequest $http, string $key, string $model, string $title, ?string $description): Response
    {
        $body = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => TriagePrompt::system()],
                ['role' => 'user', 'content' => TriagePrompt::user($title, $description, (int) config('feedback-board.ai.max_input_chars', 4000))],
            ],
            'tools' => [TriagePrompt::function()],
            'tool_choice' => ['type' => 'function', 'function' => ['name' => TriagePrompt::TOOL]],
        ];

        // OpenAI's reasoning models call functions in Chat Completions only with reasoning_effort none;
        // an empty setting leaves the field out for a provider that rejects it.
        $effort = $this->setting('reasoning_effort');
        if ($effort !== '') {
            $body['reasoning_effort'] = $effort;
        }

        // Reasoning models reject max_tokens; max_completion_tokens is the cap they all take.
        $cap = $this->positiveInt('max_tokens');
        if ($cap !== null) {
            $body['max_completion_tokens'] = $cap;
        }

        $base = $this->setting('base_url');

        return $http->withToken($key)
            ->post(rtrim($base !== '' ? $base : 'https://api.openai.com/v1', '/').'/chat/completions', $body);
    }

    protected function fields(Response $response): ?array
    {
        foreach ((array) $response->json('choices.0.message.tool_calls', []) as $call) {
            if (! is_array($call) || ($call['function']['name'] ?? null) !== TriagePrompt::TOOL) {
                continue;
            }

            $arguments = $call['function']['arguments'] ?? null;
            $fields = is_string($arguments) ? json_decode($arguments, true) : $arguments;

            return is_array($fields) ? $fields : null;
        }

        return null;
    }
}
