<?php
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use YousefAman\FeedbackBoard\Enums\FeedbackType;
use YousefAman\FeedbackBoard\Livewire\FeedbackForm;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\TestUser;

it('submits feedback with the authenticated user as reporter', function () {
    Storage::fake('local');
    $user = TestUser::create(['name' => 'Lena', 'email' => 'l@x.com']);

    Livewire::actingAs($user)->test(FeedbackForm::class)
        ->set('title', 'Cannot export')
        ->set('type', FeedbackType::Feature->value)
        ->set('description', 'need excel')
        ->set('files', [UploadedFile::fake()->image('a.png')])
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('feedback-submitted');

    $item = FeedbackItem::first();
    expect($item->title)->toBe('Cannot export')
        ->and($item->reporter->is($user))->toBeTrue()
        ->and($item->attachments)->toHaveCount(1)
        ->and($item->meta)->toHaveKey('locale');
});

it('validates required title', function () {
    $user = TestUser::create(['name' => 'L', 'email' => 'l@x.com']);
    Livewire::actingAs($user)->test(FeedbackForm::class)->set('title', '')->call('submit')->assertHasErrors('title');
});

it('confirms a successful submission and can start a new report', function () {
    Storage::fake('local');
    $user = TestUser::create(['name' => 'Lena', 'email' => 'l@x.com']);

    $component = Livewire::actingAs($user)->test(FeedbackForm::class)
        ->assertSet('sent', false)
        ->set('title', 'Broken link')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('sent', true)
        ->assertSee(__('feedback-board::feedback-board.form.sent_title'))
        ->assertSee(__('feedback-board::feedback-board.form.send_another'))
        ->assertSeeHtml('href="'.route('feedback-board.page').'"')
        ->assertDontSeeHtml('wire:submit="submit"');

    $component->set('sent', false)
        ->assertSeeHtml('wire:submit="submit"')
        ->assertDontSee(__('feedback-board::feedback-board.form.sent_title'));
});

it('stays on the form when validation fails', function () {
    $user = TestUser::create(['name' => 'L', 'email' => 'l@x.com']);

    Livewire::actingAs($user)->test(FeedbackForm::class)
        ->set('sent', true)
        ->set('title', '')
        ->call('submit')
        ->assertHasErrors('title')
        ->assertSet('sent', false)
        ->assertSeeHtml('class="fb-error"');
});

it('labels every field and describes the attachment limits from config', function () {
    config()->set('feedback-board.attachments.allowed_mimes', ['png', 'pdf']);
    config()->set('feedback-board.attachments.max_kb', 512); // below any realistic upload_max_filesize
    $user = TestUser::create(['name' => 'L', 'email' => 'l@x.com']);

    Livewire::actingAs($user)->test(FeedbackForm::class)
        ->assertSee(__('feedback-board::feedback-board.form.title'))
        ->assertSee(__('feedback-board::feedback-board.form.type'))
        ->assertSee(__('feedback-board::feedback-board.form.attachments'))
        ->assertSee('Allowed: PNG, PDF · up to 0.5 MB each')
        ->assertSeeHtml('class="fb-scope fb-form"')
        ->assertSeeHtml('data-fb-theme="class"');
});

it('shows attachment validation errors', function () {
    Storage::fake('local');
    $user = TestUser::create(['name' => 'L', 'email' => 'l@x.com']);

    Livewire::actingAs($user)->test(FeedbackForm::class)
        ->set('title', 'With a bad file')
        ->set('files', [UploadedFile::fake()->create('virus.exe', 10)])
        ->call('submit')
        ->assertHasErrors('files.0')
        ->assertSeeHtml('class="fb-error"');
});

it('formats a size limit in megabytes without a trailing zero', function (int $kb, string $size) {
    expect(\YousefAman\FeedbackBoard\Services\AttachmentStorage::megabytes($kb))->toBe($size);
})->with([[10240, '10'], [512, '0.5'], [1536, '1.5'], [1000, '1'], [102400, '100']]);

it('reads PHP ini size values in kilobytes', function (string $value, ?int $kb) {
    expect(\YousefAman\FeedbackBoard\Services\AttachmentStorage::iniSizeToKb($value))->toBe($kb);
})->with([['2M', 2048], ['512K', 512], ['1G', 1048576], ['2097152', 2048], ['8m', 8192], ['0', null], ['', null], ['abc', null]]);

it('never promises more than PHP accepts', function () {
    config()->set('feedback-board.attachments.max_kb', 10_000_000);
    $storage = app(\YousefAman\FeedbackBoard\Services\AttachmentStorage::class);
    $php = \YousefAman\FeedbackBoard\Services\AttachmentStorage::iniSizeToKb((string) ini_get('upload_max_filesize'));

    $livewire = 12288; // Livewire's default temporary-upload `max:` rule
    $expected = min(array_filter([10_000_000, $php, $livewire]));

    expect($storage->limits()['size'])->toBe(\YousefAman\FeedbackBoard\Services\AttachmentStorage::megabytes($expected));
});

it('moves focus to the sent panel after a successful submit', function () {
    Storage::fake('local');
    $user = TestUser::create(['name' => 'Lena', 'email' => 'l@x.com']);

    Livewire::actingAs($user)->test(FeedbackForm::class)
        ->set('title', 'Cannot export')
        ->set('type', FeedbackType::Bug->value)
        ->set('description', 'broken')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSeeHtml('role="status" tabindex="-1" x-init="$nextTick(() => $el.focus())"');
});

it('never promises more than Livewire accepts for temporary uploads', function () {
    config()->set('feedback-board.attachments.max_kb', 5120);
    config()->set('livewire.temporary_file_upload.rules', 'required|file|max:256');

    expect(app(\YousefAman\FeedbackBoard\Services\AttachmentStorage::class)->limits()['size'])->toBe('0.3');
});
