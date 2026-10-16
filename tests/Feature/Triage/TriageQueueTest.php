<?php

use Illuminate\Support\Facades\Queue;
use YousefAman\FeedbackBoard\Jobs\TriageFeedbackItem;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Triage\Support\TriageDispatcher;

beforeEach(function () {
    config()->set('feedback-board.ai.lock_store', 'array');
});

it('puts triage jobs on ai.queue from the automatic and the manual path', function () {
    config()->set('feedback-board.ai.queue', 'ai');
    Queue::fake();
    $automatic = FeedbackItem::factory()->create();
    $manual = FeedbackItem::factory()->create();

    TriageDispatcher::dispatch($automatic);
    TriageDispatcher::dispatch($manual, locked: true);

    Queue::assertPushedOn('ai', TriageFeedbackItem::class, fn (TriageFeedbackItem $job) => $job->itemId === $automatic->id);
    Queue::assertPushedOn('ai', TriageFeedbackItem::class, fn (TriageFeedbackItem $job) => $job->itemId === $manual->id);
});

it('keeps triage on the default queue when ai.queue is empty', function (?string $queue) {
    config()->set('feedback-board.ai.queue', $queue);
    Queue::fake();

    TriageDispatcher::dispatch(FeedbackItem::factory()->create());

    Queue::assertPushed(TriageFeedbackItem::class, fn (TriageFeedbackItem $job) => $job->queue === null);
})->with(['null' => [null], 'empty string' => ['']]);
