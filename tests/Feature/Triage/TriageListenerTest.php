<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use YousefAman\FeedbackBoard\Actions\CreateFeedbackItem;
use YousefAman\FeedbackBoard\Jobs\TriageFeedbackItem;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Triage\Contracts\TriageDriver;
use YousefAman\FeedbackBoard\Triage\Support\TriageResult;
use YousefAman\FeedbackBoard\Triage\TriageManager;

it('dispatches triage on creation when configured', function () {
    config()->set('feedback-board.ai.enabled', true);
    config()->set('feedback-board.ai.default', 'fake');
    Bus::fake();

    (new CreateFeedbackItem())->handle(['title' => 'Broken'], null);

    Bus::assertDispatched(TriageFeedbackItem::class);
});

it('is inert when disabled', function () {
    config()->set('feedback-board.ai.enabled', false);
    Bus::fake();

    (new CreateFeedbackItem())->handle(['title' => 'Broken'], null);

    Bus::assertNotDispatched(TriageFeedbackItem::class);
});

it('is inert when anthropic is enabled without a key', function () {
    config()->set('feedback-board.ai.enabled', true);
    config()->set('feedback-board.ai.default', 'anthropic');
    config()->set('feedback-board.ai.anthropic.api_key', null);
    Bus::fake();

    (new CreateFeedbackItem())->handle(['title' => 'Broken'], null);

    Bus::assertNotDispatched(TriageFeedbackItem::class);
});

it('marks the new item pending during creation', function () {
    config()->set('feedback-board.ai.enabled', true);
    config()->set('feedback-board.ai.default', 'fake');
    Bus::fake(); // intercept the after-commit job so the pending mark is observable

    $item = (new CreateFeedbackItem())->handle(['title' => 'Broken'], null);

    // The synchronous in-transaction dispatcher wrote pending (proves the listener path ran).
    expect($item->refresh()->ai_state['triage']['status'])->toBe('pending');
});

it('runs end-to-end to a terminal completed state on the sync queue (after commit)', function () {
    config()->set('queue.default', 'sync');
    config()->set('feedback-board.ai.enabled', true);
    config()->set('feedback-board.ai.default', 'fake');
    // NO Bus::fake — the after-commit job must actually run once the transaction commits.

    $item = (new CreateFeedbackItem())->handle(['title' => 'Login broken'], null);

    expect($item->refresh()->ai_state['triage']['status'])->toBe('completed')
        ->and($item->ai_state['triage']['summary'])->toBe('Login broken'); // FakeTriageDriver summary
});

it('does not triage when the surrounding transaction rolls back (after-commit guarantee)', function () {
    config()->set('queue.default', 'sync');
    config()->set('feedback-board.ai.enabled', true);
    config()->set('feedback-board.ai.default', 'spy');

    $spy = new class implements TriageDriver {
        public bool $called = false;
        public function triage(string $title, ?string $description): TriageResult {
            $this->called = true;
            return new TriageResult(null, null, 's', 'spy');
        }
    };
    app(TriageManager::class)->extend('spy', fn () => $spy); // same instance every resolution

    try {
        DB::transaction(function () {
            (new CreateFeedbackItem())->handle(['title' => 'Rolled back'], null);
            throw new RuntimeException('force rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(FeedbackItem::count())->toBe(0)  // item rolled back
        ->and($spy->called)->toBeFalse();   // after-commit job never released → driver never ran
});
