<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use YousefAman\FeedbackBoard\Enums\{ActivityAction, FeedbackPriority, FeedbackType};
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy;

class ApplyTriageSuggestion
{
    public function __construct(private SetPriority $priority = new SetPriority()) {}

    /**
     * Apply a suggestion to canonical columns. Whitelists type/priority, only applies values that
     * pass tryFrom, and logs each change. Writes type/priority and their activity rows only — no
     * ai_state — so it is disjoint from concurrent triage writes (no lock needed).
     *
     * @param array<int, string> $fields  subset of ['type', 'priority']
     */
    public function handle(FeedbackItem $item, ?Model $actor, array $fields): void
    {
        abort_unless(FeedbackItemPolicy::isDeveloper($actor), 403);

        $fresh = FeedbackItem::findOrFail($item->id);
        $triage = $fresh->ai_state['triage'] ?? null;
        abort_unless(is_array($triage) && ($triage['status'] ?? null) === 'completed', 422);

        if (in_array('type', $fields, true)
            && ($type = FeedbackType::tryFrom((string) ($triage['suggested_type'] ?? ''))) !== null) {
            $this->setType($fresh, $type, $actor);
        }

        if (in_array('priority', $fields, true)
            && ($priority = FeedbackPriority::tryFrom((string) ($triage['suggested_priority'] ?? ''))) !== null) {
            $this->priority->handle($fresh, $priority, $actor);
        }
    }

    private function setType(FeedbackItem $item, FeedbackType $type, ?Model $actor): void
    {
        $from = $item->type;

        if ($from === $type) {
            return;
        }

        DB::transaction(function () use ($item, $from, $type, $actor) {
            $item->type = $type;
            $item->save();

            $item->activities()->create([
                'actor_type' => $actor?->getMorphClass(),
                'actor_id' => $actor?->getKey(),
                'action' => ActivityAction::TypeChanged,
                'properties' => ['from' => $from?->value, 'to' => $type->value],
            ]);
        });
    }
}
