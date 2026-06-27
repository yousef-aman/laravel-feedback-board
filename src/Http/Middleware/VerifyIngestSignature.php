<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use YousefAman\FeedbackBoard\Destinations\Support\SignsPayloads;

class VerifyIngestSignature
{
    use SignsPayloads;

    public function handle(Request $request, Closure $next): mixed
    {
        abort_unless(config('feedback-board.ingest.enabled'), 404);

        $secret = (string) config('feedback-board.ingest.secret');
        $ts = (string) $request->header('X-Feedback-Timestamp', '');
        $sig = (string) $request->header('X-Feedback-Signature', '');
        abort_if($secret === '' || $ts === '' || $sig === '', 403);

        $tolerance = (int) config('feedback-board.ingest.tolerance', 300);
        abort_if(abs(now()->timestamp - (int) $ts) > $tolerance, 403, 'Stale timestamp');

        $expected = $this->sign($ts, $request->getContent(), $secret);
        abort_unless($this->verifySignature($expected, $sig), 403, 'Bad signature');

        $nonceKey = 'feedback-board:ingest:'.$sig;
        abort_unless(Cache::add($nonceKey, true, $tolerance), 403, 'Replay');

        return $next($request);
    }
}
