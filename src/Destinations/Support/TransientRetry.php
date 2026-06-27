<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Destinations\Support;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

final class TransientRetry
{
    /**
     * Retry predicate for Http::retry(): retry ONLY transient failures —
     * connection errors, HTTP 429, and 5xx. Permanent 4xx (422 validation,
     * 404 repo, 401 auth) are never retried. Without this predicate, retry()
     * retries on every non-2xx (verified in PendingRequest::retry()).
     */
    public static function when(): Closure
    {
        return function (\Throwable $e): bool {
            if ($e instanceof ConnectionException) {
                return true;
            }
            if ($e instanceof RequestException) {
                $status = $e->response->status();

                return $status === 429 || $status >= 500;
            }

            return false;
        };
    }
}
