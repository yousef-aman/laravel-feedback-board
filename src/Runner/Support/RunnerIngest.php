<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Runner\Support;

use YousefAman\FeedbackBoard\Enums\AuthorRole;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

final class RunnerIngest
{
    private const RANK = ['dispatched' => 0, 'running' => 1, 'completed' => 2, 'failed' => 2];

    public static function rank(?string $status): int
    {
        return self::RANK[$status] ?? -1;
    }

    /**
     * Apply a runner_status post-back under the per-item lock. Correlation (task_id)
     * and monotonicity are enforced inside the lock. Aborts 409 on a task_id mismatch.
     *
     * @param array<string, mixed> $data validated payload
     */
    public static function status(FeedbackItem $item, array $data): void
    {
        $taskId = (string) $data['task_id'];
        $posted = (string) $data['status'];
        $code = isset($data['error_code']) && $data['error_code'] !== '' ? (string) $data['error_code'] : 'unknown';

        $mismatch = false;
        RunnerState::lock($item->id, function (FeedbackItem $fresh) use ($taskId, $posted, $code, &$mismatch) {
            $runner = $fresh->ai_state['runner'] ?? [];
            if (($runner['task_id'] ?? null) !== $taskId) {
                $mismatch = true;
                return;
            }
            if (self::rank($posted) <= self::rank($runner['status'] ?? null)) {
                return; // idempotent / no regression
            }
            $patch = ['status' => $posted];
            if ($posted === 'failed') {
                $patch['failed_origin'] = 'runner';
                $patch['error_code'] = $code;
            }
            RunnerState::merge($fresh, $patch);
        });

        abort_if($mismatch, 409);
    }

    /**
     * Apply a runner_result post-back. task_id correlation + terminal-state gate inside
     * the lock; analysis is written as an INTERNAL comment via a flag-before-comment
     * compare-and-set (at-most-once, never duplicated). Aborts 409 on task_id mismatch.
     *
     * @param array<string, mixed> $data validated payload
     */
    public static function result(FeedbackItem $item, array $data): void
    {
        $taskId = (string) $data['task_id'];
        $analysis = isset($data['analysis']) && $data['analysis'] !== '' ? (string) $data['analysis'] : null;
        $diff = isset($data['diff']) && $data['diff'] !== '' ? (string) $data['diff'] : null;
        $prUrl = self::safePrUrl($data['pr_url'] ?? null);

        $mismatch = false;
        $rejected = false;       // runner-reported failed: no content write
        $shouldPostAnalysis = false;

        RunnerState::lock($item->id, function (FeedbackItem $fresh) use ($taskId, $analysis, $diff, $prUrl, &$mismatch, &$rejected, &$shouldPostAnalysis) {
            $runner = $fresh->ai_state['runner'] ?? [];
            if (($runner['task_id'] ?? null) !== $taskId) {
                $mismatch = true;
                return;
            }
            if (($runner['status'] ?? null) === 'failed' && ($runner['failed_origin'] ?? null) === 'runner') {
                $rejected = true; // contradictory result after a reported failure
                return;
            }

            $current = $runner['status'] ?? null;
            $patch = [];
            if ($diff !== null) {
                $patch['diff'] = $diff;
            }
            if ($prUrl !== null) {
                $patch['pr_url'] = $prUrl;
            }
            // Advance to completed when (a) a dispatch-failed item heals — our send errored
            // but the runner actually ran (equal rank, so the rank check below misses it) —
            // or (b) a non-terminal status moves forward. Only when content arrived.
            $heal = $current === 'failed' && (($runner['failed_origin'] ?? null) === 'dispatch');
            $advance = $heal || self::rank('completed') > self::rank($current);
            if ($advance && ($prUrl !== null || $diff !== null || $analysis !== null)) {
                $patch['status'] = 'completed';
                $patch['failed_origin'] = null;
                $patch['error_code'] = null;
            }
            // Compare-and-set: claim the analysis slot BEFORE creating the comment.
            if ($analysis !== null && ! ($runner['analysis_posted'] ?? false)) {
                $patch['analysis_posted'] = true;
                $shouldPostAnalysis = true;
            }
            if ($patch !== []) {
                RunnerState::merge($fresh, $patch);
            }
        });

        abort_if($mismatch, 409);

        if ($rejected) {
            return; // 200 no-op
        }

        // external_refs.pr_url under the github lock (mirrors DeliverToDestination::failed).
        if ($prUrl !== null) {
            self::writePrRef($item->id, $prUrl);
        }

        // Comment created AFTER the flag is persisted: a crash here yields a rare missing
        // comment, never a duplicate. is_internal=true is mandatory (no reporter leak).
        if ($shouldPostAnalysis) {
            app(\YousefAman\FeedbackBoard\Actions\ReplyToFeedback::class)
                ->handle($item->fresh(), (string) $analysis, null, AuthorRole::Ai, isInternal: true);
        }
    }

    /** Validate a PR URL: https only, real host, NO userinfo. Returns null if unsafe. */
    private static function safePrUrl(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        $parts = parse_url($value);
        if ($parts === false) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';
        if ($scheme !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $value;
    }

    private static function writePrRef(int $id, string $prUrl): void
    {
        $store = config('feedback-board.destinations.lock_store');
        \Illuminate\Support\Facades\Cache::store($store)->lock('feedback-board:github:'.$id, 10)->block(8, function () use ($id, $prUrl) {
            $fresh = FeedbackItem::find($id);
            if ($fresh === null) {
                return;
            }
            $fresh->forceFill([
                'external_refs' => array_merge($fresh->external_refs ?? [], ['pr_url' => $prUrl]),
            ])->save();
        });
    }
}
