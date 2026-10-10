<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{HasMany, HasOne, MorphTo};
use YousefAman\FeedbackBoard\Database\Factories\FeedbackItemFactory;
use YousefAman\FeedbackBoard\Enums\{AuthorRole, FeedbackPriority, FeedbackStatus, FeedbackType};
use YousefAman\FeedbackBoard\Support\Images;

class FeedbackItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'project', 'reporter_type', 'reporter_id', 'reporter_name', 'reporter_email',
        'title', 'description', 'type', 'status', 'priority',
        'assignee_type', 'assignee_id', 'meta', 'resolved_at',
    ];

    protected $casts = [
        'type' => FeedbackType::class,
        'status' => FeedbackStatus::class,
        'priority' => FeedbackPriority::class,
        'meta' => 'array', 'external_refs' => 'array', 'ai_state' => 'array',
        'resolved_at' => 'datetime',
        'reporter_read_at' => 'datetime',
        'reporter_activity_at' => 'datetime',
    ];

    public function reporter(): MorphTo { return $this->morphTo(); }
    public function assignee(): MorphTo { return $this->morphTo(); }
    public function comments(): HasMany { return $this->hasMany(FeedbackComment::class); }
    public function attachments(): HasMany { return $this->hasMany(FeedbackAttachment::class); }
    public function activities(): HasMany { return $this->hasMany(FeedbackActivity::class)->latest('created_at'); }

    /** The report's first own allow-listed image (never a comment's): the Kanban cover. */
    public function coverAttachment(): HasOne
    {
        return $this->hasOne(FeedbackAttachment::class)->ofMany(['id' => 'min'], function (Builder $query) {
            $query->whereNull('feedback_comment_id')->whereIn('mime', Images::mimes());
        });
    }

    public function scopeOwnedBy(Builder $query, mixed $user): Builder
    {
        if (! $user instanceof Model) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('reporter_type', $user->getMorphClass())->where('reporter_id', $user->getKey());
    }

    public function scopeWithUnreadCount(Builder $query): Builder
    {
        return $query->withCount(['comments as unread_count' => fn (Builder $comments) => static::unreadComments($comments)]);
    }

    public function scopeHasUnread(Builder $query): Builder
    {
        return $query->whereHas('comments', fn (Builder $comments) => static::unreadComments($comments));
    }

    /** Newest reporter-visible activity first; a NULL falls back to creation on every database. */
    public function scopeLatestForReporter(Builder $query): Builder
    {
        $grammar = $query->getQuery()->getGrammar();
        $table = $this->getTable();

        return $query
            ->orderByRaw('coalesce('.$grammar->wrap($table.'.reporter_activity_at').', '.$grammar->wrap($table.'.created_at').') desc')
            ->orderByDesc($table.'.id');
    }

    public function isReportedBy(mixed $user): bool
    {
        return $user instanceof Model
            && $this->reporter_type === $user->getMorphClass()
            && (string) $this->reporter_id === (string) $user->getKey();
    }

    /** Records that the reporter has seen the replies so far. Leaves updated_at alone. */
    public function markReadBy(mixed $user): bool
    {
        if (! $this->isReportedBy($user)) {
            return false;
        }

        $now = $this->freshTimestamp();
        static::query()->whereKey($this->getKey())->toBase()->update(['reporter_read_at' => $now]);
        $this->forceFill(['reporter_read_at' => $now])->syncOriginalAttribute('reporter_read_at');

        return true;
    }

    /** Replies after this moment are unread: the last read, or the report's creation. */
    public function readMarker(): ?CarbonInterface
    {
        return $this->reporter_read_at ?? $this->created_at;
    }

    /** The "last update" the reporter sees. */
    public function reporterActivity(): ?CarbonInterface
    {
        return $this->reporter_activity_at ?? $this->created_at;
    }

    /**
     * Non-internal comments by anyone but the reporter (developer and AI replies, which also
     * e-mail the reporter), newer than the reporter's last read. Second precision; a reply in
     * the same second as the read is treated as read.
     */
    protected static function unreadComments(Builder $comments): void
    {
        $grammar = $comments->getQuery()->getGrammar();
        $items = (new static)->getTable();
        $table = $comments->getModel()->getTable();

        $comments->where($table.'.is_internal', false)
            ->where($table.'.author_role', '!=', AuthorRole::Reporter->value)
            ->whereRaw(
                $grammar->wrap($table.'.created_at').' > coalesce('
                .$grammar->wrap($items.'.reporter_read_at').', '
                .$grammar->wrap($items.'.created_at').')'
            );
    }

    protected static function booted(): void
    {
        // Covers factories and host code too: the reporter's "last update" starts at creation.
        static::creating(fn (FeedbackItem $item) => $item->reporter_activity_at ??= $item->freshTimestamp());
    }

    protected static function newFactory(): FeedbackItemFactory { return FeedbackItemFactory::new(); }
}
