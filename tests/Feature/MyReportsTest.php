<?php

use Livewire\Livewire;
use YousefAman\FeedbackBoard\Enums\AuthorRole;
use YousefAman\FeedbackBoard\Livewire\MyReportsList;
use YousefAman\FeedbackBoard\Livewire\ReplyInline;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\TestUser;

it('lists only the authenticated reporter\'s feedback items', function () {
    $user = TestUser::create(['name' => 'Alice', 'email' => 'a@x.com']);
    $other = TestUser::create(['name' => 'Bob', 'email' => 'b@x.com']);

    $mine = FeedbackItem::factory()->for($user, 'reporter')->create(['title' => 'My Bug']);
    FeedbackItem::factory()->for($other, 'reporter')->create(['title' => 'Their Bug']);

    Livewire::actingAs($user)->test(MyReportsList::class)
        ->assertSee('My Bug')
        ->assertDontSee('Their Bug');
});

it('allows the reporter to post a reply on their own item', function () {
    $user = TestUser::create(['name' => 'Alice', 'email' => 'a@x.com']);
    $item = FeedbackItem::factory()->for($user, 'reporter')->create();

    Livewire::actingAs($user)->test(ReplyInline::class, ['item' => $item])
        ->set('body', 'Still happening')
        ->call('reply')
        ->assertHasNoErrors()
        ->assertSet('body', '');

    expect($item->comments()->where('body', 'Still happening')->exists())->toBeTrue();
});

it('prevents a non-owner from posting a reply', function () {
    $owner = TestUser::create(['name' => 'Alice', 'email' => 'a@x.com']);
    $stranger = TestUser::create(['name' => 'Eve', 'email' => 'e@x.com']);
    $item = FeedbackItem::factory()->for($owner, 'reporter')->create();

    Livewire::actingAs($stranger)->test(ReplyInline::class, ['item' => $item])
        ->set('body', 'Hacked')
        ->call('reply')
        ->assertForbidden();
});
