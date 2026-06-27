<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Destinations\Exceptions;

use RuntimeException;

class RetryAfter extends RuntimeException
{
    public function __construct(public int $seconds)
    {
        parent::__construct('Retry after '.$seconds.'s');
    }
}
