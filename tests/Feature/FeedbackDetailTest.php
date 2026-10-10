<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Bus, Storage};
use Livewire\Livewire;
use YousefAman\FeedbackBoard\Enums\{AuthorRole, FeedbackPriority, FeedbackStatus};
use YousefAman\FeedbackBoard\Jobs\DeliverToDestination;
use YousefAman\FeedbackBoard\Livewire\{FeedbackDetail, FeedbackItemModal};
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};
use YousefAman\FeedbackBoard\Services\AttachmentStorage;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    config()->set('feedback-board.authorization.developer_resolver', fn ($u) => $u->name === 'Dev');
    Storage::fake('local');
    $this->travelTo('2026-10-01 09:00:00');
});

function detailDev(): TestUser
{
    return TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
}

it('lets a developer reply, add an internal note, assign, set priority and status', function () {
    $dev = detailDev();
    $item = FeedbackItem::factory()->create(['status' => FeedbackStatus::New]);

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->set('body', 'we are on it')->call('reply')->assertHasNoErrors()
        ->set('body', 'root cause: cache')->call('internalNote')->assertHasNoErrors()
        ->call('assignToMe')->assertHasNoErrors()
        ->call('setPriority', FeedbackPriority::High->value)->assertHasNoErrors()
        ->call('changeStatus', FeedbackStatus::InProgress->value)->assertHasNoErrors();

    $item->refresh();
    expect($item->comments()->where('is_internal', false)->value('body'))->toBe('we are on it')
        ->and($item->comments()->where('is_internal', true)->value('body'))->toBe('root cause: cache')
        ->and($item->assignee->is($dev))->toBeTrue()
        ->and($item->priority)->toBe(FeedbackPriority::High)
        ->and($item->status)->toBe(FeedbackStatus::InProgress);
});

it('forbids a non-developer from opening the detail panel', function () {
    $client = TestUser::create(['name' => 'Client', 'email' => 'c@x.com']);
    $item = FeedbackItem::factory()->create();
    Livewire::actingAs($client);
    // mount() calls abort(403); Livewire re-throws on the initial render, so assert the throw directly.
    expect(fn () => Livewire::test(FeedbackDetail::class, ['item' => $item]))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('tells the board after every change to a card', function (string $method, array $arguments) {
    $item = FeedbackItem::factory()->create(['priority' => FeedbackPriority::Low]);
    $item->ai_state = ['triage' => ['status' => 'completed', 'summary' => 'x', 'suggested_type' => 'feature', 'suggested_priority' => 'urgent', 'model' => 'fake']];
    $item->save();

    Livewire::actingAs(detailDev())->test(FeedbackDetail::class, ['item' => $item])
        ->set('body', 'text')
        ->call($method, ...$arguments)
        ->assertHasNoErrors()
        ->assertDispatched('feedback-updated');
})->with([
    'reply' => ['reply', []],
    'internal note' => ['internalNote', []],
    'assign' => ['assignToMe', []],
    'unassign' => ['unassign', []],
    'priority' => ['setPriority', ['high']],
    'status' => ['changeStatus', ['in_review']],
    'triage type' => ['applyTriage', ['type']],
]);

it('labels triage suggestions with translated enum labels', function () {
    $item = FeedbackItem::factory()->create(['priority' => FeedbackPriority::Low]);
    $item->ai_state = ['triage' => ['status' => 'completed', 'summary' => 'Looks urgent', 'suggested_type' => 'feature', 'suggested_priority' => 'urgent', 'model' => 'fake']];
    $item->save();

    Livewire::actingAs(detailDev())->test(FeedbackDetail::class, ['item' => $item])
        ->assertSee(__('feedback-board::feedback-board.triage.apply_priority').': Urgent')
        ->assertSee(__('feedback-board::feedback-board.triage.apply_type').': Feature request')
        ->assertSeeHtml('<span dir="auto">Looks urgent</span>');
});

it('offers a selectable No priority option that clears the priority', function () {
    $item = FeedbackItem::factory()->create(['priority' => null]);

    $component = Livewire::actingAs(detailDev())->test(FeedbackDetail::class, ['item' => $item])
        ->assertSeeHtml('<option value="" selected>');

    $component->call('setPriority', FeedbackPriority::High->value)
        ->assertDontSeeHtml('<option value="" selected>')
        ->call('setPriority', '')
        ->assertSeeHtml('<option value="" selected>');

    expect($item->fresh()->priority)->toBeNull();
});

it('links the captured page URL only when it is http(s), inside a closed details block', function (string $url, bool $linked) {
    $item = FeedbackItem::factory()->create(['meta' => ['url' => $url, 'user_agent' => 'Mozilla/5.0 Test', 'locale' => 'ar']]);

    $component = Livewire::actingAs(detailDev())->test(FeedbackDetail::class, ['item' => $item])
        ->assertSeeHtml('<details class="fb-details fb-tech">')
        ->assertSee(__('feedback-board::feedback-board.board.context'))
        ->assertSee('Mozilla/5.0 Test')
        ->assertSee($url)
        ->assertSeeHtml('<span dir="ltr">Mozilla/5.0 Test</span>');

    $linked
        ? $component->assertSeeHtml('href="'.e($url).'"')
        : $component->assertDontSeeHtml('href="'.e($url).'"')->assertDontSeeHtml('href="javascript:');
})->with([
    ['https://app.test/orders/7', true],
    ['javascript:alert(1)', false],
    [' javascript:alert(1)', false],
]);

it('omits the context section when nothing was captured', function () {
    $item = FeedbackItem::factory()->create(['meta' => []]);

    Livewire::actingAs(detailDev())->test(FeedbackDetail::class, ['item' => $item])
        ->assertDontSee(__('feedback-board::feedback-board.board.context'));
});

it('merges comments and activity sentences into one feed, newest first, without replied lines', function () {
    $item = FeedbackItem::factory()->create(['reporter_name' => 'Lena Client', 'status' => FeedbackStatus::New]);

    $component = Livewire::actingAs(detailDev())->test(FeedbackDetail::class, ['item' => $item])
        ->assertSee('Lena Client')
        ->assertSee(__('feedback-board::feedback-board.board.unassigned'));

    $component->call('changeStatus', FeedbackStatus::InReview->value);
    $this->travel(1)->minutes();
    $component->set('body', 'Looking into it')->call('reply');
    $this->travel(1)->minutes();
    $component->call('assignToMe');

    $html = $component->html();
    // Names are wrapped in Unicode isolates (U+2068 … U+2069) inside sentences.
    $moved = strpos($html, "\u{2068}Dev\u{2069} moved the report from “New” to “In review”");
    $reply = strpos($html, 'Looking into it');
    $assigned = strpos($html, "\u{2068}Dev\u{2069} assigned the report to \u{2068}Dev\u{2069}");

    expect($moved)->not->toBeFalse()
        ->and($assigned)->toBeLessThan($reply)
        ->and($reply)->toBeLessThan($moved)
        ->and($html)->not->toContain("\u{2069} replied")
        ->and($html)->toContain('x-show="showActivity"');
});

it('shows comments with avatars and role tags, tints internal notes, and switches the composer by mode', function () {
    $item = FeedbackItem::factory()->create(['reporter_name' => 'Lena Client']);
    FeedbackComment::factory()->for($item, 'item')->create(['body' => 'Still broken', 'author_role' => AuthorRole::Reporter]);

    Livewire::actingAs(detailDev())->test(FeedbackDetail::class, ['item' => $item])
        ->set('body', 'root cause: cache')->call('internalNote')
        ->assertSeeHtml('class="fb-scope fb-detail"')
        ->assertSeeHtml('<h2 id="fb-drawer-title" class="fb-headline__title" dir="auto">')
        ->assertSeeHtml('class="fb-entry fb-entry--internal"')
        ->assertSee(__('feedback-board::feedback-board.detail.internal_tag'))
        ->assertSeeHtml('<bdi class="fb-entry__name">Dev</bdi>')
        ->assertSeeHtml('<span class="fb-role fb-role--team fb-tint">'.AuthorRole::Developer->label().'</span>')
        ->assertSeeHtml('<bdi class="fb-entry__name">Lena Client</bdi>')
        ->assertSeeHtml('<span class="fb-role">'.AuthorRole::Reporter->label().'</span>')
        ->assertSeeHtml('x-on:submit.prevent="mode === \'internal\' ? $wire.internalNote() : $wire.reply()"')
        ->assertSeeHtml('x-bind:aria-pressed="mode === \'internal\'"')
        ->assertSeeHtml('x-bind:class="{ \'fb-dev-composer--internal\': mode === \'internal\' }"')
        ->assertDontSeeHtml('wire:model="note"');
});

it('writes the unassignment as a sentence in the Arabic feed and keeps titles and URLs directed', function () {
    app()->setLocale('ar');
    $item = FeedbackItem::factory()->create(['title' => 'Export to CSV fails', 'meta' => ['url' => 'https://app.test/orders/7']]);

    Livewire::actingAs(detailDev())->test(FeedbackDetail::class, ['item' => $item])
        ->call('assignToMe')
        ->call('unassign')
        ->assertSee("ألغى \u{2068}Dev\u{2069} الإسناد")
        ->assertSeeHtml('dir="rtl"')
        ->assertSeeHtml('<h2 id="fb-drawer-title" class="fb-headline__title" dir="auto">Export to CSV fails</h2>')
        ->assertSeeHtml('dir="ltr">https://app.test/orders/7</a>');
});

it('posts replies and internal notes with images only, and accepts only images', function () {
    $item = FeedbackItem::factory()->create();

    Livewire::actingAs(detailDev())->test(FeedbackDetail::class, ['item' => $item])
        ->set('upload', UploadedFile::fake()->image('fix.png'))
        ->call('reply')
        ->assertHasNoErrors()
        ->set('upload', UploadedFile::fake()->image('trace.png'))
        ->call('internalNote')
        ->assertHasNoErrors()
        ->set('upload', UploadedFile::fake()->create('log.pdf', 5, 'application/pdf'))
        ->assertHasErrors('upload')
        ->assertCount('files', 0)
        ->call('reply')
        ->assertHasErrors(['body' => 'required_without']);

    $reply = $item->comments()->where('is_internal', false)->sole();
    $note = $item->comments()->where('is_internal', true)->sole();

    expect($reply->body)->toBe('')
        ->and($reply->attachments->pluck('original_name')->all())->toBe(['fix.png'])
        ->and($note->attachments->pluck('original_name')->all())->toBe(['trace.png']);
});

it('shows every attachment in the gallery, internal-note images included, with other files as download tiles', function () {
    $storage = app(AttachmentStorage::class);
    $item = FeedbackItem::factory()->create();
    $shot = $storage->store(UploadedFile::fake()->image('shot.png'), $item);
    $pdf = $storage->store(UploadedFile::fake()->create('spec.pdf', 5, 'application/pdf'), $item);
    $note = FeedbackComment::factory()->for($item, 'item')->create(['is_internal' => true, 'author_role' => AuthorRole::Developer]);
    $trace = $storage->store(UploadedFile::fake()->image('trace.png'), $item, $note);

    Livewire::actingAs(detailDev())->test(FeedbackDetail::class, ['item' => $item])
        ->assertSeeHtml('<ul class="fb-gallery">')
        ->assertSeeHtml('data-fb-src="'.$shot->url().'"')
        ->assertSeeHtml('data-fb-src="'.$trace->url().'"')
        ->assertSeeHtml('<a class="fb-gallery__file" href="'.$pdf->url().'">')
        ->assertSeeHtml('<span class="fb-gallery__name" dir="auto">spec.pdf</span>')
        ->assertSee(__('feedback-board::feedback-board.board.attachments').' · 3');
});

it('shows the cover band with the status select and the close button, and a short band without a cover', function () {
    $dev = detailDev();
    $item = FeedbackItem::factory()->create();

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->assertSeeHtml('<div class="fb-cover fb-cover--empty">')
        ->assertSeeHtml('wire:change="changeStatus($event.target.value)"')
        ->assertSeeHtml('x-on:click="$dispatch(\'fb-overlay-close\')"');

    $cover = app(AttachmentStorage::class)->store(UploadedFile::fake()->image('cover.png'), $item);

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item->fresh()])
        ->assertSeeHtml('<div class="fb-cover">')
        ->assertSeeHtml('data-fb-src="'.$cover->url().'"');
});

it('offers Send to GitHub only when it is configured and the report has no issue yet', function () {
    Bus::fake();
    $dev = detailDev();
    $item = FeedbackItem::factory()->create();

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->assertDontSeeHtml('wire:click="sendToGithub"');

    config()->set('feedback-board.destinations.github.token', 'token');
    config()->set('feedback-board.destinations.github.repo', 'acme/app');

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->assertSeeHtml('wire:click="sendToGithub"')
        ->call('sendToGithub')
        ->assertDontSeeHtml('wire:click="sendToGithub"')
        ->assertSee(__('feedback-board::feedback-board.detail.github_queued'));

    Bus::assertDispatched(DeliverToDestination::class);

    $item->forceFill(['external_refs' => ['github' => ['number' => 7]]])->save();

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item->fresh()])
        ->assertDontSeeHtml('wire:click="sendToGithub"');
});

it('guards sendToGithub like every other write', function () {
    Bus::fake();
    config()->set('feedback-board.destinations.github.token', 'token');
    config()->set('feedback-board.destinations.github.repo', 'acme/app');
    $component = Livewire::actingAs(detailDev())->test(FeedbackDetail::class, ['item' => FeedbackItem::factory()->create()]);

    config()->set('feedback-board.authorization.developer_resolver', fn () => false);

    // This file runs without exception handling (tests/Pest.php), so the 403 is thrown.
    expect(fn () => $component->call('sendToGithub'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Bus::assertNotDispatched(DeliverToDestination::class);
});

it('keeps a hostile file name out of every script position', function () {
    $name = 'x\');alert(1);(\'"</script>.png';
    $dev = detailDev();
    $item = FeedbackItem::factory()->for($dev, 'reporter')->create();
    $reply = FeedbackComment::factory()->for($item, 'item')->create(['author_role' => AuthorRole::Developer]);
    // Symfony strips everything up to the last "/" from an uploaded file's name, so the rows are written directly.
    $item->attachments()->create(['disk' => 'local', 'path' => 'x/a.png', 'original_name' => $name, 'mime' => 'image/png', 'size' => 10]);
    $item->attachments()->create(['feedback_comment_id' => $reply->id, 'disk' => 'local', 'path' => 'x/b.png', 'original_name' => $name, 'mime' => 'image/png', 'size' => 10]);

    foreach ([FeedbackDetail::class, FeedbackItemModal::class] as $component) {
        $html = Livewire::actingAs($dev)->test($component, ['item' => $item])->html();

        expect($html)->not->toContain($name)
            ->and($html)->toContain('data-fb-name="'.e($name).'"');

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();

        foreach ((new DOMXPath($dom))->query('//@*') as $attribute) {
            if (str_contains($attribute->nodeValue, 'alert(1)')) {
                expect($attribute->nodeName)->toStartWith('data-');
            }
        }
    }
});

it('renders balanced div tags with every branch of the view present', function () {
    $dev = detailDev();
    $item = FeedbackItem::factory()->create(['meta' => ['url' => 'https://app.test/x']]);
    $storage = app(AttachmentStorage::class);
    $storage->store(UploadedFile::fake()->image('cover.png'), $item);
    $storage->store(UploadedFile::fake()->create('spec.pdf', 5, 'application/pdf'), $item);
    FeedbackComment::factory()->for($item, 'item')->create(['body' => 'Hello', 'author_role' => AuthorRole::Reporter]);

    $component = Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item->fresh()])->call('assignToMe');
    $html = $component->html();

    // A DOM parse would repair an unclosed tag, so the tags are counted.
    expect(substr_count($html, '<div'))->toBe(substr_count($html, '</div>'));
});
