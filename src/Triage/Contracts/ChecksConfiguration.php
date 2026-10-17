<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Triage\Contracts;

/**
 * A triage driver that can tell whether its settings are complete. Triage runs only when the
 * selected driver says so; a driver without this interface counts as configured.
 */
interface ChecksConfiguration
{
    public function configured(): bool;
}
