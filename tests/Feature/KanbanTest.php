<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage};
use Livewire\Attributes\On;
use Livewire\Livewire;
use YousefAman\FeedbackBoard\Enums\{AuthorRole, FeedbackPriority, FeedbackStatus, FeedbackType};
use YousefAman\FeedbackBoard\Livewire\FeedbackKanban;
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};
use YousefAman\FeedbackBoard\Services\AttachmentStorage;
use YousefAman\FeedbackBoard\Support\Person;
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

it('opens a card detail panel from the board', function () {
    $item = FeedbackItem::factory()->create(['title' => 'Card X']);

    Livewire::actingAs(kanbanDev())->test(FeedbackKanban::class)
        ->call('open', $item->id)
        ->assertSet('selectedId', $item->id)
        ->assertSeeLivewire('feedback-board::feedback-detail');
});

it('closes the detail drawer', function () {
    $item = FeedbackItem::factory()->create(['title' => 'Card X']);

    Livewire::actingAs(kanbanDev())->test(FeedbackKanban::class)
        ->call('open', $item->id)
        ->assertSeeHtml('class="fb-drawer"')
        ->call('close')
        ->assertSet('selectedId', null)
        ->assertDontSeeHtml('class="fb-drawer"')
        ->assertDontSeeLivewire('feedback-board::feedback-detail');
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
