<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use YousefAman\FeedbackBoard\Actions\ReplyToFeedback;
use YousefAman\FeedbackBoard\Actions\TransitionStatus;
use YousefAman\FeedbackBoard\Enums\AuthorRole;
use YousefAman\FeedbackBoard\Enums\FeedbackStatus;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class IngestController
{
    public function __invoke(Request $request, TransitionStatus $transition, ReplyToFeedback $reply): JsonResponse
    {
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
}
