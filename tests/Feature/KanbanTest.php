<?php

use Livewire\Livewire;
use YousefAman\FeedbackBoard\Enums\FeedbackPriority;
use YousefAman\FeedbackBoard\Enums\FeedbackStatus;
use YousefAman\FeedbackBoard\Livewire\FeedbackKanban;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(fn () => config()->set('feedback-board.authorization.developer_resolver', fn ($u) => $u->name === 'Dev'));

it('forbids non-developers and lets a developer move a card', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $client = TestUser::create(['name' => 'Client', 'email' => 'c@x.com']);
    $item = FeedbackItem::factory()->create(['status' => FeedbackStatus::New]);

    Livewire::actingAs($client)->test(FeedbackKanban::class)->call('moveTo', $item->id, 'in_progress')->assertForbidden();

    Livewire::actingAs($dev)->test(FeedbackKanban::class)->call('moveTo', $item->id, 'in_progress')->assertOk();
    expect($item->refresh()->status)->toBe(FeedbackStatus::InProgress);
});

it('opens a card detail panel from the board', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $item = FeedbackItem::factory()->create(['title' => 'Card X']);
    Livewire::actingAs($dev)->test(FeedbackKanban::class)
        ->call('open', $item->id)
        ->assertSet('selectedId', $item->id)
        ->assertSeeLivewire('feedback-board::feedback-detail');
});

it('closes the detail drawer', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $item = FeedbackItem::factory()->create(['title' => 'Card X']);

    Livewire::actingAs($dev)->test(FeedbackKanban::class)
        ->call('open', $item->id)
        ->assertSeeHtml('class="fb-drawer"')
        ->call('close')
        ->assertSet('selectedId', null)
        ->assertDontSeeHtml('class="fb-drawer"')
        ->assertDontSeeLivewire('feedback-board::feedback-detail');
});

it('renders columns with counts, empty placeholders and keyboard-operable cards', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    FeedbackItem::factory()->create(['title' => 'Urgent card', 'status' => FeedbackStatus::New, 'priority' => FeedbackPriority::Urgent]);

    Livewire::actingAs($dev)->test(FeedbackKanban::class)
        ->assertSeeHtml('class="fb-scope fb-kanban"')
        ->assertSeeHtml('class="fb-col__count">1</span>')
        ->assertSeeHtml('fb-badge fb-badge--danger')
        ->assertSeeHtml('fb-dot fb-dot--gray')
        ->assertSeeHtml('role="button" tabindex="0"')
        ->assertSeeHtml("\$event.dataTransfer.setData('id'")
        ->assertSeeHtml('$event.dataTransfer.getData(\'id\') && $wire.moveTo($event.dataTransfer.getData(\'id\'), \'in_progress\')')
        ->assertSeeHtml('$event.dataTransfer.types.includes(\'id\') && over++')
        ->assertSeeHtml('x-on:keydown.space.prevent=')
        ->assertSee(__('feedback-board::feedback-board.board.empty_column'));
});
