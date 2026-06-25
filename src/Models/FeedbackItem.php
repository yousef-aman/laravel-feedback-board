<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{HasMany, MorphTo};
use YousefAman\FeedbackBoard\Database\Factories\FeedbackItemFactory;
use YousefAman\FeedbackBoard\Enums\{FeedbackPriority, FeedbackStatus, FeedbackType};

class FeedbackItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'project', 'reporter_type', 'reporter_id', 'reporter_name', 'reporter_email',
        'title', 'description', 'type', 'status', 'priority',
        'assignee_type', 'assignee_id', 'meta', 'external_refs', 'ai_state', 'resolved_at',
    ];

    protected $casts = [
        'type' => FeedbackType::class,
        'status' => FeedbackStatus::class,
        'priority' => FeedbackPriority::class,
        'meta' => 'array', 'external_refs' => 'array', 'ai_state' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function reporter(): MorphTo { return $this->morphTo(); }
    public function assignee(): MorphTo { return $this->morphTo(); }
    public function comments(): HasMany { return $this->hasMany(FeedbackComment::class); }
    public function attachments(): HasMany { return $this->hasMany(FeedbackAttachment::class); }
    public function activities(): HasMany { return $this->hasMany(FeedbackActivity::class)->latest('created_at'); }

    protected static function newFactory(): FeedbackItemFactory { return FeedbackItemFactory::new(); }
}
