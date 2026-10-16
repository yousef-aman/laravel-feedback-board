<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Runs first on the ingest route, so the errors the package does not answer itself (429 throttle,
 * 409 task mismatch, 503 lock, a 404 for a missing report) render as JSON with Laravel's default
 * renderer, whatever Accept header the sender used.
 */
final class AcceptJson
{
    public function handle(Request $request, Closure $next): mixed
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
