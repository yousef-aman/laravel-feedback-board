<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use YousefAman\FeedbackBoard\Enums\{ActivityAction, FeedbackStatus};
use YousefAman\FeedbackBoard\Events\FeedbackStatusChanged;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class TransitionStatus
{
    public function handle(FeedbackItem $item, FeedbackStatus $to, ?Model $actor): FeedbackItem
    {
        $from = $item->status;
        if ($from === $to) {
            return $item;
        }

        return DB::transaction(function () use ($item, $from, $to, $actor) {
            $item->status = $to;
            $item->resolved_at = $to === FeedbackStatus::Resolved ? now() : ($to->isTerminal() ? $item->resolved_at : null);
            $item->reporter_activity_at = $item->freshTimestamp();
            $item->save();

            $item->activities()->create([
                'actor_type' => $actor?->getMorphClass(),
                'actor_id' => $actor?->getKey(),
                'action' => ActivityAction::StatusChanged,
                'from_status' => $from,
                'to_status' => $to,
            ]);

            FeedbackStatusChanged::dispatch($item, $from, $to, $actor);

            return $item;
        });
    }
}
