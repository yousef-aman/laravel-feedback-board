<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Destinations\Support;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Throwable;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

/**
 * The failure a destination records (external_refs.{driver}_last_error, written by
 * DeliverToDestination::failed()) and its clearing. A driver clears it only after its request got
 * through, never when it skipped a delivery, so the board shows a failure while the destination is
 * failing.
 */
final class DeliveryRecord
{
    /**
     * Best-effort, after a delivery has been made: a lock timeout leaves the record, and any other
     * error is reported, never thrown, since throwing would retry a delivery that already arrived.
     */
    public static function clearFailure(FeedbackItem $item, string $driver): void
    {
        $key = $driver.'_last_error';

        // Only DeliverToDestination::failed() writes the key, so the item the job loaded tells.
        if (! array_key_exists($key, $item->external_refs ?? [])) {
            return;
        }

        try {
            Cache::store(config('feedback-board.destinations.lock_store'))
                ->lock('feedback-board:github:'.$item->id, 10)
                ->block(5, function () use ($item, $key) {
                    $fresh = FeedbackItem::find($item->id);
                    $refs = $fresh?->external_refs ?? [];

                    if ($fresh !== null && array_key_exists($key, $refs)) {
                        unset($refs[$key]);
                        $fresh->external_refs = $refs;
                        $fresh->save();
                    }
                });
        } catch (LockTimeoutException) {
            // Under contention a stale record is the lesser harm.
        } catch (Throwable $e) {
            report($e);
        }
    }
}
