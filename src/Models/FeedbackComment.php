<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany, MorphTo};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use YousefAman\FeedbackBoard\Database\Factories\FeedbackCommentFactory;
use YousefAman\FeedbackBoard\Enums\AuthorRole;

class FeedbackComment extends Model
{
    use HasFactory;

    protected $fillable = ['feedback_item_id', 'author_type', 'author_id', 'author_role', 'body', 'is_internal'];
    protected $casts = ['author_role' => AuthorRole::class, 'is_internal' => 'bool'];

    /** @var list<array{0: string, 1: string}> disk and path of each attachment deleted with this comment */
    protected array $removedFiles = [];

    public function item(): BelongsTo { return $this->belongsTo(FeedbackItem::class, 'feedback_item_id'); }
    public function author(): MorphTo { return $this->morphTo(); }
    public function attachments(): HasMany { return $this->hasMany(FeedbackAttachment::class); }

    protected static function booted(): void
    {
        // Without this the nullOnDelete foreign key would turn a deleted (possibly internal)
        // comment's images into report-level attachments.
        static::deleting(function (FeedbackComment $comment) {
            $comment->removedFiles = $comment->attachments()->get(['id', 'disk', 'path'])
                ->map(fn (FeedbackAttachment $attachment) => [$attachment->disk, $attachment->path])
                ->all();

            $comment->attachments()->delete();
        });

        // Disk deletes cannot roll back, so they wait for the commit.
        static::deleted(function (FeedbackComment $comment) {
            $files = $comment->removedFiles;

            DB::afterCommit(function () use ($files) {
                foreach ($files as [$disk, $path]) {
                    rescue(fn () => Storage::disk($disk)->delete($path), report: true);
                }
            });
        });
    }

    protected static function newFactory(): FeedbackCommentFactory { return FeedbackCommentFactory::new(); }
}
