<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage};
use YousefAman\FeedbackBoard\Models\{FeedbackAttachment, FeedbackComment, FeedbackItem};
use YousefAman\FeedbackBoard\Services\AttachmentStorage;

beforeEach(fn () => Storage::fake('local'));

it('uses the report\'s first own allow-listed image as its cover', function () {
    $item = FeedbackItem::factory()->create();
    $storage = app(AttachmentStorage::class);
    $comment = FeedbackComment::factory()->for($item, 'item')->create();

    $storage->store(UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'), $item);
    $storage->store(UploadedFile::fake()->image('from-comment.png'), $item, $comment);
    $first = $storage->store(UploadedFile::fake()->image('first.png'), $item);
    $storage->store(UploadedFile::fake()->image('second.png'), $item);

    $loaded = FeedbackItem::with('coverAttachment')->find($item->id);

    expect($loaded->coverAttachment->id)->toBe($first->id)
        ->and($loaded->coverAttachment->isImage())->toBeTrue()
        ->and($loaded->coverAttachment->url())->toBe(route('feedback-board.attachments.show', $first));
});

it('has no cover when the report has only files, comment images or non-allow-listed images', function () {
    $item = FeedbackItem::factory()->create();
    $comment = FeedbackComment::factory()->for($item, 'item')->create();
    app(AttachmentStorage::class)->store(UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'), $item);
    app(AttachmentStorage::class)->store(UploadedFile::fake()->image('in-comment.png'), $item, $comment);
    FeedbackAttachment::create([
        'feedback_item_id' => $item->id, 'disk' => 'local', 'path' => 'x/logo.svg',
        'original_name' => 'logo.svg', 'mime' => 'image/svg+xml', 'size' => 10,
    ]);

    expect(FeedbackItem::with('coverAttachment')->find($item->id)->coverAttachment)->toBeNull();
});

it('deletes a comment\'s attachments and, after the commit, their files', function () {
    $item = FeedbackItem::factory()->create();
    $note = FeedbackComment::factory()->for($item, 'item')->create(['is_internal' => true]);
    $attachment = app(AttachmentStorage::class)->store(UploadedFile::fake()->image('note.png'), $item, $note);

    DB::transaction(fn () => $note->delete());

    expect(FeedbackAttachment::find($attachment->id))->toBeNull();
    Storage::disk('local')->assertMissing($attachment->path);
});

it('keeps the attachment and its file when the comment delete rolls back', function () {
    $item = FeedbackItem::factory()->create();
    $note = FeedbackComment::factory()->for($item, 'item')->create(['is_internal' => true]);
    $attachment = app(AttachmentStorage::class)->store(UploadedFile::fake()->image('note.png'), $item, $note);

    try {
        DB::transaction(function () use ($note) {
            $note->delete();

            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(FeedbackAttachment::find($attachment->id)?->feedback_comment_id)->toBe($note->id);
    Storage::disk('local')->assertExists($attachment->path);
});

it('splits statuses into open and done', function () {
    expect(array_map(fn ($s) => $s->value, \YousefAman\FeedbackBoard\Enums\FeedbackStatus::openCases()))->toBe(['new', 'in_review', 'in_progress'])
        ->and(\YousefAman\FeedbackBoard\Enums\FeedbackStatus::Resolved->isOpen())->toBeFalse()
        ->and(\YousefAman\FeedbackBoard\Enums\FeedbackStatus::InReview->isOpen())->toBeTrue();
});
