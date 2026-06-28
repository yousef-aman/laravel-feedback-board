<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Triage\Drivers;

use Illuminate\Support\Str;
use YousefAman\FeedbackBoard\Enums\FeedbackPriority;
use YousefAman\FeedbackBoard\Enums\FeedbackType;
use YousefAman\FeedbackBoard\Triage\Contracts\TriageDriver;
use YousefAman\FeedbackBoard\Triage\Support\TriageResult;

final class FakeTriageDriver implements TriageDriver
{
    public function triage(string $title, ?string $description): TriageResult
    {
        return new TriageResult(
            FeedbackType::Bug,
            FeedbackPriority::Medium,
            Str::limit($title, 100),
            'fake',
        );
    }
}
