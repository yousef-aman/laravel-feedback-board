<?php

use Illuminate\Support\Facades\{Log, Schema, Storage};
use Livewire\Livewire;
use YousefAman\FeedbackBoard\Enums\{AuthorRole, FeedbackStatus};
use YousefAman\FeedbackBoard\Livewire\{FeedbackDetail, FeedbackItemModal, FeedbackKanban};
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem, FeedbackRead};
use YousefAman\FeedbackBoard\Support\DeveloperReads;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    config()->set('feedback-board.authorization.developer_resolver', fn ($u) => $u->name === 'Dev');
    Storage::fake('local');
    $this->travelTo('2026-10-18 09:00:00');
});

function ubBoardDev(): TestUser
{
    return TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
}

function ubBoardReporterComment(FeedbackItem $item, int $count = 1): void
{
    FeedbackComment::factory()->count($count)->for($item, 'item')->create([
        'author_type' => null,
        'author_id' => null,
        'author_role' => AuthorRole::Reporter,
        'is_internal' => false,
    ]);
}

function ubBoardUnread(int $count): string
{
    return __('feedback-board::feedback-board.kanban.unread', ['count' => $count]);
}

it('writes the baseline on the first board render, so older reports stay quiet', function () {
    $dev = ubBoardDev();
    $item = FeedbackItem::factory()->create();
    ubBoardReporterComment($item);
    $this->travel(1)->minutes();

    $component = Livewire::actingAs($dev)->test(FeedbackKanban::class);

    $component->assertDontSeeHtml('fb-card__news')
        ->assertDontSeeHtml('fb-card__news--new');
    expect(DeveloperReads::baseline($dev)->toDateTimeString())->toBe('2026-10-18 09:01:00');
});

it('shows a bell with the count of comments by others', function () {
    $dev = ubBoardDev();
    $item = FeedbackItem::factory()->create();
    Livewire::actingAs($dev)->test(FeedbackKanban::class);
    $this->travel(1)->seconds();
    DeveloperReads::markRead($item, $dev);
    $this->travel(1)->minutes();
    ubBoardReporterComment($item, 3);

    Livewire::actingAs($dev)->test(FeedbackKanban::class)
        ->assertSeeHtml('fb-card__news')
        ->assertSeeHtml('>3<')
        ->assertSeeHtml(ubBoardUnread(3));
});

it('caps the bell at 99+', function () {
    $dev = ubBoardDev();
    $item = FeedbackItem::factory()->create();
    Livewire::actingAs($dev)->test(FeedbackKanban::class);
    $this->travel(1)->seconds();
    DeveloperReads::markRead($item, $dev);
    $this->travel(1)->seconds();
    ubBoardReporterComment($item, 100);

    Livewire::actingAs($dev)->test(FeedbackKanban::class)
        ->assertSeeHtml('99+')
        ->assertSeeHtml(ubBoardUnread(100));
});

it('labels an unopened open report as New, instead of a number', function () {
    $dev = ubBoardDev();
    Livewire::actingAs($dev)->test(FeedbackKanban::class);
    $this->travel(1)->seconds();
    $item = FeedbackItem::factory()->create(['status' => FeedbackStatus::New]);
    ubBoardReporterComment($item, 2);

    Livewire::actingAs($dev)->test(FeedbackKanban::class)
        ->assertSeeHtml('fb-card__news--new')
        ->assertSeeHtml(__('feedback-board::feedback-board.kanban.new_report'))
        ->assertDontSeeHtml(ubBoardUnread(2));
});

it('hides the visible New from screen readers, which hear "New report" once', function () {
    $dev = ubBoardDev();
    Livewire::actingAs($dev)->test(FeedbackKanban::class);
    $this->travel(1)->seconds();
    FeedbackItem::factory()->create(['status' => FeedbackStatus::New]);

    Livewire::actingAs($dev)->test(FeedbackKanban::class)
        ->assertSeeHtml('<span aria-hidden="true">'.e(__('feedback-board::feedback-board.kanban.new')).'</span>'
            .'<span class="fb-sr-only">'.e(__('feedback-board::feedback-board.kanban.new_report')).'</span>');
});

it('clears the card when the developer opens it, and rings again for a later comment', function () {
    $dev = ubBoardDev();
    Livewire::actingAs($dev)->test(FeedbackKanban::class);
    $this->travel(1)->seconds();
    $item = FeedbackItem::factory()->create();
    ubBoardReporterComment($item);
    $this->travel(1)->seconds();

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item]);
    $this->travel(1)->seconds();
    Livewire::actingAs($dev)->test(FeedbackKanban::class)->assertDontSeeHtml('fb-card__news');

    $this->travel(1)->seconds();
    ubBoardReporterComment($item);
    Livewire::actingAs($dev)->test(FeedbackKanban::class)->assertSeeHtml(ubBoardUnread(1));
});

it('reads what arrives while the card stays open', function () {
    $dev = ubBoardDev();
    $item = FeedbackItem::factory()->create();
    Livewire::actingAs($dev)->test(FeedbackKanban::class);
    $this->travel(1)->seconds();

    $component = Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item]);
    $this->travel(1)->seconds();
    ubBoardReporterComment($item);
    $component->call('$refresh');
    $this->travel(1)->seconds();

    Livewire::actingAs($dev)->test(FeedbackKanban::class)->assertDontSeeHtml('fb-card__news');
});

it('reads the card a notification link opens, on the first visit', function () {
    $dev = ubBoardDev();
    $item = FeedbackItem::factory()->create();
    $this->travel(1)->minutes();

    $this->actingAs($dev)->get(route('feedback-board.board.page', ['report' => $item->id]))->assertOk();

    expect(FeedbackRead::where('feedback_item_id', $item->id)->where('reader_id', $dev->getKey())->exists())->toBeTrue()
        ->and(DeveloperReads::baseline($dev))->not->toBeNull();

    $this->travel(1)->seconds();
    Livewire::actingAs($dev)->test(FeedbackKanban::class)->assertDontSeeHtml('fb-card__news');
});

it('never reads for the developer bell from the reporter view', function () {
    $dev = ubBoardDev();
    $item = FeedbackItem::factory()->for($dev, 'reporter')->create();

    Livewire::actingAs($dev)->test(FeedbackItemModal::class, ['item' => $item]);

    expect(FeedbackRead::whereNotNull('feedback_item_id')->count())->toBe(0);
});

it('asks the panel to refresh its sidebar when the card closes', function () {
    $item = FeedbackItem::factory()->create();

    Livewire::actingAs(ubBoardDev())->test(FeedbackKanban::class)
        ->call('open', $item->id)
        ->call('close')
        ->assertDispatched('refresh-sidebar');
});

it('does not ask for a sidebar refresh when the board loads with no card open', function () {
    Livewire::actingAs(ubBoardDev())->test(FeedbackKanban::class)
        ->assertNotDispatched('refresh-sidebar');

    Livewire::withQueryParams(['report' => '999'])->actingAs(ubBoardDev())->test(FeedbackKanban::class)
        ->assertNotDispatched('refresh-sidebar');
});

it('asks for a sidebar refresh when a card a link opened closes', function () {
    $item = FeedbackItem::factory()->create();

    Livewire::withQueryParams(['report' => (string) $item->id])->actingAs(ubBoardDev())->test(FeedbackKanban::class)
        ->assertSet('selectedId', $item->id)
        ->assertNotDispatched('refresh-sidebar')
        ->call('close')
        ->assertDispatched('refresh-sidebar');
});

it('shows the bell on an Arabic board', function () {
    app()->setLocale('ar');
    $dev = ubBoardDev();
    $item = FeedbackItem::factory()->create();
    Livewire::actingAs($dev)->test(FeedbackKanban::class);
    $this->travel(1)->seconds();
    DeveloperReads::markRead($item, $dev);
    $this->travel(1)->seconds();
    ubBoardReporterComment($item);

    Livewire::actingAs($dev)->test(FeedbackKanban::class)->assertSeeHtml('تعليقات جديدة: 1');
});

it('keeps the board and the card working without the reads table', function () {
    $dev = ubBoardDev();
    $item = FeedbackItem::factory()->create();
    ubBoardReporterComment($item);
    Schema::drop('feedback_reads');
    Log::spy();

    try {
        Livewire::actingAs($dev)->test(FeedbackKanban::class)->assertOk()->assertDontSeeHtml('fb-card__news');
        Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])->assertOk();

        Log::shouldHaveReceived('warning')->once();
    } finally {
        (require __DIR__.'/../../database/migrations/create_feedback_reads_table.php')->up();
    }
});
