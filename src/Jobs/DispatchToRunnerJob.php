<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Runner\Exceptions\RunnerException;
use YousefAman\FeedbackBoard\Runner\RunnerDispatcher;
use YousefAman\FeedbackBoard\Runner\Support\RunnerError;
use YousefAman\FeedbackBoard\Runner\Support\RunnerState;

class DispatchToRunnerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $itemId, public string $taskId)
    {
        // Never POST a task for an uncommitted/rolled-back dispatch write.
        $this->afterCommit = true;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(RunnerDispatcher $dispatcher): void
    {
        $item = FeedbackItem::find($this->itemId);
        if ($item === null) {
            return; // deleted between enqueue and processing
        }

        // Best-effort, unlocked stale-dispatch guard: a newer dispatch superseded us.
        if (($item->ai_state['runner']['task_id'] ?? null) !== $this->taskId) {
            return;
        }

        try {
            $dispatcher->send($item, $this->taskId); // leaves status 'dispatched'; runner advances it
        } catch (RunnerException $e) {
            if (in_array($e->errorCode, ['http_429', 'http_5xx', 'connection_error'], true)) {
                throw $e; // transient → retry; failed() writes terminal on exhaustion
            }
            $this->writeFailed($e->errorCode);
        } catch (Throwable $e) {
            $this->writeFailed(RunnerError::code($e));
        }
    }

    public function failed(Throwable $e): void
    {
        $this->writeFailed(RunnerError::code($e));
    }

    /** Terminal dispatch-side failure; only if our task_id is still live; lock swallowed. */
    private function writeFailed(string $code): void
    {
        RunnerState::lock($this->itemId, function (FeedbackItem $fresh) use ($code) {
            if (($fresh->ai_state['runner']['task_id'] ?? null) !== $this->taskId) {
                return; // a newer dispatch owns the slot now
            }
            if (($fresh->ai_state['runner']['status'] ?? null) !== 'dispatched') {
                return; // the runner already advanced this task_id (running/completed/failed) — do not regress
            }
            RunnerState::merge($fresh, [
                'status' => 'failed',
                'failed_origin' => 'dispatch',
                'error_code' => $code,
            ]);
        }, swallowLockTimeout: true);
    }
}
