<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Support;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Models\FeedbackRead;

/** What each developer has read: one row per report, and a baseline row for their first board visit. */
final class DeveloperReads
{
    private static bool $warned = false;

    /** The developer's first board visit, or null when there is none (or the table is missing). */
    public static function baseline(Model $developer): ?CarbonInterface
    {
        return self::safely(fn (): ?CarbonInterface => self::findBaseline($developer), null);
    }

    /** The baseline, written now when the developer has none yet. */
    public static function begin(Model $developer): ?CarbonInterface
    {
        return self::safely(function () use ($developer): CarbonInterface {
            $baseline = self::findBaseline($developer);

            if ($baseline !== null) {
                return $baseline;
            }

            $now = Carbon::now();
            FeedbackRead::query()->create([
                'feedback_item_id' => null,
                'reader_type' => $developer->getMorphClass(),
                'reader_id' => $developer->getKey(),
                'read_at' => $now,
            ]);

            return $now;
        }, null);
    }

    /** Records that the developer has read the report as of now. */
    public static function markRead(FeedbackItem $item, Model $developer): void
    {
        self::safely(function () use ($item, $developer): null {
            FeedbackRead::query()->upsert([[
                'feedback_item_id' => $item->getKey(),
                'reader_type' => $developer->getMorphClass(),
                'reader_id' => $developer->getKey(),
                'read_at' => Carbon::now(),
            ]], ['reader_type', 'reader_id', 'feedback_item_id'], ['read_at']);

            return null;
        }, null);
    }

    /** @internal Test seam: the warning is logged once per process. */
    public static function resetMigrationWarning(): void
    {
        self::$warned = false;
    }

    private static function findBaseline(Model $developer): ?CarbonInterface
    {
        return FeedbackRead::query()
            ->whereNull('feedback_item_id')
            ->where('reader_type', $developer->getMorphClass())
            ->where('reader_id', $developer->getKey())
            ->orderBy('read_at')
            ->first()
            ?->read_at;
    }

    private static function safely(Closure $run, mixed $fallback): mixed
    {
        try {
            return $run();
        } catch (QueryException $e) {
            if (! self::$warned) {
                self::$warned = true;
                Log::warning('feedback-board: reading the developer read state failed; publish and run the package migrations.', ['exception' => $e->getMessage()]);
            }

            return $fallback;
        }
    }
}
