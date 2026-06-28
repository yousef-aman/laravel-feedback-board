<?php

use Illuminate\Contracts\Cache\LockTimeoutException;
use YousefAman\FeedbackBoard\Enums\FeedbackPriority;
use YousefAman\FeedbackBoard\Enums\FeedbackType;
use YousefAman\FeedbackBoard\Jobs\TriageFeedbackItem;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Triage\Contracts\TriageDriver;
use YousefAman\FeedbackBoard\Triage\Exceptions\TriageException;
use YousefAman\FeedbackBoard\Triage\Support\TriageResult;
use YousefAman\FeedbackBoard\Triage\TriageManager;

beforeEach(function () {
    config()->set('feedback-board.ai.lock_store', 'array');
    config()->set('feedback-board.ai.default', 'spy');
});

function spyDriver(callable $triage): TriageDriver {
    return new class($triage) implements TriageDriver {
        public function __construct(private $triage) {}
        public function triage(string $title, ?string $description): TriageResult { return ($this->triage)($title, $description); }
    };
}

it('writes a completed triage block via the resolved driver', function () {
    app(TriageManager::class)->extend('spy', fn () => spyDriver(
        fn ($t, $d) => new TriageResult(FeedbackType::Feature, FeedbackPriority::High, 'A summary', 'spy-model')
    ));
    $item = FeedbackItem::factory()->create();

    (new TriageFeedbackItem($item->id))->handle(app(TriageManager::class));

    $triage = $item->refresh()->ai_state['triage'];
    expect($triage['status'])->toBe('completed')
        ->and($triage['suggested_type'])->toBe('feature')
        ->and($triage['suggested_priority'])->toBe('high')
        ->and($triage['summary'])->toBe('A summary')
        ->and($triage['model'])->toBe('spy-model')
        ->and($triage['error_code'])->toBeNull();
});

it('is a no-op for a deleted item', function () {
    $spy = new class implements TriageDriver {
        public bool $called = false;
        public function triage(string $title, ?string $description): TriageResult
        {
            $this->called = true;
            return new TriageResult(null, null, 'should not reach here', 'spy');
        }
    };
    app(TriageManager::class)->extend('spy', fn () => $spy);

    (new TriageFeedbackItem(999999))->handle(app(TriageManager::class));

    expect($spy->called)->toBeFalse(); // the driver was never invoked
});

it('writes a failed terminal state with a sanitized code', function () {
    $item = FeedbackItem::factory()->create(['external_refs' => null]);

    (new TriageFeedbackItem($item->id))->failed(new TriageException('http_5xx'));

    $triage = $item->refresh()->ai_state['triage'];
    expect($triage['status'])->toBe('failed')
        ->and($triage['error_code'])->toBe('http_5xx')
        ->and($triage['summary'])->toBeNull()
        ->and($triage['suggested_type'])->toBeNull();
});

it('writing ai_state leaves external_refs intact', function () {
    app(TriageManager::class)->extend('spy', fn () => spyDriver(
        fn () => new TriageResult(null, null, 's', 'spy')
    ));
    $item = FeedbackItem::factory()->create();
    $item->external_refs = ['github' => ['issue_number' => 7]];
    $item->save();

    (new TriageFeedbackItem($item->id))->handle(app(TriageManager::class));

    $fresh = $item->refresh();
    expect($fresh->ai_state['triage']['status'])->toBe('completed')
        ->and($fresh->external_refs['github']['issue_number'])->toBe(7);
});

it('writes a terminal failed state for a permanent error straight through handle()', function () {
    // $this->job is null here (direct invocation) — proves we do NOT rely on $this->fail().
    app(TriageManager::class)->extend('spy', fn () => spyDriver(
        fn () => throw new TriageException('auth_401')
    ));
    $item = FeedbackItem::factory()->create();

    (new TriageFeedbackItem($item->id))->handle(app(TriageManager::class));

    $triage = $item->refresh()->ai_state['triage'];
    expect($triage['status'])->toBe('failed')
        ->and($triage['error_code'])->toBe('auth_401');
});

it('rethrows a transient error so the queue can retry', function () {
    app(TriageManager::class)->extend('spy', fn () => spyDriver(
        fn () => throw new TriageException('http_5xx')
    ));
    $item = FeedbackItem::factory()->create();

    expect(fn () => (new TriageFeedbackItem($item->id))->handle(app(TriageManager::class)))
        ->toThrow(TriageException::class, 'http_5xx');
});

it('fails closed and writes terminal failed state for an unexpected non-TriageException', function () {
    // Setting an unknown driver causes TriageManager::driver() to throw InvalidArgumentException
    // (not a TriageException). The job must catch it, write failed, and NOT propagate.
    config()->set('feedback-board.ai.default', 'nope');
    $item = FeedbackItem::factory()->create();

    // Must not throw — the catch(\Throwable) branch handles it.
    (new TriageFeedbackItem($item->id))->handle(app(TriageManager::class));

    $triage = $item->refresh()->ai_state['triage'];
    expect($triage['status'])->toBe('failed')
        ->and($triage['error_code'])->toBe('unknown');
});

it('passes only the title and description to the driver — never reporter PII', function () {
    $seen = null;
    app(TriageManager::class)->extend('spy', function () use (&$seen) {
        return spyDriver(function ($t, $d) use (&$seen) {
            $seen = [$t, $d];
            return new TriageResult(null, null, 's', 'spy');
        });
    });
    $item = FeedbackItem::factory()->create([
        'title' => 'Visible title',
        'description' => 'Visible description',
        'reporter_name' => 'Secret Reporter',
        'reporter_email' => 'secret@example.com',
    ]);

    (new TriageFeedbackItem($item->id))->handle(app(TriageManager::class));

    expect($seen)->toBe(['Visible title', 'Visible description']);
    // The driver received exactly two strings — the FeedbackItem (and its PII) never entered it.
});
