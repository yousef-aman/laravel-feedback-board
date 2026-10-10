<?php

use Livewire\Livewire;
use YousefAman\FeedbackBoard\Enums\AuthorRole;
use YousefAman\FeedbackBoard\Livewire\{FeedbackItemModal, MyReportsBoard};
use YousefAman\FeedbackBoard\Models\FeedbackComment;
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

it('closes the report drawer', function () {
    $me = TestUser::create(['name' => 'Me', 'email' => 'me@x.com']);
    $item = FeedbackItem::factory()->for($me, 'reporter')->create(['title' => 'Mine']);

    Livewire::actingAs($me)->test(MyReportsBoard::class)
        ->call('open', $item->id)
        ->assertSeeHtml('class="fb-drawer"')
        ->assertSeeHtml('aria-labelledby="fb-drawer-title"')
        ->assertSeeHtml('x-on:keydown.escape.window="$wire.close()"')
        ->assertSeeHtml('class="fb-drawer__body"')
        ->call('close')
        ->assertSet('selectedId', null)
        ->assertDontSeeHtml('class="fb-drawer"')
        ->assertDontSeeLivewire('feedback-board::feedback-item-modal');
});

it('renders reports as keyboard-reachable buttons with a status badge', function () {
    $me = TestUser::create(['name' => 'Me', 'email' => 'me@x.com']);
    FeedbackItem::factory()->for($me, 'reporter')->create(['title' => 'Mine']);

    Livewire::actingAs($me)->test(MyReportsBoard::class)
        ->assertSeeHtml('class="fb-scope fb-reports"')
        ->assertSeeHtml('<button type="button" class="fb-list__item"')
        ->assertSeeHtml('<span class="fb-list__title" dir="auto">Mine</span>')
        ->assertSeeHtml('fb-badge fb-badge--gray')
        ->assertDontSeeHtml('style="cursor:pointer"');
});

it('shows an empty state when the reporter has no reports', function () {
    $me = TestUser::create(['name' => 'Me', 'email' => 'me@x.com']);

    Livewire::actingAs($me)->test(MyReportsBoard::class)
        ->assertSee(__('feedback-board::feedback-board.board.empty_reports'));
});

it('labels thread comments by role for the reporter and hides internal notes', function () {
    $me = TestUser::create(['name' => 'Me', 'email' => 'me@x.com']);
    $item = FeedbackItem::factory()->for($me, 'reporter')->create(['description' => "line one\nline two"]);
    FeedbackComment::factory()->for($item, 'item')->create(['body' => 'We fixed it', 'author_role' => AuthorRole::Developer, 'is_internal' => false]);
    FeedbackComment::factory()->for($item, 'item')->create(['body' => 'secret root cause', 'author_role' => AuthorRole::Developer, 'is_internal' => true]);

    Livewire::actingAs($me)->test(FeedbackItemModal::class, ['item' => $item])
        ->assertSeeHtml('class="fb-scope fb-item"')
        ->assertSeeHtml('id="fb-drawer-title"')
        ->assertSeeHtml('<p class="fb-prose" dir="auto">')
        ->assertSee('We fixed it')
        ->assertSee(AuthorRole::Developer->label())
        ->assertSeeHtml('fb-comment fb-comment--developer')
        ->assertDontSee('secret root cause')
        ->assertDontSee(__('feedback-board::feedback-board.board.internal'));
});
