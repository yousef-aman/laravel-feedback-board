<?php // tests/Feature/CreateFeedbackItemTest.php
use Illuminate\Support\Facades\Event;
use YousefAman\FeedbackBoard\Actions\CreateFeedbackItem;
use YousefAman\FeedbackBoard\Events\FeedbackItemCreated;
use YousefAman\FeedbackBoard\Enums\{ActivityAction, FeedbackStatus, FeedbackType};
use YousefAman\FeedbackBoard\Tests\TestUser;

it('creates an item, snapshots the reporter, logs activity, fires event', function () {
    Event::fake([FeedbackItemCreated::class]);
    $user = TestUser::create(['name' => 'Mona', 'email' => 'm@x.com']);

    $item = app(CreateFeedbackItem::class)->handle([
        'title' => 'Login fails', 'description' => 'sometimes',
        'type' => FeedbackType::Bug, 'meta' => ['url' => '/login'],
        'is_internal' => true, // must be ignored (not whitelisted)
    ], $user);

    expect($item->status)->toBe(FeedbackStatus::New)
        ->and($item->reporter->is($user))->toBeTrue()
        ->and($item->reporter_email)->toBe('m@x.com')
        ->and($item->project)->toBe('default')
        ->and($item->activities()->where('action', ActivityAction::Created)->exists())->toBeTrue();

    Event::assertDispatched(FeedbackItemCreated::class, fn ($e) => $e->item->is($item));
});
