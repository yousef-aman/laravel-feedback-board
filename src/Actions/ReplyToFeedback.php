<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use YousefAman\FeedbackBoard\Enums\{ActivityAction, AuthorRole};
use YousefAman\FeedbackBoard\Events\FeedbackReplied;
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};

class ReplyToFeedback
{
    public function handle(FeedbackItem $item, string $body, ?Model $author, AuthorRole $role, bool $isInternal = false): FeedbackComment
    {
        return DB::transaction(function () use ($item, $body, $author, $role, $isInternal) {
            $comment = $item->comments()->create([
                'author_type' => $author?->getMorphClass(),
                'author_id' => $author?->getKey(),
                'author_role' => $role,
                'body' => $body,
                'is_internal' => $isInternal,
            ]);

            $item->activities()->create([
                'actor_type' => $author?->getMorphClass(),
                'actor_id' => $author?->getKey(),
                'action' => ActivityAction::Replied,
                'properties' => ['is_internal' => $isInternal],
            ]);

            FeedbackReplied::dispatch($comment);

            return $comment;
        });
    }
}
