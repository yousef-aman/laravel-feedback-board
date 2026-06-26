<?php // tests/Feature/TransitionStatusTest.php
use Illuminate\Support\Facades\Event;
use YousefAman\FeedbackBoard\Actions\TransitionStatus;
use YousefAman\FeedbackBoard\Events\FeedbackStatusChanged;
use YousefAman\FeedbackBoard\Enums\FeedbackStatus;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

it('transitions status, stamps resolved_at, logs activity, fires event', function () {
    Event::fake([FeedbackStatusChanged::class]);
    $item = FeedbackItem::factory()->create(['status' => FeedbackStatus::New]);

    app(TransitionStatus::class)->handle($item, FeedbackStatus::Resolved, null);

    $item->refresh();
    expect($item->status)->toBe(FeedbackStatus::Resolved)
        ->and($item->resolved_at)->not->toBeNull()
        ->and($item->activities()->where('to_status', FeedbackStatus::Resolved->value)->exists())->toBeTrue();
    Event::assertDispatched(FeedbackStatusChanged::class);
});

it('no-ops when transitioning to the same status', function () {
    Event::fake([FeedbackStatusChanged::class]);
    $item = FeedbackItem::factory()->create(['status' => FeedbackStatus::New]);
    app(TransitionStatus::class)->handle($item, FeedbackStatus::New, null);
    expect($item->activities()->count())->toBe(0);
    Event::assertNotDispatched(FeedbackStatusChanged::class);
});
