<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Destinations\Support;

use YousefAman\FeedbackBoard\Events\FeedbackReplied;
use YousefAman\FeedbackBoard\Events\FeedbackStatusChanged;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

final class WebhookPayload
{
    /**
     * Build the curated, whitelisted outbound payload. NEVER serialize the raw
     * model: ai_state, external_refs, meta, reporter_email, reporter_id and
     * assignee_* must never appear here.
     */
    public static function build(FeedbackItem $item, object $event, string $deliveryId): array
    {
        $payload = [
            'event' => DestinationEvent::name($event),
            'delivery' => $deliveryId,
            'item' => [
                'id' => $item->id,
                'type' => $item->type?->value,
                'status' => $item->status?->value,
                'priority' => $item->priority?->value,
                'title' => $item->title,
                'description' => $item->description,
                'project' => $item->project,
                'reporter_name' => $item->reporter_name,
                'created_at' => $item->created_at?->toIso8601String(),
            ],
        ];

        if ($event instanceof FeedbackStatusChanged) {
            $payload['change'] = ['from' => $event->from->value, 'to' => $event->to->value];
        } elseif ($event instanceof FeedbackReplied) {
            $payload['change'] = [
                'author_role' => $event->comment->author_role?->value,
                'body' => $event->comment->body,
            ];
        }
        // created: no 'change' key (key omitted entirely)

        return $payload;
    }
}
