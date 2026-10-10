<?php

use YousefAman\FeedbackBoard\Models\{FeedbackActivity, FeedbackItem, FeedbackComment};
use YousefAman\FeedbackBoard\Enums\{ActivityAction, FeedbackStatus, FeedbackType, AuthorRole};
use YousefAman\FeedbackBoard\Tests\TestUser;

it('persists an item with enum casts and morphed reporter', function () {
    $user = TestUser::create(['name' => 'Sara', 'email' => 's@x.com']);
    $item = FeedbackItem::factory()->for($user, 'reporter')->create([
        'type' => FeedbackType::Bug, 'status' => FeedbackStatus::New, 'meta' => ['url' => '/x'],
    ]);

    $fresh = $item->fresh();
    expect($fresh->status)->toBe(FeedbackStatus::New)
        ->and($fresh->type)->toBe(FeedbackType::Bug)
        ->and($fresh->meta)->toBe(['url' => '/x'])
        ->and($fresh->reporter->is($user))->toBeTrue();
});

it('threads comments under an item', function () {
    $item = FeedbackItem::factory()->create();
    $c = FeedbackComment::factory()->for($item, 'item')->create([
        'author_role' => AuthorRole::Developer, 'is_internal' => true,
    ]);
    expect($item->comments)->toHaveCount(1)
        ->and($item->comments->first()->is_internal)->toBeTrue()
        ->and($c->author_role)->toBe(AuthorRole::Developer);
});

it('summarises activity entries for the item timeline', function () {
    $item = FeedbackItem::factory()->create();
    $make = fn (array $attributes) => FeedbackActivity::create(['feedback_item_id' => $item->id, ...$attributes]);

    expect($make(['action' => ActivityAction::StatusChanged, 'from_status' => FeedbackStatus::New, 'to_status' => FeedbackStatus::InProgress])->summary())
        ->toBe('Status changed: In progress')
        ->and($make(['action' => ActivityAction::Assigned, 'properties' => ['assignee_id' => 7]])->summary())->toBe('Assigned')
        ->and($make(['action' => ActivityAction::Assigned, 'properties' => ['assignee_id' => null]])->summary())->toBe('Unassigned')
        ->and($make(['action' => ActivityAction::Assigned])->summary())->toBe('Assigned')
        ->and($make(['action' => ActivityAction::Replied])->summary())->toBe('Replied');
});
