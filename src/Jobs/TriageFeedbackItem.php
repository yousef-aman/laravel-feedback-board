<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Throwable;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Triage\Exceptions\TriageException;
use YousefAman\FeedbackBoard\Triage\Support\TriageError;
use YousefAman\FeedbackBoard\Triage\TriageManager;

class TriageFeedbackItem implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $itemId)
    {
        /** Never run against an uncommitted/rolled-back row. */
        $this->afterCommit = true;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(TriageManager $manager): void
    {
        $item = FeedbackItem::find($this->itemId);
        if ($item === null) {
            return; // deleted between enqueue and processing — no-op
        }

        try {
            $result = $manager->driver((string) config('feedback-board.ai.default'))
                ->triage($item->title, $item->description); // text only
        } catch (TriageException $e) {
            // Transient → rethrow so the job retries (tries/backoff); failed() writes
            // the terminal state on exhaustion.
            if (in_array($e->errorCode, ['http_429', 'http_5xx', 'connection_error'], true)) {
                throw $e;
            }
            // Permanent → write the terminal failed state NOW. We do not use
            // $this->fail(): InteractsWithQueue::fail() is a no-op when $this->job is
            // null (direct/synchronous invocation), which would leave ai_state stuck
            // 'pending'. Writing directly guarantees the terminal state in every context.
            $this->writeFailed($this->itemId, $e->errorCode);

            return;
        }

        $this->writeTriage($this->itemId, [
            'status' => 'completed',
            'model' => $result->model,
            'suggested_type' => $result->type?->value,
            'suggested_priority' => $result->priority?->value,
            'summary' => $result->summary,
            'generated_at' => now()->toIso8601String(),
            'error_code' => null,
        ]);
    }

    public function failed(Throwable $e): void
    {
        // Reached when a transient error exhausts $tries on a real queue.
        $this->writeFailed($this->itemId, TriageError::code($e));
    }

    /** Write the terminal failed state (best-effort lock; never rethrows a lock timeout). */
    private function writeFailed(int $id, string $code): void
    {
        $this->writeTriage($id, [
            'status' => 'failed',
            'model' => null,
            'suggested_type' => null,
            'suggested_priority' => null,
            'summary' => null,
            'generated_at' => now()->toIso8601String(),
            'error_code' => $code,
        ], swallowLockTimeout: true);
    }

    /**
     * Write ONLY the ai_state column under the per-item triage lock. Cross-column
     * safety comes from (a) only ai_state being dirty and (b) the fresh re-read —
     * not from the lock, which serializes triage-vs-triage writes. In failed() a
     * LockTimeoutException is swallowed so the failure record never crashes the worker.
     *
     * @param array<string, mixed> $triage
     */
    private function writeTriage(int $id, array $triage, bool $swallowLockTimeout = false): void
    {
        $store = config('feedback-board.ai.lock_store');

        try {
            Cache::store($store)->lock('feedback-board:ai_state:'.$id, 10)->block(8, function () use ($id, $triage) {
                $fresh = FeedbackItem::find($id);
                if ($fresh === null) {
                    return;
                }
                $ai = $fresh->ai_state ?? [];
                $ai['triage'] = $triage;
                $fresh->ai_state = $ai;
                $fresh->save();
            });
        } catch (LockTimeoutException $e) {
            if (! $swallowLockTimeout) {
                throw $e;
            }
        }
    }
}
