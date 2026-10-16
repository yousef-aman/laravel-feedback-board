<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage};
use Livewire\Livewire;
use YousefAman\FeedbackBoard\Enums\AuthorRole;
use YousefAman\FeedbackBoard\Facades\FeedbackBoard;
use YousefAman\FeedbackBoard\Livewire\FeedbackItemModal;
use YousefAman\FeedbackBoard\Models\{FeedbackAttachment, FeedbackComment, FeedbackItem};
use YousefAman\FeedbackBoard\Services\AttachmentStorage;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    Storage::fake('local');
    FeedbackBoard::resolveDevelopersUsing(fn ($user) => $user->name === 'Dev');
    $this->client = TestUser::create(['name' => 'Client', 'email' => 'c@x.com']);
    $this->dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $this->item = FeedbackItem::factory()->for($this->client, 'reporter')->create();
    $this->note = FeedbackComment::factory()->for($this->item, 'item')->create([
        'is_internal' => true, 'author_role' => AuthorRole::Developer,
        'author_type' => $this->dev->getMorphClass(), 'author_id' => $this->dev->getKey(),
    ]);
    $this->secret = app(AttachmentStorage::class)->store(UploadedFile::fake()->image('secret.png'), $this->item, $this->note);
});

it("removes an internal note's attachments when the note is deleted without model events", function () {
    DB::statement('PRAGMA foreign_keys = ON');
    // The migrations roll back alphabetically, dropping feedback_items before feedback_comments,
    // which fails while foreign keys are on.
    $this->beforeApplicationDestroyed(fn () => DB::statement('PRAGMA foreign_keys = OFF'));

    FeedbackComment::query()->where('author_id', $this->dev->getKey())->delete(); // a mass delete: no model events

    expect(FeedbackAttachment::find($this->secret->id))->toBeNull();
    $this->actingAs($this->client)->get(route('feedback-board.attachments.show', $this->secret->id))->assertNotFound();
    Livewire::actingAs($this->client)->test(FeedbackItemModal::class, ['item' => $this->item->fresh()])
        ->assertDontSee('secret.png');
});

it('serves an attachment whose comment is missing to developers only', function () {
    // Foreign keys are off on the test connection, so the row keeps a dangling feedback_comment_id.
    FeedbackComment::query()->whereKey($this->note->id)->delete();

    expect(FeedbackAttachment::find($this->secret->id)->feedback_comment_id)->toBe($this->note->id);
    $this->actingAs($this->client)->get(route('feedback-board.attachments.show', $this->secret->id))->assertForbidden();

    $this->app['auth']->forgetGuards();
    $this->actingAs($this->dev)->get(route('feedback-board.attachments.show', $this->secret->id))->assertOk();
});
