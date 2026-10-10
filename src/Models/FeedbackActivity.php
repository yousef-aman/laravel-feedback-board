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

    /** One-line text for the item timeline. */
    public function summary(): string
    {
        $properties = (array) $this->properties;

        return match (true) {
            $this->action === ActivityAction::StatusChanged && $this->to_status !== null
                => $this->action->label().': '.$this->to_status->label(),
            // AssignFeedback records an unassignment as Assigned with assignee_id = null.
            $this->action === ActivityAction::Assigned
                && array_key_exists('assignee_id', $properties) && $properties['assignee_id'] === null
                => __('feedback-board::feedback-board.activity.unassigned'),
            default => $this->action->label(),
        };
    }

    protected static function booted(): void
    {
        static::creating(fn ($a) => $a->created_at ??= now());
    }
}
