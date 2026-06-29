<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Runner\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;
use YousefAman\FeedbackBoard\Runner\Exceptions\RunnerException;

final class RunnerError
{
    /** Map any throwable to a fixed, secret-free vocabulary. Default: 'unknown'. */
    public static function code(Throwable $e): string
    {
        if ($e instanceof RunnerException) {
            return $e->errorCode;
        }

        if ($e instanceof ConnectionException) {
            return 'connection_error';
        }

        if ($e instanceof RequestException) {
            $status = $e->response?->status() ?? 0;

            return match (true) {
                $status === 401 || $status === 403 => 'auth_401',
                $status === 404 => 'not_found_404',
                $status === 422 => 'validation_422',
                $status === 429 => 'http_429',
                $status >= 500 => 'http_5xx',
                default => 'unknown',
            };
        }

        return 'unknown';
    }
}
