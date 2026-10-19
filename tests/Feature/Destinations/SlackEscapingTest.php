<?php

use Illuminate\Support\Facades\Http;
use YousefAman\FeedbackBoard\Destinations\Drivers\SlackDriver;
use YousefAman\FeedbackBoard\Events\FeedbackItemCreated;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

beforeEach(function () {
    config()->set('feedback-board.destinations.slack.webhook_url', 'https://hooks.slack.test/services/T/B/X');
    Http::fake(['hooks.slack.test/*' => Http::response('ok', 200)]);
});

it('escapes reporter text in Slack mrkdwn and keeps the header as typed', function () {
    $item = FeedbackItem::factory()->create(['title' => '<!channel> R&D down', 'reporter_name' => '<https://evil.example|Open> & co']);

    (new SlackDriver())->send($item, new FeedbackItemCreated($item), 'dlv-escape');

    Http::assertSent(function ($request) {
        $fields = collect(collect($request['blocks'])->firstWhere('fields')['fields'])->pluck('text');

        return $request['text'] === 'New report: &lt;!channel&gt; R&amp;D down'
            && $request['blocks'][0]['text']['text'] === '<!channel> R&D down'
            && $fields->last() === "*Reporter:*\n&lt;https://evil.example|Open&gt; &amp; co";
    });
});

it('breaks the line after every field label', function () {
    $item = FeedbackItem::factory()->create();

    (new SlackDriver())->send($item, new FeedbackItemCreated($item), 'dlv-newline');

    Http::assertSent(fn ($request) => collect(collect($request['blocks'])->firstWhere('fields')['fields'])
        ->every(fn (array $field) => str_contains($field['text'], ":*\n") && ! str_contains($field['text'], '\n')));
});
