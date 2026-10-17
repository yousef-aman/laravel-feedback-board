<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Runner\Support;

use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Illuminate\Support\Carbon;
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

    /**
     * True when an in-flight task (dispatched or running) has shown no activity for
     * runner.stale_after seconds, so a developer may re-send it. Activity is the last write
     * (updated_at, which merge() sets), else the dispatch time. A value that cannot be parsed
     * (an empty string included) counts as stale; a task with no timestamp at all (a row written
     * by hand) does not, because there is nothing to measure. stale_after = 0 turns staleness off.
     *
     * @param  array<string, mixed>|null  $runner
     */
    public static function isStale(?array $runner): bool
    {
        $after = (int) config('feedback-board.runner.stale_after', 1800);

        if ($after <= 0 || ! in_array($runner['status'] ?? null, ['dispatched', 'running'], true)) {
            return false;
        }

        $value = $runner['updated_at'] ?? $runner['dispatched_at'] ?? null;

        if ($value === null) {
            return false;
        }

        $at = self::parseTime($value);

        // Carbon 3's diffInSeconds() is a signed float: compare points in time instead.
        return $at === null || $at->addSeconds($after)->isPast();
    }

    /**
     * A runner timestamp as a date, or null when it is not a non-empty string Carbon can parse
     * (Carbon reads '' as now).
     */
    public static function parseTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (InvalidFormatException) {
            return null;
        }
    }

    /**
     * Whether a developer may (re-)send the item to the runner: nothing is in flight, or the
     * in-flight task is stale.
     *
     * @param  array<string, mixed>|null  $runner
     */
    public static function canDispatch(?array $runner): bool
    {
        return ! in_array($runner['status'] ?? null, ['dispatched', 'running'], true)
            || self::isStale($runner);
    }
}
