<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Triage\Support;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;
use YousefAman\FeedbackBoard\Jobs\TriageFeedbackItem;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

final class TriageDispatcher
{
    /**
     * Mark the item pending (best-effort debounce) and enqueue the after-commit job.
     *
     * Pass $locked = true from the MANUAL re-triage path (RequestTriage) so the
     * ai_state read-modify-write is serialised against concurrent runner writes that
     * share the same JSON column. Leave $locked = false (default) when called from
     * TriageNewFeedback: that listener runs inside the item-creation transaction, where
     * acquiring a cache lock is a footgun and the new row cannot yet race a runner write.
     *
     * The unlocked path must never fail the reporter's submission: the job is dispatched
     * after commit inside rescue(), so a transient provider error rethrown by the sync
     * queue, or a failed push (e.g. Redis down), is reported and recorded as a failed
     * triage instead of propagating. The locked (manual) path lets errors reach the developer.
     */
    public static function dispatch(FeedbackItem $item, bool $locked = false): void
    {
        $proceed = $locked
            ? self::lockedWritePending($item->id)
            : self::writePending($item->id);

        if (! $proceed) {
            return;
        }

        if ($locked) {
            TriageFeedbackItem::dispatch($item->id);

            return;
        }

        // Bus::dispatch, not ::dispatch(): a PendingDispatch fires from its destructor, outside rescue().
        $id = $item->id;
        DB::afterCommit(fn () => rescue(
            fn () => Bus::dispatch(new TriageFeedbackItem($id)),
            fn (Throwable $e) => (new TriageFeedbackItem($id))->failed($e),
            report: true,
        ));
    }

    /**
     * Unlocked read-modify-write: safe inside the creation transaction where no concurrent
     * runner write can exist yet (the item was just inserted).
     *
     * @return bool true if pending was written (proceed to dispatch), false if debounced/gone
     */
    private static function writePending(int $id): bool
    {
        $fresh = FeedbackItem::find($id);
        if ($fresh === null) {
            return false;
        }

        $triage = $fresh->ai_state['triage'] ?? null;
        if (is_array($triage) && ($triage['status'] ?? null) === 'pending') {
            return false; // debounce — a run is already in flight
        }

        $ai = $fresh->ai_state ?? [];
        $ai['triage'] = ['status' => 'pending', 'generated_at' => now()->toIso8601String()];
        $fresh->ai_state = $ai;
        $fresh->save();

        return true;
    }

    /**
     * Locked read-modify-write: serialises against concurrent runner writes on ai_state.
     * Used by the MANUAL re-triage path so a racing runner write does not lose its
     * ai_state.runner sub-key to a stale read.
     *
     * @return bool true if pending was written (proceed to dispatch), false if debounced/gone
     */
    private static function lockedWritePending(int $id): bool
    {
        $store = config('feedback-board.ai.lock_store');

        return (bool) Cache::store($store)
            ->lock('feedback-board:ai_state:'.$id, 10)
            ->block(8, fn () => self::writePending($id));
    }
}
