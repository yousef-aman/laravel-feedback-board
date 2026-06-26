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
