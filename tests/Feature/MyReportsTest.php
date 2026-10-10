<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use YousefAman\FeedbackBoard\Actions\TransitionStatus;
use YousefAman\FeedbackBoard\Enums\{AuthorRole, FeedbackStatus};
use YousefAman\FeedbackBoard\Livewire\{FeedbackItemModal, MyReportsBoard};
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};
use YousefAman\FeedbackBoard\Services\AttachmentStorage;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    config()->set('feedback-board.authorization.developer_resolver', fn ($u) => $u->name === 'Dev');
    Storage::fake('local');
    $this->travelTo('2026-10-01 09:00:00');
});

function fbReporter(): TestUser
{
    return TestUser::create(['name' => 'Me', 'email' => 'me@x.com']);
}

it('shows only the current user reports', function () {
    $me = fbReporter();
    FeedbackItem::factory()->for($me, 'reporter')->create(['title' => 'Mine']);
    FeedbackItem::factory()->create(['title' => 'Theirs']);

    Livewire::actingAs($me)->test(MyReportsBoard::class)->assertSee('Mine')->assertDontSee('Theirs');
});

it('lets the reporter reply on their own item', function () {
    $me = fbReporter();
    $item = FeedbackItem::factory()->for($me, 'reporter')->create();

    Livewire::actingAs($me)->test(FeedbackItemModal::class, ['item' => $item])
        ->set('body', 'any update?')->call('reply')->assertHasNoErrors();

    expect($item->comments()->where('author_role', AuthorRole::Reporter->value)->exists())->toBeTrue();
});

it('opens a report from the list in a wide slide-over and closes it', function () {
    $me = fbReporter();
    $item = FeedbackItem::factory()->for($me, 'reporter')->create(['title' => 'Mine']);

    Livewire::actingAs($me)->test(MyReportsBoard::class)
        ->call('open', $item->id)
        ->assertSet('report', $item->id)
        ->assertSeeLivewire('feedback-board::feedback-item-modal')
        ->assertSeeHtml('class="fb-slide-over fb-slide-over--wide"')
        ->assertSeeHtml('x-on:fb-overlay-close.stop="$wire.close()"')
        ->assertSeeHtml('x-on:keydown.escape.window="true && ($wire.close())"')
        ->call('close')
        ->assertSet('report', null)
        ->assertDontSeeLivewire('feedback-board::feedback-item-modal');
});

it('lists reports as keyboard-reachable rows with type, reference, status and public reply count', function () {
    $me = fbReporter();
    $item = FeedbackItem::factory()->for($me, 'reporter')->create(['title' => 'Mine']);
    FeedbackComment::factory()->for($item, 'item')->create(['author_role' => AuthorRole::Developer]);
    FeedbackComment::factory()->for($item, 'item')->create(['author_role' => AuthorRole::Developer, 'is_internal' => true]);

    Livewire::actingAs($me)->test(MyReportsBoard::class)
        ->assertSeeHtml('class="fb-scope fb-reports"')
        ->assertSeeHtml('<button type="button" class="fb-row"')
        ->assertSeeHtml('class="fb-type-icon fb-type-icon--bug"')
        ->assertSeeHtml('<span class="fb-row__title" dir="auto">Mine</span>')
        ->assertSeeHtml('<span class="fb-row__ref">#'.$item->id.' · Bug</span>')
        ->assertSeeHtml('fb-badge fb-badge--gray')
        ->assertSeeHtml('<span class="fb-row__count">1</span>')
        ->assertDontSeeHtml('style="cursor:pointer"');
});

it('filters reports into open and done with counts', function () {
    $me = fbReporter();
    FeedbackItem::factory()->for($me, 'reporter')->create(['title' => 'Fresh', 'status' => FeedbackStatus::New]);
    FeedbackItem::factory()->for($me, 'reporter')->create(['title' => 'Looking', 'status' => FeedbackStatus::InReview]);
    FeedbackItem::factory()->for($me, 'reporter')->create(['title' => 'Fixed', 'status' => FeedbackStatus::Resolved]);

    $component = Livewire::actingAs($me)->test(MyReportsBoard::class);

    expect($component->viewData('counts'))->toBe(['all' => 3, 'open' => 2, 'done' => 1]);

    $component->call('filterBy', 'done')
        ->assertSee('Fixed')->assertDontSee('Fresh')
        ->assertSeeHtml('aria-pressed="true" wire:click="filterBy(\'done\')"');

    $component->call('filterBy', 'open')->assertSee('Fresh')->assertSee('Looking')->assertDontSee('Fixed');
    $component->call('filterBy', 'nonsense')->assertSet('filter', 'all')->assertSee('Fixed');
});

it('shows an empty state for each filter', function () {
    $component = Livewire::actingAs(fbReporter())->test(MyReportsBoard::class)
        ->assertSee(__('feedback-board::feedback-board.board.empty_reports'));

    $component->call('filterBy', 'open')->assertSee(__('feedback-board::feedback-board.reports.empty_open'));
    $component->call('filterBy', 'done')->assertSee(__('feedback-board::feedback-board.reports.empty_done'));
});

it('shows its own heading and New report button outside a panel, and hides them inside one', function () {
    $me = fbReporter();

    Livewire::actingAs($me)->test(MyReportsBoard::class)
        ->assertSeeHtml('<h1 class="fb-head__title">'.__('feedback-board::feedback-board.board.my_reports').'</h1>')
        ->assertSeeHtml('x-on:click="$dispatch(\'fb-open-report-form\')"')
        ->assertSeeHtml('x-on:fb-open-report-form.window="formOpen = true; $dispatch(\'fb-launcher-opened\')"')
        ->assertSeeHtml('x-trap.noscroll="formOpen"')
        ->assertSeeLivewire('feedback-board::feedback-form');

    Livewire::actingAs($me)->test(MyReportsBoard::class, ['inPanel' => true])
        ->assertDontSeeHtml('class="fb-head"')
        ->assertSeeHtml('x-on:fb-open-report-form.window=');
});

it('shows the conversation chat-style without internal notes, even to a developer', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $item = FeedbackItem::factory()->for($dev, 'reporter')->create(['description' => "line one\nline two"]);
    FeedbackComment::factory()->for($item, 'item')->create([
        'body' => 'Mine to you', 'author_role' => AuthorRole::Reporter, 'author_type' => $dev->getMorphClass(), 'author_id' => $dev->id,
    ]);
    FeedbackComment::factory()->for($item, 'item')->create(['body' => 'We fixed it', 'author_role' => AuthorRole::Developer]);
    FeedbackComment::factory()->for($item, 'item')->create(['body' => 'secret root cause', 'author_role' => AuthorRole::Developer, 'is_internal' => true]);

    Livewire::actingAs($dev)->test(FeedbackItemModal::class, ['item' => $item])
        ->assertSeeHtml('class="fb-scope fb-item fb-sheet"')
        ->assertSeeHtml('<p class="fb-prose" dir="auto">line one')
        ->assertSeeHtml('class="fb-msg fb-msg--mine"')
        ->assertSee(__('feedback-board::feedback-board.item.you'))
        ->assertSeeHtml('class="fb-msg fb-msg--theirs"')
        ->assertSee(AuthorRole::Developer->label())
        ->assertSee('We fixed it')
        ->assertDontSee('secret root cause');
});

it('shows the status steps, and a dated banner for closed and declined reports', function () {
    $me = fbReporter();
    $item = FeedbackItem::factory()->for($me, 'reporter')->create(['status' => FeedbackStatus::InProgress]);

    Livewire::actingAs($me)->test(FeedbackItemModal::class, ['item' => $item])
        ->assertSeeHtml('class="fb-steps"')
        ->assertSeeHtml('class="fb-step fb-step--current" aria-current="step"')
        ->assertSeeHtml('class="fb-step fb-step--done"')
        ->assertDontSeeHtml('fb-banner');

    $this->travel(1)->hours();
    app(TransitionStatus::class)->handle($item->fresh(), FeedbackStatus::Declined, null);

    Livewire::actingAs($me)->test(FeedbackItemModal::class, ['item' => $item->fresh()])
        ->assertDontSeeHtml('class="fb-steps"')
        ->assertSeeHtml('class="fb-notice fb-banner fb-notice--danger"')
        ->assertSee(__('feedback-board::feedback-board.item.declined_banner'))
        ->assertSeeHtml('<time datetime="2026-10-01T10:00:00+00:00">');
});

it('marks a report read when it is opened, so its row loses the dot, and tags the newer replies', function () {
    $me = fbReporter();
    $item = FeedbackItem::factory()->for($me, 'reporter')->create(['title' => 'Mine']);
    $this->travel(1)->minutes();
    FeedbackComment::factory()->for($item, 'item')->create(['body' => 'Older reply', 'author_role' => AuthorRole::Developer]);
    $item->fresh()->markReadBy($me);
    $this->travel(1)->minutes();
    FeedbackComment::factory()->for($item, 'item')->create(['body' => 'Newer reply', 'author_role' => AuthorRole::Developer]);
    $updatedAt = $item->fresh()->updated_at;

    $board = Livewire::actingAs($me)->test(MyReportsBoard::class)
        ->assertSeeHtml('<span class="fb-unread" aria-hidden="true"></span>')
        ->assertSee(__('feedback-board::feedback-board.reports.new_reply'));

    $this->travel(1)->minutes();
    $board->call('open', $item->id)
        ->assertDontSeeHtml('<span class="fb-unread" aria-hidden="true"></span>')
        ->assertSet('readBefore', '2026-10-01T09:01:00+00:00');

    expect($item->fresh()->reporter_read_at->toDateTimeString())->toBe('2026-10-01 09:03:00')
        ->and($item->fresh()->updated_at)->toEqual($updatedAt);

    $html = Livewire::actingAs($me)
        ->test(FeedbackItemModal::class, ['item' => $item, 'readBefore' => '2026-10-01T09:01:00+00:00'])
        ->html();

    expect(substr_count($html, '<span class="fb-new">'))->toBe(1)
        ->and(strpos($html, 'Older reply'))->toBeLessThan(strpos($html, '<span class="fb-new">'))
        ->and(strpos($html, '<span class="fb-new">'))->toBeLessThan(strpos($html, 'Newer reply'));
});

it('opens a report from ?report= and opens nothing for anything else', function (mixed $report, bool $opens) {
    $me = fbReporter();
    $other = TestUser::create(['name' => 'Other', 'email' => 'o@x.com']);
    FeedbackItem::factory()->count(4)->for($other, 'reporter')->create();
    $mine = FeedbackItem::factory()->for($me, 'reporter')->create();

    expect($mine->id)->toBe(5);

    $component = Livewire::withQueryParams(['report' => $report])->actingAs($me)->test(MyReportsBoard::class)->assertOk();

    $opens
        ? $component->assertSeeLivewire('feedback-board::feedback-item-modal')
        : $component->assertDontSeeLivewire('feedback-board::feedback-item-modal');
    expect($mine->fresh()->reporter_read_at !== null)->toBe($opens);
})->with([
    'own id as a string' => ['5', true],
    'own id as an integer' => [5, true],
    'someone else\'s id' => ['1', false],
    'a missing id' => ['999', false],
    'letters' => ['abc', false],
    'an array' => [['5'], false],
    'a negative number' => ['-5', false],
]);

it('lets the reporter reply with images only, and reads replies by replying', function () {
    $me = fbReporter();
    $item = FeedbackItem::factory()->for($me, 'reporter')->create();

    $this->travel(1)->minutes();
    Livewire::actingAs($me)->test(FeedbackItemModal::class, ['item' => $item])
        ->set('upload', UploadedFile::fake()->image('shot.png'))
        ->call('reply')
        ->assertHasNoErrors()
        ->assertCount('files', 0);

    $comment = $item->comments()->latest('id')->first();
    expect($comment->body)->toBe('')
        ->and($comment->author_role)->toBe(AuthorRole::Reporter)
        ->and($comment->attachments)->toHaveCount(1)
        ->and($comment->attachments->first()->isImage())->toBeTrue()
        ->and($item->fresh()->reporter_read_at->toDateTimeString())->toBe('2026-10-01 09:01:00');
});

it('accepts only images in the reporter composer and needs text or an image', function () {
    $me = fbReporter();
    $item = FeedbackItem::factory()->for($me, 'reporter')->create();

    Livewire::actingAs($me)->test(FeedbackItemModal::class, ['item' => $item])
        ->set('upload', UploadedFile::fake()->create('notes.pdf', 5, 'application/pdf'))
        ->assertHasErrors('upload')
        ->assertCount('files', 0)
        ->call('reply')
        ->assertHasErrors(['body' => 'required_without'])
        ->assertSee(__('feedback-board::feedback-board.composer.empty'));

    expect($item->comments()->count())->toBe(0);
});

it('hides the image picker and ignores pastes when the host allows no image types', function () {
    config()->set('feedback-board.attachments.allowed_mimes', ['pdf']);
    $me = fbReporter();
    $item = FeedbackItem::factory()->for($me, 'reporter')->create();

    Livewire::actingAs($me)->test(FeedbackItemModal::class, ['item' => $item])
        ->assertDontSeeHtml('accept="image/*"')
        ->assertDontSeeHtml('x-on:paste=');
});

it('never shows the images of an internal note to the reporter', function () {
    $me = fbReporter();
    $item = FeedbackItem::factory()->for($me, 'reporter')->create();
    $note = FeedbackComment::factory()->for($item, 'item')->create(['is_internal' => true, 'author_role' => AuthorRole::Developer]);
    $secret = app(AttachmentStorage::class)->store(UploadedFile::fake()->image('secret.png'), $item, $note);
    $own = app(AttachmentStorage::class)->store(UploadedFile::fake()->image('mine.png'), $item);

    Livewire::actingAs($me)->test(FeedbackItemModal::class, ['item' => $item])
        ->assertSeeHtml('data-fb-src="'.$own->url().'"')
        ->assertDontSeeHtml('data-fb-src="'.$secret->url().'"')
        ->assertDontSee('secret.png');
});
