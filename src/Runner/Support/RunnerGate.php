<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Runner\Support;

final class RunnerGate
{
    public static function configured(): bool
    {
        if (! config('feedback-board.runner.enabled')) {
            return false;
        }

        $endpoint = config('feedback-board.runner.endpoint');
        $secret = config('feedback-board.runner.secret');

        return is_string($endpoint) && $endpoint !== ''
            && is_string($secret) && $secret !== '';
    }
}
