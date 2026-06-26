<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use YousefAman\FeedbackBoard\Enums\ActivityAction;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class AssignFeedback
{
    public function handle(FeedbackItem $item, ?Model $assignee, ?Model $actor): FeedbackItem
    {
        return DB::transaction(function () use ($item, $assignee, $actor) {
            $assignee ? $item->assignee()->associate($assignee) : $item->assignee()->dissociate();
            $item->save();

            $item->activities()->create([
                'actor_type' => $actor?->getMorphClass(),
                'actor_id' => $actor?->getKey(),
                'action' => ActivityAction::Assigned,
                'properties' => ['assignee_id' => $assignee?->getKey()],
            ]);

            return $item;
        });
    }
}
