<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use YousefAman\FeedbackBoard\Enums\{ActivityAction, FeedbackStatus, FeedbackType};
use YousefAman\FeedbackBoard\Events\FeedbackItemCreated;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class CreateFeedbackItem
{
    public function handle(array $data, ?Model $reporter): FeedbackItem
    {
        $type = $data['type'] ?? FeedbackType::Bug;
        if (is_string($type)) {
            $type = FeedbackType::from($type);
        }

        return DB::transaction(function () use ($data, $reporter, $type) {
            $item = new FeedbackItem([
                'project' => config('feedback-board.project', 'default'),
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'type' => $type,
                'status' => FeedbackStatus::default(),
                'priority' => $data['priority'] ?? null,
                'meta' => $data['meta'] ?? [],
            ]);

            if ($reporter) {
                $item->reporter()->associate($reporter);
                $item->reporter_name = $reporter->name ?? null;
                $item->reporter_email = $reporter->email ?? null;
            }
            $item->save();

            $item->activities()->create([
                'actor_type' => $reporter?->getMorphClass(),
                'actor_id' => $reporter?->getKey(),
                'action' => ActivityAction::Created,
                'to_status' => FeedbackStatus::default(),
            ]);

            FeedbackItemCreated::dispatch($item);

            return $item;
        });
    }
}
