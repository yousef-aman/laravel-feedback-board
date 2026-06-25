<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, MorphTo};
use YousefAman\FeedbackBoard\Database\Factories\FeedbackCommentFactory;
use YousefAman\FeedbackBoard\Enums\AuthorRole;

class FeedbackComment extends Model
{
    use HasFactory;

    protected $fillable = ['feedback_item_id', 'author_type', 'author_id', 'author_role', 'body', 'is_internal'];
    protected $casts = ['author_role' => AuthorRole::class, 'is_internal' => 'bool'];

    public function item(): BelongsTo { return $this->belongsTo(FeedbackItem::class, 'feedback_item_id'); }
    public function author(): MorphTo { return $this->morphTo(); }

    protected static function newFactory(): FeedbackCommentFactory { return FeedbackCommentFactory::new(); }
}
