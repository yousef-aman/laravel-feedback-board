<?php

use Livewire\Livewire;
use YousefAman\FeedbackBoard\Enums\AuthorRole;
use YousefAman\FeedbackBoard\Livewire\{FeedbackItemModal, MyReportsBoard};
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\TestUser;

it('shows only the current user reports', function () {
    $me = TestUser::create(['name' => 'Me', 'email' => 'me@x.com']);
    FeedbackItem::factory()->for($me, 'reporter')->create(['title' => 'Mine']);
    FeedbackItem::factory()->create(['title' => 'Theirs']);

    Livewire::actingAs($me)->test(MyReportsBoard::class)
        ->assertSee('Mine')->assertDontSee('Theirs');
});

it('lets the reporter reply on their own item', function () {
    $me = TestUser::create(['name' => 'Me', 'email' => 'me@x.com']);
    $item = FeedbackItem::factory()->for($me, 'reporter')->create();

    Livewire::actingAs($me)->test(FeedbackItemModal::class, ['item' => $item])
        ->set('body', 'any update?')->call('reply')->assertHasNoErrors();

    expect($item->comments()->where('author_role', AuthorRole::Reporter->value)->exists())->toBeTrue();
});

it('opens a report thread from the list (modal reachable)', function () {
    $me = TestUser::create(['name' => 'Me', 'email' => 'me@x.com']);
    $item = FeedbackItem::factory()->for($me, 'reporter')->create(['title' => 'Mine']);

    Livewire::actingAs($me)->test(MyReportsBoard::class)
        ->call('open', $item->id)
        ->assertSet('selectedId', $item->id)
        ->assertSeeLivewire('feedback-board::feedback-item-modal');
});
