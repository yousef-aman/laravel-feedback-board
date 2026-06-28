<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Triage\Support;

final class TriageGate
{
    public static function configured(): bool
    {
        if (! config('feedback-board.ai.enabled')) {
            return false;
        }

        $driver = (string) config('feedback-board.ai.default', 'anthropic');

        return match ($driver) {
            'anthropic' => is_string(config('feedback-board.ai.anthropic.api_key')) && config('feedback-board.ai.anthropic.api_key') !== '',
            'fake' => true,
            default => false, // unknown/typo driver → not configured (fail closed)
        };
    }
}
