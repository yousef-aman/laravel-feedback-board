<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage};
use Livewire\Attributes\On;
use Livewire\Livewire;
use YousefAman\FeedbackBoard\Enums\{AuthorRole, FeedbackPriority, FeedbackStatus, FeedbackType};
use YousefAman\FeedbackBoard\Livewire\FeedbackKanban;
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};
use YousefAman\FeedbackBoard\Services\AttachmentStorage;
use YousefAman\FeedbackBoard\Support\{DeveloperReads, Person};
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    config()->set('feedback-board.authorization.developer_resolver', fn ($u) => $u->name === 'Dev');
    Storage::fake('local');
});

function kanbanDev(): TestUser
{
    return TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
}

it('forbids non-developers and lets a developer move a card', function () {
    $dev = kanbanDev();
    $client = TestUser::create(['name' => 'Client', 'email' => 'c@x.com']);
    $item = FeedbackItem::factory()->create(['status' => FeedbackStatus::New]);

    Livewire::actingAs($client)->test(FeedbackKanban::class)->call('moveTo', $item->id, 'in_progress')->assertForbidden();

    Livewire::actingAs($dev)->test(FeedbackKanban::class)->call('moveTo', $item->id, 'in_progress')->assertOk();
    expect($item->refresh()->status)->toBe(FeedbackStatus::InProgress);
});

it('opens a card in a dialog and closes it', function () {
    $item = FeedbackItem::factory()->create(['title' => 'Card X']);

    Livewire::actingAs(kanbanDev())->test(FeedbackKanban::class)
        ->call('open', $item->id)
        ->assertSet('selectedId', $item->id)
        ->assertSeeLivewire('feedback-board::feedback-detail')
        ->assertSeeHtml('<div class="fb-dialog" x-data x-on:keydown.escape.window="$wire.close()" x-on:fb-overlay-close.stop="$wire.close()">')
        ->assertSeeHtml('<div class="fb-dialog__backdrop" wire:click="close"></div>')
        ->assertSeeHtml('<div class="fb-dialog__panel" role="dialog" aria-modal="true" aria-labelledby="fb-drawer-title" x-trap.noscroll="true">')
        ->assertDontSeeHtml('fb-drawer"')
        ->call('close')
        ->assertSet('selectedId', null)
        ->assertDontSeeHtml('class="fb-dialog"')
        ->assertDontSeeLivewire('feedback-board::feedback-detail');
});

it('renders no duplicate element ids with a card open', function () {
    FeedbackItem::factory()->count(3)->create();

    $html = Livewire::actingAs(kanbanDev())->test(FeedbackKanban::class)->call('open', FeedbackItem::first()->id)->html();
    preg_match_all('/\sid="([^"]+)"/', $html, $ids);

    expect($ids[1])->toContain('fb-drawer-title')
        ->and(array_count_values($ids[1]))->each->toBe(1);
});

it('renders columns with counts, empty placeholders and keyboard-operable cards', function () {
    FeedbackItem::factory()->create(['title' => 'Urgent card', 'status' => FeedbackStatus::New, 'priority' => FeedbackPriority::Urgent]);

    Livewire::actingAs(kanbanDev())->test(FeedbackKanban::class)
        ->assertSeeHtml('class="fb-scope fb-kanban"')
        ->assertSeeHtml('class="fb-col__count">1</span>')
        ->assertSeeHtml('<span class="fb-badge fb-badge--danger fb-card__prio">Urgent</span>')
        ->assertSeeHtml('class="fb-card__type"')
        ->assertSeeHtml('fb-dot fb-dot--gray')
        ->assertSeeHtml('role="button" tabindex="0"')
        ->assertSeeHtml("\$event.dataTransfer.setData('id'")
        ->assertSeeHtml('$event.dataTransfer.getData(\'id\') && $wire.moveTo($event.dataTransfer.getData(\'id\'), \'in_progress\')')
        ->assertSeeHtml('$event.dataTransfer.types.includes(\'id\') && over++')
        ->assertSeeHtml('x-bind:class="{ \'fb-col--over\': over > 0 }"')
        ->assertSeeHtml('x-bind:class="{ \'fb-card--dragging\': dragging }"')
        ->assertSeeHtml('x-on:keydown.space.prevent=')
        ->assertSee(__('feedback-board::feedback-board.board.empty_column'));
});

it('covers a card with its first own image, lazily, and never with a comment image or a non-image', function () {
    $storage = app(AttachmentStorage::class);

    $shots = FeedbackItem::factory()->create(['title' => 'With shots']);
    $first = $storage->store(UploadedFile::fake()->image('first.png'), $shots);
    $storage->store(UploadedFile::fake()->image('second.jpg'), $shots);

    $commented = FeedbackItem::factory()->create(['title' => 'Comment image only']);
    $storage->store(UploadedFile::fake()->image('reply.png'), $commented, FeedbackComment::factory()->for($commented, 'item')->create());

    // Rows written directly: the configured allow-list would refuse these uploads.
    $vector = FeedbackItem::factory()->create(['title' => 'SVG only']);
    $vector->attachments()->create(['disk' => 'local', 'path' => 'x/logo.svg', 'original_name' => 'logo.svg', 'mime' => 'image/svg+xml', 'size' => 10]);
    $mismatch = FeedbackItem::factory()->create(['title' => 'PNG type, text name']);
    $mismatch->attachments()->create(['disk' => 'local', 'path' => 'x/notes.txt', 'original_name' => 'notes.txt', 'mime' => 'image/png', 'size' => 10]);

    $html = Livewire::actingAs(kanbanDev())->test(FeedbackKanban::class)->html();

    expect(substr_count($html, 'class="fb-card__cover"'))->toBe(1)
        ->and($html)->toContain('<img class="fb-card__cover" src="'.$first->url().'" alt="" loading="lazy" decoding="async" draggable="false"')
        ->and($html)->toContain('x-on:error="$el.remove()"');
});

it('shows public comments, attachments and internal notes on a card, with the reporter and assignee', function () {
    $dev = kanbanDev();
    $storage = app(AttachmentStorage::class);
    $item = FeedbackItem::factory()->create([
        'reporter_name' => 'Lena Client', 'assignee_type' => $dev->getMorphClass(), 'assignee_id' => $dev->id,
    ]);
    $reply = FeedbackComment::factory()->for($item, 'item')->create(['author_role' => AuthorRole::Developer]);
    FeedbackComment::factory()->for($item, 'item')->create();
    $note = FeedbackComment::factory()->for($item, 'item')->create(['author_role' => AuthorRole::Developer, 'is_internal' => true]);
    $storage->store(UploadedFile::fake()->image('a.png'), $item);
    $storage->store(UploadedFile::fake()->image('b.png'), $item, $reply);
    $storage->store(UploadedFile::fake()->image('c.png'), $item, $note);
    FeedbackItem::factory()->create(['title' => 'Quiet card']);

    Livewire::actingAs($dev)->test(FeedbackKanban::class)
        ->assertSeeHtml('<span class="fb-card__ref">#'.$item->id.'</span>')
        ->assertSeeHtml('<span class="fb-sr-only">Comments: 2</span>')
        ->assertSeeHtml('<span class="fb-sr-only">Attachments: 3</span>')
        ->assertSeeHtml('class="fb-card__stat fb-card__stat--internal"')
        ->assertSeeHtml('<span class="fb-sr-only">Internal notes: 1</span>')
        ->assertDontSeeHtml('Comments: 0')
        ->assertSeeHtml('class="fb-avatar fb-avatar--'.Person::hue('Lena Client').'" aria-hidden="true">L</span>')
        ->assertSeeHtml('<span class="fb-sr-only">Reporter: Lena Client</span>')
        ->assertSeeHtml('class="fb-avatar fb-avatar--'.Person::hue('Dev').'" aria-hidden="true">D</span>')
        ->assertSeeHtml('<span class="fb-sr-only">Assignee: Dev</span>');
});

it('filters the board by type, with a count per type', function () {
    FeedbackItem::factory()->create(['title' => 'Broken login', 'type' => FeedbackType::Bug]);
    FeedbackItem::factory()->create(['title' => 'Broken export', 'type' => FeedbackType::Bug]);
    FeedbackItem::factory()->create(['title' => 'Dark mode please', 'type' => FeedbackType::Feature]);

    $component = Livewire::actingAs(kanbanDev())->test(FeedbackKanban::class)
        ->assertSeeHtml('aria-pressed="true" wire:click="filterType(\'all\')"');

    expect($component->viewData('counts'))->toBe(['all' => 3, 'bug' => 2, 'feature' => 1, 'question' => 0, 'other' => 0]);

    $component->call('filterType', 'feature')
        ->assertSet('type', 'feature')
        ->assertSee('Dark mode please')
        ->assertDontSee('Broken login')
        ->assertSeeHtml('class="fb-filter fb-tint" aria-pressed="true" wire:click="filterType(\'feature\')"')
        ->assertSeeHtml('class="fb-col__count">1</span>');

    $component->call('filterType', 'all')->assertSet('type', null)->assertSee('Broken login');
    $component->call('filterType', 'nonsense')->assertSet('type', null)->assertSee('Dark mode please');
});

it('reads the type filter from ?type= and ignores anything that is not a type', function (mixed $type, bool $filtered) {
    FeedbackItem::factory()->create(['title' => 'Broken login', 'type' => FeedbackType::Bug]);
    FeedbackItem::factory()->create(['title' => 'Dark mode please', 'type' => FeedbackType::Feature]);

    $component = Livewire::withQueryParams(['type' => $type])->actingAs(kanbanDev())->test(FeedbackKanban::class)
        ->assertOk()
        ->assertSee('Dark mode please');

    $filtered ? $component->assertDontSee('Broken login') : $component->assertSee('Broken login');
})->with([
    'a type' => ['feature', true],
    'a number' => ['5', false],
    'an integer' => [5, false],
    'a boolean' => ['true', false],
    'an array' => [['feature'], false],
    'junk' => ['nope', false],
]);

it('loads the board with the same number of queries for 2 cards and for 10', function () {
    $dev = kanbanDev();

    $seed = function (int $cards) use ($dev): void {
        FeedbackItem::factory()->count($cards)
            ->create(['assignee_type' => $dev->getMorphClass(), 'assignee_id' => $dev->id])
            ->each(function (FeedbackItem $item): void {
                app(AttachmentStorage::class)->store(UploadedFile::fake()->image('shot.png'), $item);
                FeedbackComment::factory()->for($item, 'item')->create();
                FeedbackComment::factory()->for($item, 'item')->create(['is_internal' => true]);
            });
    };

    $queries = function () use ($dev): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($dev)->test(FeedbackKanban::class)->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    Livewire::actingAs($dev)->test(FeedbackKanban::class); // the first visit writes the developer's baseline
    $seed(2);
    $two = $queries();
    $seed(8);

    expect($queries())->toBe($two);
});

it('keeps the same number of queries for 2 cards and for 10 with unread markers', function () {
    $dev = kanbanDev();

    $seed = function (int $cards) use ($dev): void {
        $items = FeedbackItem::factory()->count($cards)->create();
        $items->filter(fn (FeedbackItem $item, int $i) => $i % 2 === 0)->each(fn (FeedbackItem $item) => DeveloperReads::markRead($item, $dev));
        // A second after the reads, so the read cards get a bell.
        $this->travel(1)->seconds();
        $items->each(fn (FeedbackItem $item) => FeedbackComment::factory()->for($item, 'item')
            ->create(['author_type' => null, 'author_id' => null, 'author_role' => AuthorRole::Reporter]));
    };

    $queries = function () use ($dev): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $board = Livewire::actingAs($dev)->test(FeedbackKanban::class)->assertOk();
        DB::disableQueryLog();

        // Measured on real markers: the read cards ring a bell, the others say New.
        $news = collect($board->viewData('cards'))->pluck('news');
        expect($news->where('new', true))->not->toBeEmpty()
            ->and($news->where('new', false)->where('unread', '>', 0))->not->toBeEmpty();

        return count(DB::getQueryLog());
    };

    Livewire::actingAs($dev)->test(FeedbackKanban::class); // writes the baseline
    $this->travel(1)->seconds();
    $seed(2);
    $two = $queries();
    $seed(8);

    expect($queries())->toBe($two);
});

it('redraws when the card dialog reports a change', function () {
    $item = FeedbackItem::factory()->create(['status' => FeedbackStatus::New]);
    $component = Livewire::actingAs(kanbanDev())->test(FeedbackKanban::class);

    $item->update(['status' => FeedbackStatus::InProgress]);
    $component->dispatch('feedback-updated');

    expect($component->viewData('columns')['in_progress']->pluck('id')->all())->toBe([$item->id])
        ->and($component->viewData('columns')['new'])->toBeEmpty();

    // A Livewire test re-renders on any dispatch; this pins the browser wiring, where only a
    // registered listener makes the board ask the server again.
    $listener = (new ReflectionMethod(FeedbackKanban::class, 'refreshBoard'))->getAttributes(On::class)[0];
    expect($listener->getArguments())->toBe(['feedback-updated']);
});

it('keeps an English title auto-directed on an Arabic board', function () {
    app()->setLocale('ar');
    FeedbackItem::factory()->create(['title' => 'Export to CSV fails']);

    Livewire::actingAs(kanbanDev())->test(FeedbackKanban::class)
        ->assertSeeHtml('dir="rtl"')
        ->assertSeeHtml('<p class="fb-card__title" dir="auto">Export to CSV fails</p>')
        ->assertSeeHtml('aria-label="تصفية حسب النوع"');
});

it('opens the card a link names, and keeps the open card in the link', function () {
    $item = FeedbackItem::factory()->create(['title' => 'Linked card']);

    Livewire::withQueryParams(['report' => (string) $item->id])->actingAs(kanbanDev())->test(FeedbackKanban::class)
        ->assertSet('selectedId', $item->id)
        ->assertSeeLivewire('feedback-board::feedback-detail')
        ->call('close')
        ->assertSet('report', null)
        ->call('open', $item->id)
        ->assertSet('report', $item->id);
});

it('ignores a link to a card that does not exist or is not a number', function (string $report) {
    FeedbackItem::factory()->create();

    Livewire::withQueryParams(['report' => $report])->actingAs(kanbanDev())->test(FeedbackKanban::class)
        ->assertSet('selectedId', null)
        ->assertSet('report', null)
        ->assertDontSeeLivewire('feedback-board::feedback-detail');
})->with(['missing' => '999', 'text' => 'abc', 'zero' => '0', 'negative' => '-1']);
