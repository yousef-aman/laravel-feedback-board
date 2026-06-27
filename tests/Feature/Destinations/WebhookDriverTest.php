<?php

use Illuminate\Support\Facades\Http;
use YousefAman\FeedbackBoard\Destinations\Drivers\WebhookDriver;
use YousefAman\FeedbackBoard\Events\FeedbackItemCreated;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

beforeEach(function () {
    config()->set('feedback-board.destinations.webhook.url', 'https://example.test/hook');
    config()->set('feedback-board.destinations.webhook.secret', 'whsecret');
});

it('signs the exact bytes sent and sets the delivery + event headers', function () {
    Http::fake(['example.test/*' => Http::response('', 200)]);
    $item = FeedbackItem::factory()->create(['ai_state' => ['x' => 'leak']]);

    (new WebhookDriver())->send($item, new FeedbackItemCreated($item), 'dlv-1');

    Http::assertSent(function ($request) {
        $raw = $request->body();
        $ts = $request->header('X-Feedback-Timestamp')[0];
        $expected = hash_hmac('sha256', $ts.'.'.$raw, 'whsecret');
        return $request->url() === 'https://example.test/hook'
            && $request->header('X-Feedback-Signature')[0] === $expected
            && $request->header('X-Feedback-Event')[0] === 'feedback.created'
            && $request->header('X-Feedback-Delivery')[0] === 'dlv-1'
            && ! str_contains($raw, 'leak');                  // curated payload, no ai_state
    });
});

it('is a no-op when the url is missing', function () {
    config()->set('feedback-board.destinations.webhook.url', null);
    Http::fake();
    $item = FeedbackItem::factory()->create();

    (new WebhookDriver())->send($item, new FeedbackItemCreated($item), 'dlv-2');

    Http::assertNothingSent();
});
