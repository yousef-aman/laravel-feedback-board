<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Livewire;

use Livewire\Component;
use YousefAman\FeedbackBoard\Actions\TransitionStatus;
use YousefAman\FeedbackBoard\Enums\FeedbackStatus;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy;

class FeedbackKanban extends Component
{
    public ?int $selectedId = null;

    // Page access is enforced by the route's `can:viewAny` middleware (Step 4); each
    // write re-authorizes below. No mount() guard — that keeps the component mountable
    // in tests (Livewire re-throws auth exceptions raw on initial render) while the
    // real gate lives on the route + the per-action authorize('transition').

    public function open(int $id): void { $this->selectedId = $id; }

    public function close(): void { $this->selectedId = null; }

    public function moveTo(int $itemId, string $status, TransitionStatus $transition): void
    {
        $item = FeedbackItem::findOrFail($itemId);
        $this->authorize('transition', $item);
        $s = FeedbackStatus::tryFrom($status) ?? abort(422);
        $transition->handle($item, $s, auth()->user());
    }

    public function render()
    {
        // Defense-in-depth: if a non-developer somehow mounts this component outside the
        // gated route, return empty columns so item data does not leak. The per-action
        // authorize('transition') in moveTo() remains the primary write guard.
        if (! FeedbackItemPolicy::isDeveloper(auth()->user())) {
            return view('feedback-board::livewire.feedback-kanban', ['columns' => collect(), 'selected' => null]);
        }

        $columns = collect(FeedbackStatus::cases())->mapWithKeys(fn ($s) => [
            $s->value => FeedbackItem::where('status', $s->value)->latest()->get(),
        ]);
        $selected = $this->selectedId ? FeedbackItem::find($this->selectedId) : null;

        return view('feedback-board::livewire.feedback-kanban', ['columns' => $columns, 'selected' => $selected]);
    }
}
