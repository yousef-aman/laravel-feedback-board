<?php

use Illuminate\Support\Facades\Cache;
use YousefAman\FeedbackBoard\Destinations\Contracts\Destination;
use YousefAman\FeedbackBoard\Destinations\DestinationManager;
use YousefAman\FeedbackBoard\Destinations\Exceptions\RetryAfter;
use YousefAman\FeedbackBoard\Destinations\Support\DeliveryError;
use YousefAman\FeedbackBoard\Events\FeedbackItemCreated;
use YousefAman\FeedbackBoard\Jobs\DeliverToDestination;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

beforeEach(function () {
    config()->set('feedback-board.destinations.lock_store', 'array');
});

// A driver test double registered onto the manager via extend().
function fakeDriver(callable $send): Destination {
    return new class($send) implements Destination {
        public function __construct(private $send) {}
        public function send(FeedbackItem $item, object $event, string $deliveryId): void { ($this->send)($item, $event, $deliveryId); }
    };
}

it('calls the resolved driver with the item, event and deliveryId', function () {
    $item = FeedbackItem::factory()->create();
    $seen = null;
    app(DestinationManager::class)->extend('spy', function () use (&$seen) { return fakeDriver(function ($i, $e, $d) use (&$seen) { $seen = [$i->id, $d]; }); });

    (new DeliverToDestination(new FeedbackItemCreated($item), 'spy', 'dlv-1'))->handle(app(DestinationManager::class));

    expect($seen)->toBe([$item->id, 'dlv-1']);
});

it('no-ops without calling the driver when the item was deleted', function () {
    $item = FeedbackItem::factory()->create();
    $event = new FeedbackItemCreated($item);
    $called = false;
    app(DestinationManager::class)->extend('spy', fn () => fakeDriver(function () use (&$called) { $called = true; }));
    $item->delete();

    (new DeliverToDestination($event, 'spy', 'dlv-2'))->handle(app(DestinationManager::class));

    expect($called)->toBeFalse();
});

it('translates a RetryAfter from the driver into release without failing', function () {
    $item = FeedbackItem::factory()->create();
    app(DestinationManager::class)->extend('rl', fn () => fakeDriver(function () { throw new RetryAfter(42); }));

    $job = Mockery::mock(DeliverToDestination::class, [new FeedbackItemCreated($item), 'rl', 'dlv-3'])->makePartial();
    $job->shouldReceive('release')->once()->with(42);

    $job->handle(app(DestinationManager::class));
});

it('failed() writes a sanitized error code under external_refs and never the raw message', function () {
    // Default config has no `notifications.developers` (env unset) → email-only/empty,
    // so notifyDevelopers() no-ops here: this test does NOT create a notifications table,
    // proving the email-only path never attempts a database notification.
    $item = FeedbackItem::factory()->create();
    $job = new DeliverToDestination(new FeedbackItemCreated($item), 'slack', 'dlv-4');

    $job->failed(new \Illuminate\Http\Client\ConnectionException('cURL error 6: Could not resolve host: hooks.slack.com/services/SECRET'));

    $err = $item->refresh()->external_refs['slack_last_error'];
    expect($err['code'])->toBe('connection_error')
        ->and($err['event'])->toBe('feedback.created')
        ->and(json_encode($err))->not->toContain('SECRET')
        ->and(json_encode($err))->not->toContain('hooks.slack.com');
});

it('raises a best-effort Filament database notification to model developers', function () {
    \Illuminate\Support\Facades\Schema::create('notifications', function ($t) {
        $t->uuid('id')->primary();
        $t->string('type');
        $t->morphs('notifiable');
        $t->text('data');
        $t->timestamp('read_at')->nullable();
        $t->timestamps();
    });
    $dev = \YousefAman\FeedbackBoard\Tests\TestUser::create(['name' => 'Dev']);
    config()->set('feedback-board.notifications.developers', fn () => collect([$dev]));
    $item = FeedbackItem::factory()->create();

    (new DeliverToDestination(new FeedbackItemCreated($item), 'slack', 'dlv-n'))
        ->failed(new \Illuminate\Http\Client\ConnectionException('cURL error to hooks.slack.com/services/SECRET'));

    $first = $dev->fresh()->notifications()->first();
    expect($dev->notifications()->count())->toBe(1)
        ->and($first->data['format'])->toBe('filament')     // Filament render contract
        ->and(json_encode($first->data))->not->toContain('SECRET')
        ->and(json_encode($first->data))->not->toContain('hooks.slack.com');
});

it('retries are time-bounded, not count-bounded', function () {
    $item = FeedbackItem::factory()->create();
    $job = new DeliverToDestination(new FeedbackItemCreated($item), 'slack', 'dlv-5');
    expect($job->maxExceptions)->toBe(4)
        ->and($job->retryUntil())->toBeInstanceOf(DateTimeInterface::class);
});

it('DeliveryError maps throwables to a fixed vocabulary', function () {
    expect(DeliveryError::code(new \Illuminate\Http\Client\ConnectionException('x')))->toBe('connection_error')
        ->and(DeliveryError::code(new RuntimeException('boom')))->toBe('unknown');
});

it("clears this destination's recorded failure once a delivery succeeds, and leaves the others", function () {
    $failure = ['code' => 'connection_error', 'event' => 'feedback.created', 'at' => '2026-10-17T10:00:00+00:00'];
    $item = FeedbackItem::factory()->create(['external_refs' => [
        'spy_last_error' => $failure,
        'slack_last_error' => $failure,
        'github' => ['issue_number' => 7],
    ]]);
    app(DestinationManager::class)->extend('spy', fn () => fakeDriver(fn () => null));

    (new DeliverToDestination(new FeedbackItemCreated($item), 'spy', 'dlv-ok'))->handle(app(DestinationManager::class));

    expect($item->refresh()->external_refs)->toBe(['slack_last_error' => $failure, 'github' => ['issue_number' => 7]]);
});

it('keeps the recorded failure when the delivery fails again', function () {
    $failure = ['code' => 'connection_error', 'event' => 'feedback.created', 'at' => '2026-10-17T10:00:00+00:00'];
    $item = FeedbackItem::factory()->create(['external_refs' => ['spy_last_error' => $failure]]);
    app(DestinationManager::class)->extend('spy', fn () => fakeDriver(fn () => throw new RuntimeException('down')));

    expect(fn () => (new DeliverToDestination(new FeedbackItemCreated($item), 'spy', 'dlv-x'))->handle(app(DestinationManager::class)))
        ->toThrow(RuntimeException::class);
    expect($item->refresh()->external_refs)->toBe(['spy_last_error' => $failure]);
});
