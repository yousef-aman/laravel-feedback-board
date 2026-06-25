<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Services\AttachmentStorage;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    Storage::fake('local');
});

it('stores an allowed image on the private disk', function () {
    $item = FeedbackItem::factory()->create();
    $att = app(AttachmentStorage::class)->store(UploadedFile::fake()->image('shot.png'), $item);

    expect($att->feedback_item_id)->toBe($item->id)
        ->and($att->disk)->toBe('local');
    Storage::disk('local')->assertExists($att->path);
});

it('rejects a disallowed executable upload', function () {
    $item = FeedbackItem::factory()->create();
    app(AttachmentStorage::class)->store(UploadedFile::fake()->create('x.php', 10, 'application/x-php'), $item);
})->throws(ValidationException::class);

it('gates attachment downloads to authorized users', function () {
    config()->set('feedback-board.authorization.developer_resolver', fn ($u) => $u->name === 'Dev');
    Storage::fake('local');
    $client = TestUser::create(['name' => 'Client', 'email' => 'c@x.com']);
    $stranger = TestUser::create(['name' => 'Nope', 'email' => 'n@x.com']);
    $item = FeedbackItem::factory()->for($client, 'reporter')->create();
    $att = app(AttachmentStorage::class)->store(UploadedFile::fake()->image('s.png'), $item);

    $this->actingAs($stranger)->get(route('feedback-board.attachments.show', $att))->assertForbidden();
    $this->actingAs($client)->get(route('feedback-board.attachments.show', $att))->assertOk();
});
