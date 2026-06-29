<?php

use Illuminate\Support\Facades\Http;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Runner\Exceptions\RunnerException;
use YousefAman\FeedbackBoard\Runner\RunnerDispatcher;

beforeEach(function () {
    config()->set('feedback-board.runner.endpoint', 'https://runner.test/task');
    config()->set('feedback-board.runner.secret', 'rsecret');
    config()->set('feedback-board.runner.callback_url', 'https://app.test/feedback-board/ingest');
});

it('signs and POSTs the envelope with the runner headers', function () {
    Http::fake(['https://runner.test/*' => Http::response(['ok' => true], 200)]);
    $item = FeedbackItem::factory()->create(['title' => 'T', 'description' => 'D']);

    (new RunnerDispatcher())->send($item, 'task-9');

    Http::assertSent(function ($request) {
        $ts = $request->header('X-Feedback-Timestamp')[0];
        $expected = hash_hmac('sha256', $ts.'.'.$request->body(), 'rsecret');
        return $request->url() === 'https://runner.test/task'
            && $request->header('X-Feedback-Signature')[0] === $expected
            && $request->header('X-Feedback-Event')[0] === 'runner.task'
            && $request->header('X-Feedback-Delivery')[0] === 'task-9'
            && str_contains($request->body(), '"task_id":"task-9"');
    });
});

it('throws a sanitized RunnerException on a permanent 4xx', function () {
    Http::fake(['https://runner.test/*' => Http::response('nope', 404)]);
    $item = FeedbackItem::factory()->create();

    expect(fn () => (new RunnerDispatcher())->send($item, 't'))
        ->toThrow(RunnerException::class, 'not_found_404');
});

it('throws not_configured when endpoint/secret are missing', function () {
    config()->set('feedback-board.runner.endpoint', '');
    $item = FeedbackItem::factory()->create();

    expect(fn () => (new RunnerDispatcher())->send($item, 't'))
        ->toThrow(RunnerException::class, 'not_configured');
});
