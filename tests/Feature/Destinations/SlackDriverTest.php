<?php

use Illuminate\Support\Facades\Http;
use YousefAman\FeedbackBoard\Destinations\Drivers\SlackDriver;
use YousefAman\FeedbackBoard\Events\FeedbackItemCreated;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

beforeEach(function () {
    config()->set('feedback-board.destinations.slack.webhook_url', 'https://hooks.slack.test/services/T/B/X');
});

it('posts Block Kit JSON and succeeds only on 200 + body "ok"', function () {
    Http::fake(['hooks.slack.test/*' => Http::response('ok', 200)]);
    $item = FeedbackItem::factory()->create(['title' => 'Crash on save']);

    (new SlackDriver())->send($item, new FeedbackItemCreated($item), 'dlv-1');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://hooks.slack.test/services/T/B/X'
            && is_array($request['blocks'])
            && str_contains(json_encode($request->data()), 'Crash on save');
    });
});

it('throws when Slack does not return the literal ok body', function () {
    Http::fake(['hooks.slack.test/*' => Http::response('invalid_payload', 200)]);
    $item = FeedbackItem::factory()->create();

    expect(fn () => (new SlackDriver())->send($item, new FeedbackItemCreated($item), 'dlv-2'))
        ->toThrow(RuntimeException::class);
});

it('is a no-op when the webhook url is missing', function () {
    config()->set('feedback-board.destinations.slack.webhook_url', null);
    Http::fake();
    $item = FeedbackItem::factory()->create();

    (new SlackDriver())->send($item, new FeedbackItemCreated($item), 'dlv-3');

    Http::assertNothingSent();
});
