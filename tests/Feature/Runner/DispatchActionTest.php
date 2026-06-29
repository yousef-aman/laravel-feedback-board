<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use YousefAman\FeedbackBoard\Actions\DispatchToRunner;
use YousefAman\FeedbackBoard\Jobs\DispatchToRunnerJob;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    config()->set('feedback-board.ai.lock_store', 'array');
    config()->set('feedback-board.runner.enabled', true);
    config()->set('feedback-board.runner.endpoint', 'https://runner.test/task');
    config()->set('feedback-board.runner.secret', 'rsecret');
    config()->set('feedback-board.ingest.enabled', true);
    config()->set('feedback-board.ingest.secret', 'ingsecret');
    config()->set('feedback-board.authorization.developer_resolver', fn ($u) => ($u->name ?? null) === 'Dev');
});

function rDeveloper(): TestUser { return TestUser::create(['name' => 'Dev']); }
function rRegular(): TestUser { return TestUser::create(['name' => 'User']); }

it('forbids non-developers and sends nothing', function () {
    Bus::fake(); Http::fake();
    $item = FeedbackItem::factory()->create();

    expect(fn () => (new DispatchToRunner())->handle($item, rRegular()))->toThrow(HttpException::class);

    Bus::assertNotDispatched(DispatchToRunnerJob::class);
    Http::assertNothingSent();
    expect($item->refresh()->ai_state['runner'] ?? null)->toBeNull();
});

it('422s when runner is not configured', function () {
    config()->set('feedback-board.runner.enabled', false);
    Bus::fake();
    $item = FeedbackItem::factory()->create();

    try { (new DispatchToRunner())->handle($item, rDeveloper()); $this->fail('expected 422'); }
    catch (HttpException $e) { expect($e->getStatusCode())->toBe(422); }
    Bus::assertNotDispatched(DispatchToRunnerJob::class);
});

it('422s when ingest is disabled (post-back could never land)', function () {
    config()->set('feedback-board.ingest.enabled', false);
    Bus::fake();
    $item = FeedbackItem::factory()->create();

    try { (new DispatchToRunner())->handle($item, rDeveloper()); $this->fail('expected 422'); }
    catch (HttpException $e) { expect($e->getStatusCode())->toBe(422); }
    Bus::assertNotDispatched(DispatchToRunnerJob::class);
    expect($item->refresh()->ai_state['runner'] ?? null)->toBeNull();
});

it('writes dispatched + dispatches the job for a configured developer', function () {
    Bus::fake();
    $item = FeedbackItem::factory()->create();

    (new DispatchToRunner())->handle($item, rDeveloper());

    $runner = $item->refresh()->ai_state['runner'];
    expect($runner['status'])->toBe('dispatched')
        ->and($runner['task_id'])->not->toBeNull()
        ->and($runner['analysis_posted'])->toBeFalse();
    Bus::assertDispatched(DispatchToRunnerJob::class);
});

it('is a silent no-op (no exception, no new job) while a dispatch is in flight', function () {
    Bus::fake();
    $item = FeedbackItem::factory()->create();
    $item->ai_state = ['runner' => ['status' => 'running', 'task_id' => 'live']];
    $item->save();

    (new DispatchToRunner())->handle($item, rDeveloper()); // must NOT throw

    expect($item->refresh()->ai_state['runner']['task_id'])->toBe('live'); // unchanged
    Bus::assertNotDispatched(DispatchToRunnerJob::class);
});

it('re-dispatches from a terminal state with a FRESH task_id (supersession source)', function () {
    Bus::fake();
    $item = FeedbackItem::factory()->create();
    $item->ai_state = ['runner' => ['status' => 'completed', 'task_id' => 'OLD']];
    $item->save();

    (new DispatchToRunner())->handle($item, rDeveloper());

    $runner = $item->refresh()->ai_state['runner'];
    expect($runner['status'])->toBe('dispatched')
        ->and($runner['task_id'])->not->toBe('OLD')   // new token supersedes the old one
        ->and($runner['task_id'])->not->toBeNull();
    Bus::assertDispatched(DispatchToRunnerJob::class);
    // The stale OLD post-back is then rejected 409 at ingest (see IngestRunnerStatusTest).
});
