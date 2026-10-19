<?php

use Illuminate\Support\Facades\Http;
use YousefAman\FeedbackBoard\Destinations\Drivers\SlackDriver;
use YousefAman\FeedbackBoard\Destinations\Support\DeliveryError;
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

it('gives the reason when Slack refuses the webhook URL', function (int $status, string $body, string $code) {
    Http::fake(['hooks.slack.test/*' => Http::response($body, $status)]);
    $item = FeedbackItem::factory()->create();

    try {
        (new SlackDriver())->send($item, new FeedbackItemCreated($item), 'dlv-refused');
        $this->fail('Slack refused the message, so the delivery must fail.');
    } catch (Throwable $e) {
        expect(DeliveryError::code($e))->toBe($code)
            ->and($e->getMessage().$e->getTraceAsString())->not->toContain('hooks.slack.test');
    }
})->with([
    'a deleted webhook' => [404, 'no_service', 'not_found_404'],
    'a removed workspace' => [404, 'no_team', 'not_found_404'],
    'an archived channel' => [410, 'channel_is_archived', 'not_found_404'],
    'a revoked token' => [403, 'invalid_token', 'auth_401'],
    'a message Slack cannot read' => [400, 'invalid_blocks', 'validation_422'],
    'an outage' => [503, 'service_unavailable', 'http_5xx'],
]);

it('is a no-op when the webhook url is missing', function () {
    config()->set('feedback-board.destinations.slack.webhook_url', null);
    Http::fake();
    $item = FeedbackItem::factory()->create();

    (new SlackDriver())->send($item, new FeedbackItemCreated($item), 'dlv-3');

    Http::assertNothingSent();
});
