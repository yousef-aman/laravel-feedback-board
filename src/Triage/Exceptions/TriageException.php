<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Triage\Exceptions;

use RuntimeException;

/**
 * Carries ONLY a sanitized code from the closed vocabulary. Constructed with no
 * previous exception so `(string) $e` cannot walk back to a message/headers that
 * embed the API key.
 */
final class TriageException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
