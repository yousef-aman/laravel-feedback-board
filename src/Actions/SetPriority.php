<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use YousefAman\FeedbackBoard\Enums\{ActivityAction, FeedbackPriority};
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class SetPriority
{
    /** Sets or clears (null) the priority and logs the change; an unchanged value writes nothing. */
    public function handle(FeedbackItem $item, ?FeedbackPriority $priority, ?Model $actor): FeedbackItem
    {
        $from = $item->priority;

        if ($from === $priority) {
            return $item;
        }

        return DB::transaction(function () use ($item, $from, $priority, $actor) {
            $item->priority = $priority;
            $item->save();

            $item->activities()->create([
                'actor_type' => $actor?->getMorphClass(),
                'actor_id' => $actor?->getKey(),
                'action' => ActivityAction::PriorityChanged,
                'properties' => ['from' => $from?->value, 'to' => $priority?->value],
            ]);

            return $item;
        });
    }
}
