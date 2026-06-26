<?php

use Illuminate\Support\Facades\Notification;
use YousefAman\FeedbackBoard\Actions\{CreateFeedbackItem, ReplyToFeedback, TransitionStatus};
use YousefAman\FeedbackBoard\Enums\{AuthorRole, FeedbackStatus, FeedbackType};
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Notifications\{FeedbackRepliedNotification, FeedbackResolvedNotification, NewFeedbackNotification};
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    Notification::fake();
    config()->set('feedback-board.notifications.developers', ['dev@team.com']);
    config()->set('feedback-board.notifications.channels', ['mail']);
});

it('notifies developers on a new item', function () {
    app(CreateFeedbackItem::class)->handle(['title' => 'X', 'type' => FeedbackType::Bug], null);
    Notification::assertSentOnDemand(NewFeedbackNotification::class);
});

it('notifies the reporter when resolved and on developer reply', function () {
    $client = TestUser::create(['name' => 'C', 'email' => 'c@x.com']);
    $item = FeedbackItem::factory()->for($client, 'reporter')->create(['status' => FeedbackStatus::New]);

    app(TransitionStatus::class)->handle($item, FeedbackStatus::Resolved, null);
    Notification::assertSentTo($client, FeedbackResolvedNotification::class);

    app(ReplyToFeedback::class)->handle($item, 'done', null, AuthorRole::Developer);
    Notification::assertSentTo($client, FeedbackRepliedNotification::class);
});

it('does not notify the reporter for internal notes', function () {
    $client = TestUser::create(['name' => 'C', 'email' => 'c@x.com']);
    $item = FeedbackItem::factory()->for($client, 'reporter')->create();
    app(ReplyToFeedback::class)->handle($item, 'secret', null, AuthorRole::Developer, isInternal: true);
    Notification::assertNotSentTo($client, FeedbackRepliedNotification::class);
});

it('drops the database channel for on-demand (email-only) developer recipients', function () {
    config()->set('feedback-board.notifications.channels', ['mail', 'database']);
    $item = FeedbackItem::factory()->create();
    $onDemand = Notification::route('mail', 'dev@team.com'); // AnonymousNotifiable
    expect((new NewFeedbackNotification($item))->via($onDemand))->toBe(['mail']);
});

it('stores a Filament-format database payload when Filament is installed', function () {
    if (! class_exists(\Filament\Notifications\Notification::class)) {
        test()->markTestSkipped('Filament not installed');
    }
    $item = FeedbackItem::factory()->create(['title' => 'X']);
    $data = (new NewFeedbackNotification($item))->toArray(new \Illuminate\Notifications\AnonymousNotifiable());
    expect($data)->toHaveKey('format', 'filament')->and($data)->toHaveKey('feedback_item_id', $item->id);
});
