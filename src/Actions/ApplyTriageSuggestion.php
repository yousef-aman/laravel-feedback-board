<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Actions;

use Illuminate\Database\Eloquent\Model;
use YousefAman\FeedbackBoard\Enums\FeedbackPriority;
use YousefAman\FeedbackBoard\Enums\FeedbackType;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy;

class ApplyTriageSuggestion
{
    /**
     * Apply a suggestion to canonical columns. Whitelists type/priority and only
     * applies values that pass tryFrom. Writes type/priority only — no ai_state —
     * so it is disjoint from concurrent triage writes (no lock needed).
     *
     * @param array<int, string> $fields  subset of ['type', 'priority']
     */
    public function handle(FeedbackItem $item, ?Model $actor, array $fields): void
    {
        abort_unless(FeedbackItemPolicy::isDeveloper($actor), 403);

        $fresh = FeedbackItem::findOrFail($item->id);
        $triage = $fresh->ai_state['triage'] ?? null;
        abort_unless(is_array($triage) && ($triage['status'] ?? null) === 'completed', 422);

        $update = [];
        foreach (array_intersect($fields, ['type', 'priority']) as $field) {
            $suggested = (string) ($triage["suggested_{$field}"] ?? '');
            $enum = $field === 'type'
                ? FeedbackType::tryFrom($suggested)
                : FeedbackPriority::tryFrom($suggested);
            if ($enum !== null) {
                $update[$field] = $enum;
            }
        }

        if ($update !== []) {
            $fresh->forceFill($update)->save();
        }
    }
}
