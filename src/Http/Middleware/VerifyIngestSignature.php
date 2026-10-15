<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use YousefAman\FeedbackBoard\Destinations\Support\SignsPayloads;

class VerifyIngestSignature
{
    use SignsPayloads;

    public function handle(Request $request, Closure $next): mixed
    {
        if (! config('feedback-board.ingest.enabled')) {
            return self::deny(404, 'Not Found');
        }

        $secret = (string) config('feedback-board.ingest.secret');
        $ts = (string) $request->header('X-Feedback-Timestamp', '');
        $sig = (string) $request->header('X-Feedback-Signature', '');

        if ($secret === '' || $ts === '' || $sig === '') {
            return self::deny(403, 'Missing signature');
        }

        $tolerance = (int) config('feedback-board.ingest.tolerance', 300);

        if (abs(now()->timestamp - (int) $ts) > $tolerance) {
            return self::deny(403, 'Stale timestamp');
        }

        $expected = $this->sign($ts, $request->getContent(), $secret);

        if (! $this->verifySignature($expected, $sig)) {
            return self::deny(403, 'Bad signature');
        }

        $nonceKey = 'feedback-board:ingest:'.$sig;

        if (! Cache::add($nonceKey, true, $tolerance)) {
            return self::deny(403, 'Replay');
        }

        return $next($request);
    }

    /** Answered here as JSON, so the host's exception rendering cannot change it. */
    private static function deny(int $status, string $message): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }
}
