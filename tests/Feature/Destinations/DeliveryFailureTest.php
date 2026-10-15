<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Bus, DB, Exceptions, Http, Queue, Schema};
use Illuminate\Support\Sleep;
use YousefAman\FeedbackBoard\Actions\CreateFeedbackItem;
use YousefAman\FeedbackBoard\Destinations\Exceptions\DeliveryFailed;
use YousefAman\FeedbackBoard\Enums\FeedbackType;
use YousefAman\FeedbackBoard\Events\FeedbackItemCreated;
use YousefAman\FeedbackBoard\Jobs\DeliverToDestination;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

beforeEach(function () {
    Exceptions::fake();
    Sleep::fake();
    config()->set('feedback-board.destinations.lock_store', 'array');
});

it('reports a Slack connection failure without the webhook URL', function () {
    config()->set('queue.default', 'sync');
    config()->set('feedback-board.destinations.enabled', ['slack']);
    config()->set('feedback-board.destinations.slack.webhook_url', 'https://hooks.slack.test/services/T000/B000/SECRETTOKEN');
    Http::fake(fn ($request) => throw new ConnectionException('cURL error 6: Could not resolve host for '.$request->url()));

    app(CreateFeedbackItem::class)->handle(['title' => 'Kept', 'type' => FeedbackType::Bug], null);

    $item = FeedbackItem::where('title', 'Kept')->first();
    expect($item->external_refs['slack_last_error']['code'] ?? null)->toBe('connection_error');
    // ExceptionHandlerFake::reported() only exists from Laravel 12.18: count and inspect instead.
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (DeliveryFailed $e) => $e->errorCode === 'connection_error'
        && $e->getPrevious() === null && ! str_contains((string) $e, 'SECRETTOKEN'));
});

it('reports an unparsable webhook URL without the URL', function () {
    config()->set('queue.default', 'sync');
    config()->set('feedback-board.destinations.enabled', ['slack']);
    // A port typo: Guzzle cannot parse the URL and throws before connecting, naming the whole URL.
    config()->set('feedback-board.destinations.slack.webhook_url', 'https://hooks.slack.test:99999/services/T000/B000/SECRETTOKEN');

    app(CreateFeedbackItem::class)->handle(['title' => 'Kept', 'type' => FeedbackType::Bug], null);

    $item = FeedbackItem::where('title', 'Kept')->first();
    expect($item->external_refs['slack_last_error']['code'] ?? null)->toBe('invalid_url');
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (DeliveryFailed $e) => $e->errorCode === 'invalid_url'
        && $e->getPrevious() === null && ! str_contains((string) $e, 'SECRETTOKEN'));
});

it('retries a failing delivery after 30 s, 2 min and 5 min on a real queue, then fails', function () {
    Schema::create('jobs', function ($t) {
        $t->id(); $t->string('queue')->index(); $t->longText('payload'); $t->unsignedTinyInteger('attempts');
        $t->unsignedInteger('reserved_at')->nullable(); $t->unsignedInteger('available_at'); $t->unsignedInteger('created_at');
    });
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', ['driver' => 'database', 'connection' => 'testing', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90, 'after_commit' => false]);
    config()->set('feedback-board.destinations.webhook.url', 'https://hooks.example.test/in');
    config()->set('feedback-board.destinations.webhook.secret', 'shh');
    Http::fake(['*' => Http::response('down', 503)]);
    Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00'));
    $item = FeedbackItem::factory()->create();
    Bus::dispatch(new DeliverToDestination(new FeedbackItemCreated($item), 'webhook', 'dlv-backoff'));

    $worker = app('queue.worker');
    $worker->setCache(app('cache')->store('array'));
    $delays = [];
    // Only queue:work writes failed_jobs; runNextJob() fires JobFailed, which Queue::failing() sees.
    $failed = 0;
    Queue::failing(function () use (&$failed) {
        $failed++;
    });

    for ($attempt = 1; $attempt <= 4; $attempt++) {
        $worker->runNextJob('database', 'default', new WorkerOptions());
        $available = DB::table('jobs')->value('available_at');

        if ($available !== null) {
            $delays[] = (int) $available - Carbon::now()->getTimestamp();
            Carbon::setTestNow(Carbon::createFromTimestamp((int) $available));
        }
    }

    expect($delays)->toBe([30, 120, 300])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($failed)->toBe(1)
        ->and($item->fresh()->external_refs['webhook_last_error']['code'] ?? null)->toBe('http_5xx');
});
