<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Destinations\Exceptions;

use RuntimeException;

/**
 * A delivery that failed before any response arrived. It carries only the driver and a code from
 * DeliveryError's fixed vocabulary, never the original exception: a connection error's message ends
 * with the request URL, and a webhook URL can be a secret (Slack's always is).
 */
final class DeliveryFailed extends RuntimeException
{
    public function __construct(public readonly string $driver, public readonly string $errorCode)
    {
        parent::__construct('feedback-board: '.$driver.' delivery failed ('.$errorCode.').');
    }
}
