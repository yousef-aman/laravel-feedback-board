<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, MorphTo, Relation};
use YousefAman\FeedbackBoard\Enums\{ActivityAction, FeedbackPriority, FeedbackStatus, FeedbackType};
use YousefAman\FeedbackBoard\Support\Person;

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

    /** One-line text for the item timeline. Kept as public API for host code; the package's views use sentence(). */
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

    /**
     * A full line for the activity feed. Names are resolved (and eager-loaded) by the caller;
     * a missing actor reads as "Someone".
     */
    public function sentence(?string $actor, ?string $assignee = null): string
    {
        $key = 'feedback-board::feedback-board.activity.sentence.';
        $actor = filled($actor) ? $actor : __('feedback-board::feedback-board.activity.someone');
        $properties = (array) $this->properties;
        $to = $properties['to'] ?? null;

        return match ($this->action) {
            ActivityAction::Created => __($key.'created', ['actor' => $actor]),
            ActivityAction::StatusChanged => $this->from_status !== null
                ? __($key.'status_changed', ['actor' => $actor, 'from' => $this->from_status->label(), 'to' => $this->to_status?->label() ?? ''])
                : __($key.'status_set', ['actor' => $actor, 'to' => $this->to_status?->label() ?? '']),
            ActivityAction::Assigned => array_key_exists('assignee_id', $properties) && $properties['assignee_id'] === null
                ? __($key.'unassigned', ['actor' => $actor])
                : __($key.'assigned', ['actor' => $actor, 'assignee' => filled($assignee)
                    ? $assignee
                    : (isset($properties['assignee_id']) ? '#'.$properties['assignee_id'] : __('feedback-board::feedback-board.activity.someone'))]),
            ActivityAction::PriorityChanged => ($priority = FeedbackPriority::tryFrom((string) $to)) !== null
                ? __($key.'priority_changed', ['actor' => $actor, 'to' => $priority->label()])
                : __($key.'priority_cleared', ['actor' => $actor]),
            ActivityAction::TypeChanged => __($key.'type_changed', ['actor' => $actor, 'to' => FeedbackType::tryFrom((string) $to)?->label() ?? (string) $to]),
            ActivityAction::Replied => __($key.'replied', ['actor' => $actor]),
        };
    }

    /**
     * Assignee names for a page of activities, one query per assignee model type. Rows written
     * before assignee_type was stored resolve only when the actor assigned themselves ("Assign to
     * me" was the only way to assign then); otherwise they read "#id".
     *
     * @param  iterable<FeedbackActivity>  $activities  with `actor` loaded
     * @return array<int, string>  activity id => name
     */
    public static function assigneeNames(iterable $activities): array
    {
        $assignments = [];

        foreach ($activities as $activity) {
            $properties = (array) $activity->properties;

            if ($activity->action === ActivityAction::Assigned && ($properties['assignee_id'] ?? null) !== null) {
                $assignments[] = [$activity, $properties['assignee_id'], $properties['assignee_type'] ?? null];
            }
        }

        $models = [];

        foreach (collect($assignments)->filter(fn ($row) => is_string($row[2]) && $row[2] !== '')->groupBy(fn ($row) => $row[2]) as $type => $rows) {
            $class = Relation::getMorphedModel($type) ?? $type;

            if (! is_subclass_of($class, Model::class)) {
                continue;
            }

            foreach ($class::query()->whereKey($rows->pluck(1)->unique()->values()->all())->get() as $model) {
                $models[$type][(string) $model->getKey()] = $model;
            }
        }

        $names = [];

        foreach ($assignments as [$activity, $id, $type]) {
            $model = is_string($type) ? ($models[$type][(string) $id] ?? null) : null;

            if ($model === null && $type === null && $activity->actor instanceof Model && (string) $activity->actor_id === (string) $id) {
                $model = $activity->actor;
            }

            $names[$activity->id] = Person::name($model) ?? '#'.$id;
        }

        return $names;
    }

    protected static function booted(): void
    {
        static::creating(fn ($a) => $a->created_at ??= now());
    }
}
