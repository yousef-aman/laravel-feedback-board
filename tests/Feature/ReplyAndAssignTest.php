<?php // tests/Feature/ReplyAndAssignTest.php
use Illuminate\Support\Facades\Event;
use YousefAman\FeedbackBoard\Actions\{AssignFeedback, ReplyToFeedback};
use YousefAman\FeedbackBoard\Events\FeedbackReplied;
use YousefAman\FeedbackBoard\Enums\{AuthorRole, ActivityAction};
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\TestUser;

it('adds a reply comment and fires event', function () {
    Event::fake([FeedbackReplied::class]);
    $item = FeedbackItem::factory()->create();
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);

    $comment = app(ReplyToFeedback::class)->handle($item, 'Fixed in build 12', $dev, AuthorRole::Developer);

    expect($comment->body)->toBe('Fixed in build 12')
        ->and($comment->author_role)->toBe(AuthorRole::Developer)
        ->and($comment->is_internal)->toBeFalse()
        ->and($item->activities()->where('action', ActivityAction::Replied)->exists())->toBeTrue();
    Event::assertDispatched(FeedbackReplied::class);
});

it('assigns and unassigns an item', function () {
    $item = FeedbackItem::factory()->create();
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    app(AssignFeedback::class)->handle($item, $dev, $dev);
    expect($item->refresh()->assignee->is($dev))->toBeTrue();
    app(AssignFeedback::class)->handle($item, null, $dev);
    expect($item->refresh()->assignee_id)->toBeNull();
});
