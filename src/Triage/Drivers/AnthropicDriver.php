<?php
// src/Triage/Drivers/AnthropicDriver.php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Triage\Drivers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use YousefAman\FeedbackBoard\Triage\Support\TriagePrompt;

/** Anthropic's Messages API, with the triage as a forced tool call. */
final class AnthropicDriver extends HttpTriageDriver
{
    protected function provider(): string
    {
        return 'anthropic';
    }

    protected function defaultModel(): string
    {
        return 'claude-haiku-4-5';
    }

    protected function send(PendingRequest $http, string $key, string $model, string $title, ?string $description): Response
    {
        return $http->withHeaders([
            'x-api-key' => $key,
            'anthropic-version' => '2023-06-01',
        ])->post('https://api.anthropic.com/v1/messages', [
            'model' => $model,
            // The Messages API requires a cap.
            'max_tokens' => $this->positiveInt('max_tokens') ?? 512,
            'system' => TriagePrompt::system(),
            'messages' => [[
                'role' => 'user',
                'content' => TriagePrompt::user($title, $description, (int) config('feedback-board.ai.max_input_chars', 4000)),
            ]],
            'tools' => [TriagePrompt::tool()],
            'tool_choice' => ['type' => 'tool', 'name' => TriagePrompt::TOOL],
        ]);
    }

    protected function fields(Response $response): ?array
    {
        $block = collect((array) $response->json('content', []))
            ->first(fn ($b) => is_array($b)
                && ($b['type'] ?? null) === 'tool_use'
                && ($b['name'] ?? null) === TriagePrompt::TOOL);

        $input = is_array($block) ? ($block['input'] ?? null) : null;

        return is_array($input) ? $input : null;
    }
}
