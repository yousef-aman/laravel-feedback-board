<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Blade, Exceptions, Storage};
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use YousefAman\FeedbackBoard\Enums\FeedbackType;
use YousefAman\FeedbackBoard\Livewire\FeedbackForm;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Services\AttachmentStorage;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(fn () => Storage::fake('local'));

function fbFormUser(): TestUser
{
    return TestUser::create(['name' => 'Lena', 'email' => 'l@x.com']);
}

/** Livewire's temporary uploads. Livewire 4 writes a .json metadata file beside each one, which delete() leaves. */
function fbTemporaryUploadCount(): int
{
    return count(array_filter(
        FileUploadConfiguration::storage()->allFiles(),
        fn (string $path) => ! str_ends_with($path, '.json'),
    ));
}

it('submits a report with staged attachments and the authenticated user as reporter', function () {
    $user = fbFormUser();

    Livewire::actingAs($user)->test(FeedbackForm::class)
        ->set('title', 'Cannot export')
        ->set('type', FeedbackType::Feature->value)
        ->set('description', 'need excel')
        ->set('upload', UploadedFile::fake()->image('a.png'))
        ->assertCount('files', 1)
        ->assertSet('upload', null)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('feedback-submitted')
        ->assertCount('files', 0)
        ->assertSet('type', 'bug');

    $item = FeedbackItem::first();
    expect($item->title)->toBe('Cannot export')
        ->and($item->type)->toBe(FeedbackType::Feature)
        ->and($item->reporter->is($user))->toBeTrue()
        ->and($item->attachments)->toHaveCount(1)
        ->and($item->attachments->first()->original_name)->toBe('a.png')
        ->and($item->meta)->toHaveKey('locale');
});

it('validates required title', function () {
    Livewire::actingAs(fbFormUser())->test(FeedbackForm::class)->set('title', '')->call('submit')->assertHasErrors('title');
});

it('appends staged uploads one at a time, in order', function () {
    $component = Livewire::actingAs(fbFormUser())->test(FeedbackForm::class)
        ->set('upload', UploadedFile::fake()->image('one.png'))
        ->set('upload', UploadedFile::fake()->create('two.pdf', 10, 'application/pdf'))
        ->assertCount('files', 2)
        ->assertSet('upload', null);

    expect(collect($component->get('files'))->map->getClientOriginalName()->all())->toBe(['one.png', 'two.pdf']);
});

it('rejects a disallowed staged file, deletes it and clears the stage', function () {
    $component = Livewire::actingAs(fbFormUser())->test(FeedbackForm::class);
    $before = fbTemporaryUploadCount();

    $component->set('upload', UploadedFile::fake()->create('virus.exe', 10))
        ->assertHasErrors('upload')
        ->assertSee('virus.exe')
        ->assertSet('upload', null)
        ->assertCount('files', 0);

    expect(fbTemporaryUploadCount())->toBe($before);
});

it('rejects uploads past the file limit and deletes them', function () {
    config()->set('feedback-board.attachments.max_files', 1);
    $component = Livewire::actingAs(fbFormUser())->test(FeedbackForm::class)->set('upload', UploadedFile::fake()->image('one.png'));
    $before = fbTemporaryUploadCount();

    $component->set('upload', UploadedFile::fake()->image('two.png'))
        ->assertHasErrors('upload')
        ->assertSee(__('feedback-board::feedback-board.form.max_files_reached', ['count' => 1]))
        ->assertCount('files', 1);

    expect(fbTemporaryUploadCount())->toBe($before);
});

it('removes a pending file and deletes it', function () {
    $component = Livewire::actingAs(fbFormUser())->test(FeedbackForm::class)
        ->set('upload', UploadedFile::fake()->image('a.png'))
        ->set('upload', UploadedFile::fake()->image('b.png'));
    $before = fbTemporaryUploadCount();

    $component->call('removeFile', 0)->assertCount('files', 1);

    expect(collect($component->get('files'))->map->getClientOriginalName()->all())->toBe(['b.png'])
        ->and(fbTemporaryUploadCount())->toBe($before - 1);
});

it('never lets the client set the staged files', function () {
    $this->withoutExceptionHandling();

    expect(fn () => Livewire::actingAs(fbFormUser())->test(FeedbackForm::class)->set('files', []))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('previews only allow-listed images among the pending files', function () {
    config()->set('feedback-board.attachments.allowed_mimes', ['png', 'pdf', 'svg']);

    $html = Livewire::actingAs(fbFormUser())->test(FeedbackForm::class)
        ->set('upload', UploadedFile::fake()->image('shot.png'))
        ->set('upload', UploadedFile::fake()->create('spec.pdf', 5, 'application/pdf'))
        ->set('upload', UploadedFile::fake()->create('logo.svg', 1, 'image/svg+xml'))
        ->assertCount('files', 3)
        ->html();

    // Livewire marks @if blocks in component views with <!--[if BLOCK]><![endif]--> comments.
    expect(preg_match_all('/<span class="fb-upload__thumb">(?:\s|<!--\[if \w+\]><!\[endif\]-->)*<img /', $html))->toBe(1)
        ->and(preg_match_all('/<span class="fb-upload__thumb">(?:\s|<!--\[if \w+\]><!\[endif\]-->)*<svg /', $html))->toBe(2)
        ->and($html)->toContain('<span class="fb-upload__name" dir="auto">logo.svg</span>');
});

it('stores a renamed pasted screenshot as an image attachment', function () {
    Livewire::actingAs(fbFormUser())->test(FeedbackForm::class)
        ->set('title', 'Pasted')
        ->set('upload', UploadedFile::fake()->image('screenshot-20261011-093000.jpg'))
        ->call('submit')
        ->assertHasNoErrors();

    $attachment = FeedbackItem::first()->attachments->first();
    expect($attachment->original_name)->toBe('screenshot-20261011-093000.jpg')
        ->and($attachment->mime)->toBe('image/jpeg')
        ->and($attachment->isImage())->toBeTrue();
});

it('confirms a submission with its reference and a link that opens it', function () {
    $component = Livewire::actingAs(fbFormUser())->test(FeedbackForm::class)
        ->assertSet('sent', false)
        ->set('title', 'Broken link')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('sent', true);

    $id = FeedbackItem::first()->id;

    $component->assertSet('sentId', $id)
        ->assertSee(__('feedback-board::feedback-board.form.sent_title'))
        ->assertSeeHtml('<span class="fb-sent__ref" dir="ltr">#'.$id.'</span>')
        ->assertSeeHtml('href="'.route('feedback-board.page').'?report='.$id.'"')
        ->assertSee(__('feedback-board::feedback-board.form.send_another'))
        ->assertDontSeeHtml('wire:submit="submit"');

    $component->set('sent', false)
        ->assertSeeHtml('wire:submit="submit"')
        ->assertDontSee(__('feedback-board::feedback-board.form.sent_title'));
});

it('stays on the form when validation fails', function () {
    Livewire::actingAs(fbFormUser())->test(FeedbackForm::class)
        ->set('sent', true)
        ->set('title', '')
        ->call('submit')
        ->assertHasErrors('title')
        ->assertSet('sent', false)
        ->assertSeeHtml('class="fb-error"');
});

it('keeps the report and says so when an attachment cannot be stored', function () {
    Exceptions::fake();
    $this->partialMock(AttachmentStorage::class, fn ($mock) => $mock->shouldReceive('store')->andThrow(new RuntimeException('disk full')));

    Livewire::actingAs(fbFormUser())->test(FeedbackForm::class)
        ->set('title', 'With a broken disk')
        ->set('upload', UploadedFile::fake()->image('shot.png'))
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('sent', true)
        ->assertSee(__('feedback-board::feedback-board.form.attachments_failed'));

    expect(FeedbackItem::count())->toBe(1);
    Exceptions::assertReported(RuntimeException::class);
});

it('binds the type through four radio cards in a fieldset', function () {
    $component = Livewire::actingAs(fbFormUser())->test(FeedbackForm::class);
    $html = $component->html();

    expect($html)->toContain('<fieldset class="fb-fieldset">')
        ->toContain('<legend class="fb-label fb-legend">'.__('feedback-board::feedback-board.form.type').'</legend>')
        ->toContain(FeedbackType::Question->hint())
        ->and(substr_count($html, 'type="radio"'))->toBe(4)
        ->and(substr_count($html, 'wire:model="type"'))->toBe(4);

    $component->set('title', 'How do I export?')->set('type', 'question')->call('submit')->assertHasNoErrors();

    expect(FeedbackItem::first()->type)->toBe(FeedbackType::Question);
});

it('labels every field and describes the attachment limits from config', function () {
    config()->set('feedback-board.attachments.allowed_mimes', ['png', 'pdf']);
    config()->set('feedback-board.attachments.max_kb', 512); // below any realistic upload_max_filesize
    config()->set('feedback-board.attachments.max_files', 4);

    Livewire::actingAs(fbFormUser())->test(FeedbackForm::class)
        ->assertSee(__('feedback-board::feedback-board.form.title'))
        ->assertSee(__('feedback-board::feedback-board.form.type'))
        ->assertSee(__('feedback-board::feedback-board.form.attachments'))
        ->assertSee('Allowed: PNG, PDF · up to 0.5 MB each')
        ->assertSee('Up to 4 files')
        ->assertSee(__('feedback-board::feedback-board.form.context'))
        ->assertSeeHtml('class="fb-scope fb-form fb-sheet"')
        ->assertSeeHtml('data-fb-theme="class"');
});

it('feeds the picker, drop and paste through one sequential upload queue', function () {
    $html = Livewire::actingAs(fbFormUser())->test(FeedbackForm::class)->html();

    expect($html)->toContain('x-on:paste="pasted($event)"')
        ->toContain('x-on:change="picked($event)"')
        ->toContain('x-on:drop.prevent="dropped($event)"')
        ->toContain("await new Promise((done) => this.\$wire.upload('upload', file, done, done, (event) => { this.progress = event.detail.progress }, done));")
        ->toContain("used[file.name] = (used[file.name] || 0) + 1;")
        ->toContain("file.name.slice(0, dot) + '-' + used[file.name] + file.name.slice(dot)")
        ->toContain("const types = { 'image/jpeg': 'jpg', 'image/png': 'png', 'image/gif': 'gif', 'image/webp': 'webp' };")
        ->toContain("const generic = ['', 'image.png', 'image.jpg', 'image.jpeg', 'image.gif', 'image.webp'];")
        ->toContain('x-bind:disabled="uploading > 0" wire:loading.attr="disabled" wire:target="submit"')
        ->not->toContain('wire:model="files"')
        ->not->toContain('uploadMultiple');
});

it('derives every element id and the radio group name from the component id', function () {
    $this->actingAs(fbFormUser());

    $html = Blade::render("@livewire('feedback-board::feedback-form')\n@livewire('feedback-board::feedback-form')");

    preg_match_all('/\sid="([^"]+)"/', $html, $ids);
    preg_match_all('/\sname="([^"]+)"/', $html, $names);

    expect($ids[1])->not->toBeEmpty()
        ->and(array_count_values($ids[1]))->each->toBe(1)
        ->and(array_values(array_unique($names[1])))->toHaveCount(2);
});

it('moves focus to the sent panel after a successful submit', function () {
    Livewire::actingAs(fbFormUser())->test(FeedbackForm::class)
        ->set('title', 'Cannot export')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSeeHtml('role="status" tabindex="-1" x-init="$nextTick(() => $el.focus())"');
});

it('formats file sizes for the upload list', function (int $bytes, string $text) {
    expect(AttachmentStorage::humanSize($bytes))->toBe($text);
})->with([[200, '1 KB'], [245760, '240 KB'], [1572864, '1.5 MB']]);

it('formats a size limit in megabytes without a trailing zero', function (int $kb, string $size) {
    expect(AttachmentStorage::megabytes($kb))->toBe($size);
})->with([[10240, '10'], [512, '0.5'], [1536, '1.5'], [1000, '1'], [102400, '100']]);

it('reads PHP ini size values in kilobytes', function (string $value, ?int $kb) {
    expect(AttachmentStorage::iniSizeToKb($value))->toBe($kb);
})->with([['2M', 2048], ['512K', 512], ['1G', 1048576], ['2097152', 2048], ['8m', 8192], ['0', null], ['', null], ['abc', null]]);

it('never promises more than PHP accepts', function () {
    config()->set('feedback-board.attachments.max_kb', 10_000_000);
    $php = AttachmentStorage::iniSizeToKb((string) ini_get('upload_max_filesize'));
    $livewire = 12288; // Livewire's default temporary-upload `max:` rule

    expect(app(AttachmentStorage::class)->limits()['size'])
        ->toBe(AttachmentStorage::megabytes(min(array_filter([10_000_000, $php, $livewire]))));
});

it('never promises more than Livewire accepts for temporary uploads', function () {
    config()->set('feedback-board.attachments.max_kb', 5120);
    config()->set('livewire.temporary_file_upload.rules', 'required|file|max:256');

    expect(app(AttachmentStorage::class)->limits()['size'])->toBe('0.3');
});

it('listens for paste on the wrapper that holds both the sheet head and the form', function () {
    $html = Livewire::actingAs(fbFormUser())->test(FeedbackForm::class)->html();

    expect(substr_count($html, 'x-on:paste="pasted($event)"'))->toBe(1)
        ->and($html)->toMatch('/class="fb-scope fb-form fb-sheet".*?\n\s+x-on:paste="pasted\(\$event\)"\n\s+x-on:fb-launcher-opened\.window=/s')
        ->and($html)->toMatch('/x-on:paste="pasted.*fb-slide-over__head.*<form /s')
        ->and($html)->not->toContain('.window="pasted');
});

it('labels the dialog by the heading id its host passes in', function () {
    $html = Livewire::actingAs(fbFormUser())->test(FeedbackForm::class, ['titleId' => 'fb-launcher-title'])->html();

    expect($html)->toContain('<h2 id="fb-launcher-title" class="fb-slide-over__title">');
});

it('shows an icon instead of a preview when the host narrows the previewable types', function () {
    config()->set('livewire.temporary_file_upload.preview_mimes', ['pdf']);

    $html = Livewire::actingAs(fbFormUser())->test(FeedbackForm::class)
        ->set('upload', UploadedFile::fake()->image('shot.png'))
        ->assertCount('files', 1)
        ->html();

    expect($html)->not->toMatch('/<span class="fb-upload__thumb">(?:\s|<!--\[if \w+\]><!\[endif\]-->)*<img /')
        ->and($html)->toMatch('/<span class="fb-upload__thumb">(?:\s|<!--\[if \w+\]><!\[endif\]-->)*<svg /');
});
