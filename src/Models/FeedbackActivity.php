<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, MorphTo};
use YousefAman\FeedbackBoard\Enums\{ActivityAction, FeedbackStatus};

class FeedbackActivity extends Model
{
    public $timestamps = false;
    protected $fillable = ['feedback_item_id', 'actor_type', 'actor_id', 'action', 'from_status', 'to_status', 'properties', 'created_at'];
    protected $casts = [
        'action' => ActivityAction::class,
        'from_status' => FeedbackStatus::class,
        'to_status' => FeedbackStatus::class,
        'properties' => 'array',
        'created_at' => 'datetime',
    ];

    public function item(): BelongsTo { return $this->belongsTo(FeedbackItem::class, 'feedback_item_id'); }
    public function actor(): MorphTo { return $this->morphTo(); }

    protected static function booted(): void
    {
        static::creating(fn ($a) => $a->created_at ??= now());
    }
}
