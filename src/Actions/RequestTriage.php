<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Actions;

use Illuminate\Database\Eloquent\Model;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy;
use YousefAman\FeedbackBoard\Triage\Support\TriageDispatcher;
use YousefAman\FeedbackBoard\Triage\Support\TriageGate;

class RequestTriage
{
    public function handle(FeedbackItem $item, ?Model $actor): void
    {
        abort_unless(FeedbackItemPolicy::isDeveloper($actor), 403);
        abort_unless(TriageGate::configured(), 422);

        TriageDispatcher::dispatch($item, locked: true);
    }
}
