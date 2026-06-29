<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Actions;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use YousefAman\FeedbackBoard\Jobs\DispatchToRunnerJob;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy;
use YousefAman\FeedbackBoard\Runner\Support\RunnerGate;
use YousefAman\FeedbackBoard\Runner\Support\RunnerState;

class DispatchToRunner
{
    public function handle(FeedbackItem $item, ?Model $actor): void
    {
        abort_unless(FeedbackItemPolicy::isDeveloper($actor), 403);
        abort_unless(RunnerGate::configured(), 422);
        abort_unless((bool) config('feedback-board.ingest.enabled'), 422);

        $taskId = null;

        try {
            RunnerState::lock($item->id, function (FeedbackItem $fresh) use (&$taskId) {
                $runner = $fresh->ai_state['runner'] ?? [];
                if (in_array($runner['status'] ?? null, ['dispatched', 'running'], true)) {
                    return; // silent debounce — leaves $taskId null
                }
                $taskId = (string) Str::uuid();
                RunnerState::merge($fresh, [
                    'status' => 'dispatched',
                    'task_id' => $taskId,
                    'dispatched_at' => now()->toIso8601String(),
                    'model' => null,
                    'analysis_posted' => false,
                    'diff' => null,
                    'pr_url' => null,
                    'failed_origin' => null,
                    'error_code' => null,
                ]);
            });
        } catch (LockTimeoutException $e) {
            abort(503); // synchronous path: do not swallow — let the caller retry
        }

        if ($taskId === null) {
            return; // in-flight: silent no-op
        }

        DispatchToRunnerJob::dispatch($item->id, $taskId);
    }
}
