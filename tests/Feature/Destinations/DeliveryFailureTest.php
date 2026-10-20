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

// A database queue on the test connection, and a worker that runs its jobs as queue:work would.
function deliveryQueueWorker(): \Illuminate\Queue\Worker
{
    Schema::create('jobs', function ($t) {
        $t->id(); $t->string('queue')->index(); $t->longText('payload'); $t->unsignedTinyInteger('attempts');
        $t->unsignedInteger('reserved_at')->nullable(); $t->unsignedInteger('available_at'); $t->unsignedInteger('created_at');
    });
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', ['driver' => 'database', 'connection' => 'testing', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90, 'after_commit' => false]);

    $worker = app('queue.worker');
    $worker->setCache(app('cache')->store('array'));

    return $worker;
}

it('retries a failing delivery after 30 s, 2 min and 5 min on a real queue, then fails', function () {
    $worker = deliveryQueueWorker();
    config()->set('feedback-board.destinations.webhook.url', 'https://hooks.example.test/in');
    config()->set('feedback-board.destinations.webhook.secret', 'shh');
    Http::fake(['*' => Http::response('down', 503)]);
    Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00'));
    $item = FeedbackItem::factory()->create();
    Bus::dispatch(new DeliverToDestination(new FeedbackItemCreated($item), 'webhook', 'dlv-backoff'));

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

it('reports a raw Guzzle transfer error without its URL', function () {
    config()->set('queue.default', 'sync');
    config()->set('feedback-board.destinations.enabled', ['webhook']);
    // Before Laravel 12.34 the HTTP client lets non-connect cURL errors out as raw Guzzle exceptions.
    app(\YousefAman\FeedbackBoard\Destinations\DestinationManager::class)->extend('webhook', fn () => new class implements \YousefAman\FeedbackBoard\Destinations\Contracts\Destination
    {
        public function send(FeedbackItem $item, object $event, string $deliveryId): void
        {
            throw new \GuzzleHttp\Exception\RequestException(
                'cURL error 56: Recv failure: Connection reset by peer for https://hooks.example.test/SECRETTOKEN',
                new \GuzzleHttp\Psr7\Request('POST', 'https://hooks.example.test/SECRETTOKEN'),
            );
        }
    });

    app(CreateFeedbackItem::class)->handle(['title' => 'Kept', 'type' => FeedbackType::Bug], null);

    $item = FeedbackItem::where('title', 'Kept')->first();
    expect($item->external_refs['webhook_last_error']['code'] ?? null)->toBe('connection_error');
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (DeliveryFailed $e) => $e->errorCode === 'connection_error'
        && $e->getPrevious() === null && ! str_contains((string) $e, 'SECRETTOKEN'));
});

it('fails at once, without retries, when the destination refuses for good', function (int $status, string $code) {
    $worker = deliveryQueueWorker();
    config()->set('feedback-board.destinations.webhook.url', 'https://hooks.example.test/in');
    config()->set('feedback-board.destinations.webhook.secret', 'shh');
    Http::fake(['*' => Http::response('refused', $status)]);
    $item = FeedbackItem::factory()->create();
    Bus::dispatch(new DeliverToDestination(new FeedbackItemCreated($item), 'webhook', 'dlv-refused'));
    $failed = 0;
    Queue::failing(function () use (&$failed) {
        $failed++;
    });

    $worker->runNextJob('database', 'default', new WorkerOptions());

    expect(DB::table('jobs')->count())->toBe(0)
        ->and($failed)->toBe(1)
        ->and($item->fresh()->external_refs['webhook_last_error']['code'] ?? null)->toBe($code);
    Exceptions::assertReportedCount(1);
})->with([
    'bad request' => [400, 'validation_422'],
    'revoked key' => [401, 'auth_401'],
    'no permission' => [403, 'auth_401'],
    'wrong address' => [404, 'not_found_404'],
    'gone' => [410, 'not_found_404'],
    'unreadable payload' => [422, 'validation_422'],
]);

it('fails a GitHub delivery at once when the token is refused', function () {
    $worker = deliveryQueueWorker();
    config()->set('feedback-board.destinations.github.token', 'ghp_test');
    config()->set('feedback-board.destinations.github.repo', 'acme/app');
    Http::fake(['*' => Http::response(['message' => 'Bad credentials'], 401)]);
    $item = FeedbackItem::factory()->create();
    Bus::dispatch(new DeliverToDestination(new FeedbackItemCreated($item), 'github', 'dlv-token'));

    $worker->runNextJob('database', 'default', new WorkerOptions());

    expect(DB::table('jobs')->count())->toBe(0)
        ->and($item->fresh()->external_refs['github_last_error']['code'] ?? null)->toBe('auth_401');
});

it('still retries a GitHub refusal that is a rate limit', function (int $status, array $headers, array $body) {
    $worker = deliveryQueueWorker();
    config()->set('feedback-board.destinations.github.token', 'ghp_test');
    config()->set('feedback-board.destinations.github.repo', 'acme/app');
    Http::fake(['*' => Http::response($body, $status, $headers)]);
    $item = FeedbackItem::factory()->create();
    Bus::dispatch(new DeliverToDestination(new FeedbackItemCreated($item), 'github', 'dlv-limit'));

    $worker->runNextJob('database', 'default', new WorkerOptions());

    expect(DB::table('jobs')->count())->toBe(1)
        ->and($item->fresh()->external_refs['github_last_error'] ?? null)->toBeNull();
})->with([
    'primary limit spent' => [403, ['x-ratelimit-remaining' => '0'], ['message' => 'API rate limit exceeded for user ID 1.']],
    'secondary limit, no headers' => [403, [], ['message' => 'You have exceeded a secondary rate limit. Please wait a few minutes before you try again.']],
    'created too quickly' => [422, [], ['message' => 'Validation Failed', 'errors' => [['resource' => 'Issue', 'code' => 'custom', 'field' => 'body', 'message' => 'was submitted too quickly']]]],
]);

it('still retries a webhook 403 that says when to try again', function () {
    $worker = deliveryQueueWorker();
    config()->set('feedback-board.destinations.webhook.url', 'https://hooks.example.test/in');
    config()->set('feedback-board.destinations.webhook.secret', 'shh');
    Http::fake(['*' => Http::response('slow down', 403, ['Retry-After' => '60'])]);
    $item = FeedbackItem::factory()->create();
    Bus::dispatch(new DeliverToDestination(new FeedbackItemCreated($item), 'webhook', 'dlv-wait'));

    $worker->runNextJob('database', 'default', new WorkerOptions());

    expect(DB::table('jobs')->count())->toBe(1);
});

it('records a refused delivery and reports it once on the sync queue', function () {
    config()->set('queue.default', 'sync');
    config()->set('feedback-board.destinations.enabled', ['webhook']);
    config()->set('feedback-board.destinations.webhook.url', 'https://hooks.example.test/in');
    config()->set('feedback-board.destinations.webhook.secret', 'shh');
    Http::fake(['*' => Http::response('refused', 401)]);

    app(CreateFeedbackItem::class)->handle(['title' => 'Kept', 'type' => FeedbackType::Bug], null);

    expect(FeedbackItem::where('title', 'Kept')->first()->external_refs['webhook_last_error']['code'] ?? null)->toBe('auth_401');
    Exceptions::assertReportedCount(1);
});
