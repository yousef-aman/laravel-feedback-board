<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Triage\Drivers;

use YousefAman\FeedbackBoard\Triage\Contracts\TriageDriver;
use YousefAman\FeedbackBoard\Triage\Support\TriageResult;

final class AnthropicDriver implements TriageDriver
{
    public function triage(string $title, ?string $description): TriageResult
    {
        // Replaced with the real implementation in Task 5.
        throw new \RuntimeException('not implemented');
    }
}
