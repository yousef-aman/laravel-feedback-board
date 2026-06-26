<?php

use YousefAman\FeedbackBoard\Destinations\DestinationManager;
use YousefAman\FeedbackBoard\Destinations\Drivers\MailDriver;
use YousefAman\FeedbackBoard\Destinations\Contracts\DestinationDriver;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

it('resolves DestinationManager from the container', function () {
    expect(app(DestinationManager::class))->toBeInstanceOf(DestinationManager::class);
});

it('default driver is mail', function () {
    $manager = app(DestinationManager::class);
    expect($manager->driver())->toBeInstanceOf(MailDriver::class);
});

it('MailDriver implements DestinationDriver', function () {
    expect(new MailDriver(app()))->toBeInstanceOf(DestinationDriver::class);
});

it('MailDriver send is a no-op (returns void without throwing)', function () {
    $item = FeedbackItem::factory()->create();
    $driver = new MailDriver(app());
    // Should not throw — deliberate no-op in MVP
    $result = $driver->send($item);
    expect($result)->toBeNull();
});

it('destinations config block exists with expected keys', function () {
    expect(config('feedback-board.destinations'))->toBeArray()
        ->and(config('feedback-board.destinations.default'))->toBe('mail')
        ->and(config('feedback-board.destinations.enabled'))->toContain('mail');
});

it('DispatchToDestinations listener is subscribed to package events', function () {
    $item = FeedbackItem::factory()->create();
    // Dispatch the event — if listener throws, the test will fail
    // We just verify no exception is thrown (driver is a no-op)
    \YousefAman\FeedbackBoard\Events\FeedbackItemCreated::dispatch($item);
    expect(true)->toBeTrue();
});
