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

it('keeps a completed suggestion aside while a re-run is pending', function () {
    Bus::fake();
    $item = FeedbackItem::factory()->create();
    $item->ai_state = ['triage' => ['status' => 'completed', 'summary' => 'old', 'suggested_type' => 'bug', 'error_code' => null]];
    $item->save();

    TriageDispatcher::dispatch($item->refresh(), locked: true);

    $triage = $item->refresh()->ai_state['triage'];
    expect($triage['status'])->toBe('pending')
        ->and($triage['previous']['summary'])->toBe('old')
        ->and($triage['previous']['suggested_type'])->toBe('bug');
});

it('keeps nothing aside after a failed run', function () {
    Bus::fake();
    $item = FeedbackItem::factory()->create();
    $item->ai_state = ['triage' => ['status' => 'failed', 'summary' => null, 'error_code' => 'auth_401']];
    $item->save();

    TriageDispatcher::dispatch($item->refresh(), locked: true);

    expect($item->refresh()->ai_state['triage'])->not->toHaveKey('previous');
});
