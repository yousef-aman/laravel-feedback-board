<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Destinations\Drivers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use YousefAman\FeedbackBoard\Destinations\Contracts\Destination;
use YousefAman\FeedbackBoard\Destinations\Support\DestinationEvent;
use YousefAman\FeedbackBoard\Destinations\Support\TransientRetry;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class SlackDriver implements Destination
{
    public function send(FeedbackItem $item, object $event, string $deliveryId): void
    {
        $url = (string) config('feedback-board.destinations.slack.webhook_url');
        if ($url === '') {
            Log::warning('feedback-board: slack driver not configured; skipping delivery.');
            return;
        }

        $name = DestinationEvent::name($event);
        $fallback = "[{$name}] {$item->title}";

        $response = Http::asJson()->timeout(8)->connectTimeout(4)
            ->retry(2, 200, TransientRetry::when(), throw: false)
            ->post($url, [
                'text' => $fallback,
                'blocks' => [
                    ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => mb_strimwidth($item->title, 0, 150, '…')]],
                    ['type' => 'section', 'fields' => [
                        ['type' => 'mrkdwn', 'text' => "*Event:*\n{$name}"],
                        ['type' => 'mrkdwn', 'text' => '*Type:*\n'.($item->type?->value ?? '—')],
                        ['type' => 'mrkdwn', 'text' => '*Priority:*\n'.($item->priority?->value ?? '—')],
                        ['type' => 'mrkdwn', 'text' => '*Status:*\n'.($item->status?->value ?? '—')],
                        ['type' => 'mrkdwn', 'text' => '*Reporter:*\n'.($item->reporter_name ?? '—')],
                    ]],
                ],
            ]);

        // Slack Incoming Webhooks return HTTP 200 with the literal text "ok".
        if ($response->status() !== 200 || trim($response->body()) !== 'ok') {
            throw new RuntimeException('feedback-board: slack delivery rejected (status '.$response->status().').');
        }
    }
}
