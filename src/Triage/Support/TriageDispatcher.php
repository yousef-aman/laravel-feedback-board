<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Triage\Support;

use YousefAman\FeedbackBoard\Jobs\TriageFeedbackItem;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

final class TriageDispatcher
{
    /**
     * Mark the item pending (best-effort debounce) and enqueue the after-commit job.
     * No lock: this may run inside the item-creation transaction, where a cache lock
     * would be a footgun. The pending write is a simple read-check-write on a fresh
     * row; the job's terminal write (off-transaction) is the one that locks.
     */
    public static function dispatch(FeedbackItem $item): void
    {
        $fresh = FeedbackItem::find($item->id);
        if ($fresh === null) {
            return;
        }

        $triage = $fresh->ai_state['triage'] ?? null;
        if (is_array($triage) && ($triage['status'] ?? null) === 'pending') {
            return; // debounce — a run is already in flight
        }

        $ai = $fresh->ai_state ?? [];
        $ai['triage'] = ['status' => 'pending', 'generated_at' => now()->toIso8601String()];
        $fresh->ai_state = $ai;
        $fresh->save();

        TriageFeedbackItem::dispatch($item->id); // $afterCommit = true on the job
    }
}
