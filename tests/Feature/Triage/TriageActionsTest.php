<?php

declare(strict_types=1);
// tests/Feature/Triage/TriageActionsTest.php

use Illuminate\Support\Facades\Bus;
use Symfony\Component\HttpKernel\Exception\HttpException;
use YousefAman\FeedbackBoard\Actions\ApplyTriageSuggestion;
use YousefAman\FeedbackBoard\Actions\RequestTriage;
use YousefAman\FeedbackBoard\Enums\FeedbackPriority;
use YousefAman\FeedbackBoard\Enums\FeedbackType;
use YousefAman\FeedbackBoard\Jobs\TriageFeedbackItem;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    config()->set('feedback-board.ai.enabled', true);
    config()->set('feedback-board.ai.default', 'fake');
    // Mirror the repo convention (tests/Feature/AuthorizationTest.php): the users table
    // in TestCase has only id/name/email, so authorize by name — NOT a non-existent column.
    config()->set('feedback-board.authorization.developer_resolver', fn ($u) => ($u->name ?? null) === 'Dev');
});

function developer(): TestUser { return TestUser::create(['name' => 'Dev']); }
function regular(): TestUser { return TestUser::create(['name' => 'User']); }

it('RequestTriage forbids non-developers', function () {
    Bus::fake();
    $item = FeedbackItem::factory()->create();

    expect(fn () => (new RequestTriage())->handle($item, regular()))
        ->toThrow(HttpException::class);

    // A non-developer must never reach dispatch.
    Bus::assertNotDispatched(TriageFeedbackItem::class);
});

it('RequestTriage 422s when unconfigured', function () {
    config()->set('feedback-board.ai.enabled', false);
    Bus::fake();
    $item = FeedbackItem::factory()->create();

    try {
        (new RequestTriage())->handle($item, developer());
        $this->fail('expected 422');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(422);
    }
    Bus::assertNotDispatched(TriageFeedbackItem::class);
});

it('RequestTriage dispatches for a configured developer', function () {
    Bus::fake();
    $item = FeedbackItem::factory()->create();

    (new RequestTriage())->handle($item, developer());

    Bus::assertDispatched(TriageFeedbackItem::class);
});

it('ApplyTriageSuggestion applies whitelisted suggestions', function () {
    $item = FeedbackItem::factory()->create(['type' => FeedbackType::Other, 'priority' => FeedbackPriority::Low]);
    $item->ai_state = ['triage' => [
        'status' => 'completed',
        'suggested_type' => 'feature',
        'suggested_priority' => 'high',
        'summary' => 's', 'model' => 'fake',
    ]];
    $item->save();

    (new ApplyTriageSuggestion())->handle($item, developer(), ['type', 'priority']);

    $fresh = $item->refresh();
    expect($fresh->type)->toBe(FeedbackType::Feature)
        ->and($fresh->priority)->toBe(FeedbackPriority::High);
});

it('ApplyTriageSuggestion ignores non-whitelisted fields and invalid values', function () {
    $item = FeedbackItem::factory()->create(['type' => FeedbackType::Bug, 'status' => \YousefAman\FeedbackBoard\Enums\FeedbackStatus::New]);
    $item->ai_state = ['triage' => [
        'status' => 'completed',
        'suggested_type' => 'not-a-type',
        'summary' => 's', 'model' => 'fake',
    ]];
    $item->save();

    (new ApplyTriageSuggestion())->handle($item, developer(), ['type', 'status']);

    $fresh = $item->refresh();
    expect($fresh->type)->toBe(FeedbackType::Bug) // invalid suggestion ignored
        ->and($fresh->status)->toBe(\YousefAman\FeedbackBoard\Enums\FeedbackStatus::New); // status not whitelisted
});

it('ApplyTriageSuggestion forbids non-developers and never writes the column', function () {
    $item = FeedbackItem::factory()->create(['type' => FeedbackType::Bug]);
    $item->ai_state = ['triage' => [
        'status' => 'completed',
        'suggested_type' => 'feature',
        'summary' => 's', 'model' => 'fake',
    ]];
    $item->save();

    try {
        (new ApplyTriageSuggestion())->handle($item, regular(), ['type']);
        $this->fail('expected 403');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    }

    // The column write must not happen for a non-developer.
    expect($item->refresh()->type)->toBe(FeedbackType::Bug);
});

it('ApplyTriageSuggestion 422s without a completed triage', function () {
    $item = FeedbackItem::factory()->create(['type' => FeedbackType::Bug, 'priority' => FeedbackPriority::Low]);
    $item->ai_state = ['triage' => ['status' => 'pending']];
    $item->save();

    try {
        (new ApplyTriageSuggestion())->handle($item, developer(), ['type']);
        $this->fail('expected 422');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(422);
    }

    // No write may occur before the 422 — the test is discriminating.
    $fresh = $item->refresh();
    expect($fresh->type)->toBe(FeedbackType::Bug)
        ->and($fresh->priority)->toBe(FeedbackPriority::Low);
});
