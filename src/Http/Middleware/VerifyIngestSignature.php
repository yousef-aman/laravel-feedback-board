<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class VerifyIngestSignature
{
    public function handle(Request $request, Closure $next): mixed
    {
        abort_unless(config('feedback-board.ingest.enabled'), 404);

        $secret = (string) config('feedback-board.ingest.secret');
        $ts = (string) $request->header('X-Feedback-Timestamp', '');
        $sig = (string) $request->header('X-Feedback-Signature', '');
        abort_if($secret === '' || $ts === '' || $sig === '', 403);

        $tolerance = (int) config('feedback-board.ingest.tolerance', 300);
        abort_if(abs(now()->timestamp - (int) $ts) > $tolerance, 403, 'Stale timestamp');

        $expected = hash_hmac('sha256', $ts.'.'.$request->getContent(), $secret);
        abort_unless(hash_equals($expected, $sig), 403, 'Bad signature');

        // replay protection: a given signature may be used once within the window
        $nonceKey = 'feedback-board:ingest:'.$sig;
        abort_if(Cache::has($nonceKey), 403, 'Replay');
        Cache::put($nonceKey, true, $tolerance);

        return $next($request);
    }
}
