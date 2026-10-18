<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Support;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use YousefAman\FeedbackBoard\Enums\FeedbackStatus;
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

    /**
     * Adds `developer_read_at` (the developer's read of each report, or NULL) and
     * `developer_unread_count` (comments by others newer than that read, else than the baseline).
     */
    public static function apply(Builder $items, Model $developer, CarbonInterface $baseline): Builder
    {
        $model = $items->getModel();

        if ($items->getQuery()->columns === null) {
            $items->select($model->getTable().'.*');
        }

        // At most one row: the reads table is unique on reader and report.
        $readAt = self::reads($items->getQuery(), $model->getQualifiedKeyName(), $developer)
            ->select((new FeedbackRead)->getTable().'.read_at');

        return $items
            ->selectSub($readAt, 'developer_read_at')
            ->withCount([
                'comments as developer_unread_count' => fn (Builder $comments) => self::unreadComments($comments, $developer, $baseline),
            ]);
    }

    /**
     * The card's markers, from the columns apply() added. A report is new when the developer never
     * opened it, it is open, it came after the baseline, and the developer did not send it.
     *
     * @return array{new: bool, unread: int}
     */
    public static function marker(FeedbackItem $item, Model $developer, CarbonInterface $baseline): array
    {
        return [
            'new' => $item->developer_read_at === null
                && $item->status->isOpen()
                && $item->created_at->gt($baseline)
                && ! $item->isReportedBy($developer),
            'unread' => (int) $item->developer_unread_count,
        ];
    }

    /**
     * Reports in any status that are new or have an unread comment, in one query. With no baseline
     * every comparison against it is NULL, so the count is 0; this never writes one.
     */
    public static function cardsWithNews(Model $developer): int
    {
        return self::safely(function () use ($developer): int {
            $items = FeedbackItem::query();
            $model = $items->getModel();
            $table = $model->getTable();
            $reads = (new FeedbackRead)->getTable();
            $grammar = $items->getQuery()->getGrammar();
            $open = array_map(fn (FeedbackStatus $status): string => $status->value, FeedbackStatus::openCases());

            $baseline = $items->getQuery()->newQuery()
                ->from($reads)
                ->selectRaw('min('.$grammar->wrap($reads.'.read_at').')')
                ->whereNull($reads.'.feedback_item_id')
                ->where($reads.'.reader_type', $developer->getMorphClass())
                ->where($reads.'.reader_id', $developer->getKey());

            return $items
                ->where(fn (Builder $news) => $news
                    ->where(fn (Builder $new) => $new
                        ->whereNotExists(self::reads($items->getQuery(), $model->getQualifiedKeyName(), $developer))
                        ->whereIn($table.'.status', $open)
                        ->where($table.'.created_at', '>', $baseline)
                        ->where(fn (Builder $notSent) => $notSent
                            ->whereNull($table.'.reporter_type')
                            ->orWhere($table.'.reporter_type', '<>', $developer->getMorphClass())
                            ->orWhere($table.'.reporter_id', '<>', $developer->getKey())))
                    ->orWhereHas('comments', fn (Builder $comments) => self::unreadComments($comments, $developer, $baseline)))
                ->count();
        }, 0);
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

    /** The developer's read rows of the report whose key is in the column $item of the outer query. */
    private static function reads(QueryBuilder $outer, string $item, Model $developer): QueryBuilder
    {
        $reads = (new FeedbackRead)->getTable();

        return $outer->newQuery()
            ->from($reads)
            ->whereColumn($reads.'.feedback_item_id', $item)
            ->where($reads.'.reader_type', $developer->getMorphClass())
            ->where($reads.'.reader_id', $developer->getKey());
    }

    /**
     * Comments, internal or not, newer than the developer's read of their report, else than the
     * baseline (a time, or a subselect of it), and not the developer's own. A comment with no
     * author is someone else's. Second precision: a comment in the same second as the read is read.
     */
    private static function unreadComments(Builder $comments, Model $developer, CarbonInterface|QueryBuilder $baseline): void
    {
        $grammar = $comments->getQuery()->getGrammar();
        $table = $comments->getModel()->getTable();
        $readAt = self::reads($comments->getQuery(), $table.'.feedback_item_id', $developer)
            ->select((new FeedbackRead)->getTable().'.read_at');
        [$since, $sinceBindings] = $baseline instanceof QueryBuilder
            ? ['('.$baseline->toSql().')', $baseline->getBindings()]
            : ['?', [$baseline]];

        $comments
            ->where(fn (Builder $others) => $others
                ->whereNull($table.'.author_type')
                ->orWhere($table.'.author_type', '<>', $developer->getMorphClass())
                ->orWhere($table.'.author_id', '<>', $developer->getKey()))
            ->whereRaw(
                $grammar->wrap($table.'.created_at').' > coalesce(('.$readAt->toSql().'), '.$since.')',
                [...$readAt->getBindings(), ...$sinceBindings],
            );
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
