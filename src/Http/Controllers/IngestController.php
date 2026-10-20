<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Http\Controllers;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
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
        $payload = self::signedPayload($request);

        if (in_array($payload['action'] ?? null, ['runner_status', 'runner_result'], true)) {
            return $this->runner($payload);
        }

        $validator = Validator::make($payload, [
            'item_id' => ['required', 'integer'],
            'action' => ['required', 'in:comment,status,external_ref'],
            // As long as an analysis may be: an AI comment is shown as Markdown, whose cost grows with it.
            'body' => ['required_if:action,comment', 'string', 'max:'.(int) config('feedback-board.runner.max_analysis_chars', 10000)],
            'status' => ['required_if:action,status', 'string', 'in:new,in_review,in_progress,resolved,closed,declined'],
            'ref' => ['required_if:action,external_ref', 'array'],
            // `\z`, not `$`: `$` would accept a trailing newline. The package's own keys are reserved.
            'ref.key' => ['required_with:ref', 'string', 'regex:/^[a-z][a-z0-9_.-]{0,63}\z/', 'not_in:github,pr_url', 'doesnt_end_with:_last_error'],
            'ref.value' => ['required_with:ref', 'string', 'max:2048'],
        ]);

        if ($validator->fails()) {
            return self::invalid($validator);
        }

        $data = $validator->validated();
        $item = FeedbackItem::findOrFail($data['item_id']);

        if ($data['action'] === 'external_ref') {
            return $this->externalRef($item, $data['ref']['key'], $data['ref']['value']);
        }

        match ($data['action']) {
            'comment' => $reply->handle($item, $data['body'] ?? '', null, AuthorRole::Ai),
            'status' => $transition->handle($item, FeedbackStatus::from($data['status']), null),
        };

        return response()->json(['ok' => true]);
    }

    /** @param  array<array-key, mixed>  $payload */
    private function runner(array $payload): JsonResponse
    {
        $validator = Validator::make($payload, [
            'item_id' => ['required', 'integer'],
            'action' => ['required', 'in:runner_status,runner_result'],
            'task_id' => ['required', 'string', 'max:64'],
            'status' => ['required_if:action,runner_status', 'string', 'in:running,completed,failed'],
            'error_code' => ['nullable', 'string', 'regex:/^[a-z0-9_]{1,40}$/'],
            'analysis' => ['nullable', 'string', 'max:'.(int) config('feedback-board.runner.max_analysis_chars', 10000)],
            'diff' => ['nullable', 'string', 'max:'.(int) config('feedback-board.runner.max_diff_chars', 20000)],
            'pr_url' => ['nullable', 'string', 'max:2048'],
        ]);

        if ($validator->fails()) {
            return self::invalid($validator);
        }

        $data = $validator->validated();
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

    /**
     * Writes one external reference under the lock every external_refs writer takes (the GitHub
     * driver, failed deliveries, the runner's PR link), on a fresh read. Direct assignment, not
     * array_merge, so a key is never renumbered.
     */
    private function externalRef(FeedbackItem $item, string $key, string $value): JsonResponse
    {
        try {
            Cache::store(config('feedback-board.destinations.lock_store'))
                ->lock('feedback-board:github:'.$item->id, 10)
                ->block(8, function () use ($item, $key, $value) {
                    $fresh = FeedbackItem::find($item->id);

                    if ($fresh === null) {
                        return;
                    }

                    $refs = $fresh->external_refs ?? [];
                    $refs[$key] = $value;
                    $fresh->external_refs = $refs;
                    $fresh->save();
                });
        } catch (LockTimeoutException) {
            abort(503); // the GitHub driver can hold this lock for about 30 s; the sender re-signs and retries
        }

        return response()->json(['ok' => true]);
    }

    /**
     * The fields the signature covers, and nothing else: the JSON body, as the host's input
     * middleware (TrimStrings, ConvertEmptyStringsToNull) left it. Never all() or input():
     * VerifyIngestSignature signs the raw body only, and those merge in the unsigned query
     * string, and for a non-JSON Content-Type read the form fields instead of the body.
     *
     * @return array<array-key, mixed>
     */
    private static function signedPayload(Request $request): array
    {
        return $request->isJson() ? $request->json()->all() : [];
    }

    /** Validation answers JSON itself: through the host's exception renderer it could become a 302. */
    private static function invalid(ValidatorContract $validator): JsonResponse
    {
        return response()->json([
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors()->toArray(),
        ], 422);
    }
}
