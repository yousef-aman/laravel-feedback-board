<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use YousefAman\FeedbackBoard\Enums\AuthorRole;
use YousefAman\FeedbackBoard\Models\{FeedbackAttachment, FeedbackComment};
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

function fbClientWithItem(): array
{
    config()->set('feedback-board.authorization.developer_resolver', fn ($u) => $u->name === 'Dev');
    $client = TestUser::create(['name' => 'Client', 'email' => 'c@x.com']);

    return [$client, FeedbackItem::factory()->for($client, 'reporter')->create()];
}

it('serves allow-listed images inline under a locked-down policy', function () {
    [$client, $item] = fbClientWithItem();
    $attachment = app(AttachmentStorage::class)->store(UploadedFile::fake()->image('shot.png'), $item);

    $response = $this->actingAs($client)->get(route('feedback-board.attachments.show', $attachment))->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox")
        ->and($response->headers->get('Cache-Control'))->toContain('private')->toContain('max-age=3600');
});

it('downloads SVG, PDF and type/extension mismatches instead of rendering them', function (string $name, string $mime) {
    [$client, $item] = fbClientWithItem();
    $attachment = FeedbackAttachment::create([
        'feedback_item_id' => $item->id, 'disk' => 'local', 'path' => 'feedback-board/'.$item->id.'/'.$name,
        'original_name' => $name, 'mime' => $mime, 'size' => 3,
    ]);
    Storage::disk('local')->put($attachment->path, 'abc');

    $response = $this->actingAs($client)->get(route('feedback-board.attachments.show', $attachment))->assertOk();

    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment');
})->with([
    ['logo.svg', 'image/svg+xml'],
    ['spec.pdf', 'application/pdf'],
    ['photo.png', 'image/jpeg'],
    ['page.png', 'text/html'],
]);

it('returns 404 when the file is gone from the disk', function () {
    [$client, $item] = fbClientWithItem();
    $attachment = app(AttachmentStorage::class)->store(UploadedFile::fake()->image('shot.png'), $item);
    Storage::disk('local')->delete($attachment->path);

    $this->actingAs($client)->get(route('feedback-board.attachments.show', $attachment))->assertNotFound();
});

it('serves an internal note\'s images to developers only', function () {
    [$client, $item] = fbClientWithItem();
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $note = FeedbackComment::factory()->for($item, 'item')->create(['is_internal' => true, 'author_role' => AuthorRole::Developer]);
    $reply = FeedbackComment::factory()->for($item, 'item')->create(['is_internal' => false, 'author_role' => AuthorRole::Developer]);
    $secret = app(AttachmentStorage::class)->store(UploadedFile::fake()->image('secret.png'), $item, $note);
    $public = app(AttachmentStorage::class)->store(UploadedFile::fake()->image('public.png'), $item, $reply);

    $this->actingAs($client)->get(route('feedback-board.attachments.show', $secret))->assertForbidden();
    $this->actingAs($client)->get(route('feedback-board.attachments.show', $public))->assertOk();
    $this->actingAs($dev)->get(route('feedback-board.attachments.show', $secret))->assertOk();
});
