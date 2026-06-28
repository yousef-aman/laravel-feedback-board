<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Triage\Contracts;

use YousefAman\FeedbackBoard\Triage\Support\TriageResult;

interface TriageDriver
{
    /** Receives text only (data minimization is enforced by this signature). */
    public function triage(string $title, ?string $description): TriageResult;
}
