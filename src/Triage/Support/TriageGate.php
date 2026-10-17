<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Triage\Support;

use YousefAman\FeedbackBoard\Triage\TriageManager;

final class TriageGate
{
    /** Triage is on and its selected driver can run (see TriageManager::configured()). */
    public static function configured(): bool
    {
        if (! config('feedback-board.ai.enabled')) {
            return false;
        }

        return app(TriageManager::class)->configured();
    }
}
