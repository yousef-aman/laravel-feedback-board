<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Runner\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

final class RunnerState
{
    /**
     * Run $fn(FeedbackItem $fresh) inside the per-item ai_state lock with a fresh
     * re-read. No-op if the row is gone. On a synchronous path a LockTimeoutException
     * bubbles (the caller converts it to 503); the queued terminal write passes
     * $swallowLockTimeout = true.
     */
    public static function lock(int $id, Closure $fn, bool $swallowLockTimeout = false): void
    {
        $store = config('feedback-board.ai.lock_store');

        try {
            Cache::store($store)->lock('feedback-board:ai_state:'.$id, 10)->block(8, function () use ($id, $fn) {
                $fresh = FeedbackItem::find($id);
                if ($fresh === null) {
                    return;
                }
                $fn($fresh);
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            if (! $swallowLockTimeout) {
                throw $e;
            }
        }
    }

    /**
     * Merge $patch into ai_state.runner (+ updated_at) and save. Only the ai_state
     * column is dirtied, so a concurrent external_refs write is never clobbered.
     *
     * @param array<string, mixed> $patch
     */
    public static function merge(FeedbackItem $item, array $patch): void
    {
        $ai = $item->ai_state ?? [];
        $runner = $ai['runner'] ?? [];
        $ai['runner'] = array_merge($runner, $patch, ['updated_at' => now()->toIso8601String()]);
        $item->ai_state = $ai;
        $item->save();
    }
}
