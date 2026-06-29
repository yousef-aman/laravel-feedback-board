<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Runner\Support;

use YousefAman\FeedbackBoard\Models\FeedbackItem;

final class RunnerTaskPayload
{
    /**
     * Curated whitelist — the raw model is never serialized. Unlike WebhookPayload,
     * this excludes ALL reporter_* fields (a code runner has no need for reporter PII).
     * title/description/triage.summary are reporter-authored free text sent verbatim.
     *
     * @return array<string, mixed>
     */
    public static function build(FeedbackItem $item, string $taskId, ?string $callbackUrl): array
    {
        $env = [
            'task_id' => $taskId,
            'event' => 'runner.task',
            'item' => [
                'id' => $item->id,
                'type' => $item->type?->value,
                'status' => $item->status?->value,
                'priority' => $item->priority?->value,
                'title' => $item->title,
                'description' => $item->description,
                'project' => $item->project,
                'created_at' => $item->created_at?->toIso8601String(),
            ],
        ];

        $triage = $item->ai_state['triage'] ?? null;
        if (is_array($triage) && ($triage['status'] ?? null) === 'completed') {
            $env['triage'] = [
                'suggested_type' => $triage['suggested_type'] ?? null,
                'suggested_priority' => $triage['suggested_priority'] ?? null,
                'summary' => $triage['summary'] ?? null,
            ];
        }

        $callback = ['item_id' => $item->id, 'task_id' => $taskId];
        if (is_string($callbackUrl) && $callbackUrl !== '') {
            $callback = ['ingest_url' => $callbackUrl] + $callback;
        }
        $env['callback'] = $callback;

        return $env;
    }
}
