<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Triage\Support;

use YousefAman\FeedbackBoard\Enums\FeedbackPriority;
use YousefAman\FeedbackBoard\Enums\FeedbackType;

final class TriageResult
{
    public function __construct(
        public readonly ?FeedbackType $type,
        public readonly ?FeedbackPriority $priority,
        public readonly string $summary,
        public readonly string $model,
    ) {}
}
