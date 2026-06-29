<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Runner\Support;

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
}
