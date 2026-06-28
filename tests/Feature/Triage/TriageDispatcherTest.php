<?php

use Illuminate\Support\Facades\Bus;
use YousefAman\FeedbackBoard\Jobs\TriageFeedbackItem;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Triage\Support\TriageDispatcher;

beforeEach(function () {
    config()->set('feedback-board.ai.lock_store', 'array');
});

it('marks pending and enqueues the job', function () {
    Bus::fake();
    $item = FeedbackItem::factory()->create();

    TriageDispatcher::dispatch($item);

    expect($item->refresh()->ai_state['triage']['status'])->toBe('pending');
    Bus::assertDispatched(TriageFeedbackItem::class, fn ($job) => $job->itemId === $item->id);
});

it('debounces a second dispatch while pending', function () {
    Bus::fake();
    $item = FeedbackItem::factory()->create();

    TriageDispatcher::dispatch($item);
    TriageDispatcher::dispatch($item->refresh());

    Bus::assertDispatchedTimes(TriageFeedbackItem::class, 1);
});

it('re-dispatches after a terminal state', function () {
    Bus::fake();
    $item = FeedbackItem::factory()->create();
    $item->ai_state = ['triage' => ['status' => 'completed', 'summary' => 'old']];
    $item->save();

    TriageDispatcher::dispatch($item->refresh());

    expect($item->refresh()->ai_state['triage']['status'])->toBe('pending');
    Bus::assertDispatchedTimes(TriageFeedbackItem::class, 1);
});
