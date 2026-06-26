<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedbackAttachment extends Model
{
    protected $fillable = ['feedback_item_id', 'feedback_comment_id', 'disk', 'path', 'original_name', 'mime', 'size'];

    public function item(): BelongsTo { return $this->belongsTo(FeedbackItem::class, 'feedback_item_id'); }
    public function comment(): BelongsTo { return $this->belongsTo(FeedbackComment::class, 'feedback_comment_id'); }
}
