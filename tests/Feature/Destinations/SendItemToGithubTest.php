<?php

use Illuminate\Support\Facades\{Bus, Gate};
use Symfony\Component\HttpKernel\Exception\HttpException;
use YousefAman\FeedbackBoard\Actions\SendItemToGithub;
use YousefAman\FeedbackBoard\Events\FeedbackItemCreated;
use YousefAman\FeedbackBoard\Jobs\DeliverToDestination;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    config()->set('feedback-board.destinations.github.token', 'ghp');
    config()->set('feedback-board.destinations.github.repo', 'a/b');
});

it('dispatches a github created job for a developer', function () {
    Bus::fake();
    $dev = TestUser::create(['name' => 'Dev']);
    Gate::define('feedback-board.manage', fn ($u) => true);
    $item = FeedbackItem::factory()->create();

    app(SendItemToGithub::class)->handle($item, $dev);

    Bus::assertDispatched(DeliverToDestination::class, fn ($j) => $j->driver === 'github'
        && $j->event instanceof FeedbackItemCreated && $j->deliveryId !== '');
});

it('aborts 403 for a non-developer (hard authorization, not UI hiding)', function () {
    Bus::fake();
    $user = TestUser::create(['name' => 'Client']);
    Gate::define('feedback-board.manage', fn ($u) => false);
    $item = FeedbackItem::factory()->create();

    try {
        app(SendItemToGithub::class)->handle($item, $user);
        $this->fail('expected an HttpException');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    }
    Bus::assertNotDispatched(DeliverToDestination::class);
});

it('aborts 422 when github is not configured', function () {
    config()->set('feedback-board.destinations.github.token', null);
    Bus::fake();
    $dev = TestUser::create(['name' => 'Dev']);
    Gate::define('feedback-board.manage', fn ($u) => true);
    $item = FeedbackItem::factory()->create();

    try {
        app(SendItemToGithub::class)->handle($item, $dev);
        $this->fail('expected an HttpException');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(422);
    }
    Bus::assertNotDispatched(DeliverToDestination::class);
});
