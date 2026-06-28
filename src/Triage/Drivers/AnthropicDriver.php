<?php
// src/Triage/Drivers/AnthropicDriver.php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Triage\Drivers;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;
use YousefAman\FeedbackBoard\Destinations\Support\TransientRetry;
use YousefAman\FeedbackBoard\Enums\FeedbackPriority;
use YousefAman\FeedbackBoard\Enums\FeedbackType;
use YousefAman\FeedbackBoard\Triage\Contracts\TriageDriver;
use YousefAman\FeedbackBoard\Triage\Exceptions\TriageException;
use YousefAman\FeedbackBoard\Triage\Support\TriageError;
use YousefAman\FeedbackBoard\Triage\Support\TriagePrompt;
use YousefAman\FeedbackBoard\Triage\Support\TriageResult;

final class AnthropicDriver implements TriageDriver
{
    public function triage(string $title, ?string $description): TriageResult
    {
        $key = config('feedback-board.ai.anthropic.api_key');
        if (! is_string($key) || $key === '') {
            throw new TriageException('not_configured');
        }

        $model = (string) config('feedback-board.ai.anthropic.model', 'claude-haiku-4-5');
        $maxTokens = max(1, (int) config('feedback-board.ai.anthropic.max_tokens', 512));
        $timeout = (int) config('feedback-board.ai.anthropic.timeout', 30);
        $maxChars = (int) config('feedback-board.ai.max_input_chars', 4000);

        try {
            $resp = Http::withHeaders([
                    'x-api-key' => $key,
                    'anthropic-version' => '2023-06-01',
                ])
                ->timeout($timeout)
                // retry() narrows to connection/429/5xx via TransientRetry; throw:false
                // returns the final response so guardResponse() decides.
                ->retry(2, 200, TransientRetry::when(), throw: false)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $model,
                    'max_tokens' => $maxTokens,
                    'system' => TriagePrompt::system(),
                    'messages' => [[
                        'role' => 'user',
                        'content' => TriagePrompt::user($title, $description, $maxChars),
                    ]],
                    'tools' => [TriagePrompt::tool()],
                    'tool_choice' => ['type' => 'tool', 'name' => 'submit_triage'],
                ]);

            $this->guardResponse($resp);

            return $this->parse($resp, $model);
        } catch (TriageException $e) {
            throw $e; // already sanitized (e.g. invalid_output)
        } catch (Throwable $e) {
            // Convert every transport-layer throwable (whose message/trace can embed
            // the x-api-key header) into a sanitized code with NO previous.
            throw new TriageException(TriageError::code($e));
        }
    }

    /** Surface a non-2xx final response as a RequestException (sanitized by the caller). */
    private function guardResponse(Response $resp): void
    {
        if ($resp->successful()) {
            return;
        }
        $resp->throw();
    }

    private function parse(Response $resp, string $model): TriageResult
    {
        $block = collect((array) $resp->json('content', []))
            ->first(fn ($b) => is_array($b)
                && ($b['type'] ?? null) === 'tool_use'
                && ($b['name'] ?? null) === 'submit_triage');

        $input = is_array($block) ? ($block['input'] ?? null) : null;
        if (! is_array($input)) {
            throw new TriageException('invalid_output');
        }

        $summary = $input['summary'] ?? null;
        if (! is_string($summary) || trim($summary) === '') {
            throw new TriageException('invalid_output');
        }

        return new TriageResult(
            FeedbackType::tryFrom((string) ($input['type'] ?? '')),
            FeedbackPriority::tryFrom((string) ($input['priority'] ?? '')),
            $summary,
            $model,
        );
    }
}
