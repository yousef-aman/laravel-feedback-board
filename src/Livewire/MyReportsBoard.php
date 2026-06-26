<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Livewire;

use Livewire\Attributes\On;
use Livewire\Component;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class MyReportsBoard extends Component
{
    public ?int $selectedId = null;

    #[On('feedback-submitted')]
    public function refreshList(): void { $this->selectedId = null; }

    public function open(int $id): void { $this->selectedId = $id; }

    public function render()
    {
        $user = auth()->user();
        $base = FeedbackItem::query() // scoped to the reporter — defense in depth on selection
            ->where('reporter_type', $user?->getMorphClass())
            ->where('reporter_id', $user?->getKey());

        $items = (clone $base)->latest()->get();
        $selected = $this->selectedId ? (clone $base)->whereKey($this->selectedId)->first() : null;

        return view('feedback-board::livewire.my-reports-board', ['items' => $items, 'selected' => $selected]);
    }
}
