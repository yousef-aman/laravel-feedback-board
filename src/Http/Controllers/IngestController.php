<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Http\Controllers;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use YousefAman\FeedbackBoard\Actions\ReplyToFeedback;
use YousefAman\FeedbackBoard\Actions\TransitionStatus;
use YousefAman\FeedbackBoard\Enums\AuthorRole;
use YousefAman\FeedbackBoard\Enums\FeedbackStatus;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Runner\Support\RunnerIngest;

class IngestController
{
    public function __invoke(Request $request, TransitionStatus $transition, ReplyToFeedback $reply): JsonResponse
    {
        if (in_array($request->input('action'), ['runner_status', 'runner_result'], true)) {
            return $this->runner($request);
        }

        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            'action' => ['required', 'in:comment,status,external_ref'],
            'body' => ['required_if:action,comment', 'string'],
            'status' => ['required_if:action,status', 'string', 'in:new,in_review,in_progress,resolved,closed,declined'],
            'ref' => ['required_if:action,external_ref', 'array'],
            'ref.key' => ['required_with:ref', 'string'],
            'ref.value' => ['required_with:ref', 'string'],
        ]);

        $item = FeedbackItem::findOrFail($data['item_id']);

        match ($data['action']) {
            'comment' => $reply->handle($item, $data['body'] ?? '', null, AuthorRole::Ai),
            'status' => $transition->handle($item, FeedbackStatus::from($data['status']), null),
            'external_ref' => $item->forceFill([
                'external_refs' => array_merge($item->external_refs ?? [], [$data['ref']['key'] => $data['ref']['value']]),
            ])->save(),
        };

        return response()->json(['ok' => true]);
    }

    private function runner(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            'action' => ['required', 'in:runner_status,runner_result'],
            'task_id' => ['required', 'string', 'max:64'],
            'status' => ['required_if:action,runner_status', 'string', 'in:running,completed,failed'],
            'error_code' => ['nullable', 'string', 'regex:/^[a-z0-9_]{1,40}$/'],
            'analysis' => ['nullable', 'string', 'max:'.(int) config('feedback-board.runner.max_analysis_chars', 10000)],
            'diff' => ['nullable', 'string', 'max:'.(int) config('feedback-board.runner.max_diff_chars', 20000)],
            'pr_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $item = FeedbackItem::findOrFail($data['item_id']);

        try {
            // Synchronous path: a LockTimeoutException becomes a 503 so the runner retries
            // with a fresh signature (a new timestamp ⇒ new nonce key — not blocked as replay).
            match ($data['action']) {
                'runner_status' => RunnerIngest::status($item, $data),
                'runner_result' => RunnerIngest::result($item, $data),
            };
        } catch (LockTimeoutException) {
            abort(503);
        }

        return response()->json(['ok' => true]);
    }
}
