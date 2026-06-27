<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Destinations\Drivers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use YousefAman\FeedbackBoard\Destinations\Contracts\Destination;
use YousefAman\FeedbackBoard\Destinations\Support\DestinationEvent;
use YousefAman\FeedbackBoard\Destinations\Support\SignsPayloads;
use YousefAman\FeedbackBoard\Destinations\Support\TransientRetry;
use YousefAman\FeedbackBoard\Destinations\Support\WebhookPayload;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class WebhookDriver implements Destination
{
    use SignsPayloads;

    public function send(FeedbackItem $item, object $event, string $deliveryId): void
    {
        $url = (string) config('feedback-board.destinations.webhook.url');
        $secret = (string) config('feedback-board.destinations.webhook.secret');
        if ($url === '' || $secret === '') {
            Log::warning('feedback-board: webhook driver not configured; skipping delivery.');
            return;
        }

        // json_encode ONCE; sign those exact bytes; send the same bytes via withBody.
        $rawBody = json_encode(WebhookPayload::build($item, $event, $deliveryId), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ts = (string) now()->timestamp;
        $signature = $this->sign($ts, $rawBody, $secret);

        // retry() retries on connection errors AND any non-2xx; the TransientRetry
        // predicate narrows that to connection/429/5xx. With throw:false the final
        // attempt returns the response instead of throwing, so we ->throw() explicitly.
        Http::timeout(8)->connectTimeout(4)
            ->retry(2, 200, TransientRetry::when(), throw: false)
            ->withHeaders([
                'X-Feedback-Timestamp' => $ts,
                'X-Feedback-Signature' => $signature,
                'X-Feedback-Event' => DestinationEvent::name($event),
                'X-Feedback-Delivery' => $deliveryId,
            ])
            ->withBody($rawBody, 'application/json')
            ->post($url)
            ->throw();
    }
}
