<?php

use YousefAman\FeedbackBoard\Models\{FeedbackItem, FeedbackComment};
use YousefAman\FeedbackBoard\Enums\{FeedbackStatus, FeedbackType, AuthorRole};
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
