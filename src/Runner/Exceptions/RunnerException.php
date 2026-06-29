<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Runner\Exceptions;

use RuntimeException;

/**
 * Carries ONLY a sanitized code from the closed vocabulary. Constructed with no
 * previous exception so `(string) $e` cannot walk back to a message/headers/body
 * that embed the HMAC secret or the runner endpoint.
 */
final class RunnerException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
