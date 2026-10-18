<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** When a developer last read a report. A row with no report is their baseline: the first board visit. */
final class FeedbackRead extends Model
{
    protected $table = 'feedback_reads';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['read_at' => 'datetime'];

    public function item(): BelongsTo { return $this->belongsTo(FeedbackItem::class, 'feedback_item_id'); }
}
