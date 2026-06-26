<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Livewire;

use Livewire\Component;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class MyReportsList extends Component
{
    public function render(): \Illuminate\Contracts\View\View
    {
        $items = FeedbackItem::query()
            ->where('reporter_type', auth()->user()?->getMorphClass())
            ->where('reporter_id', auth()->id())
            ->latest()
            ->get();

        return view('feedback-board::livewire.my-reports-list', ['items' => $items]);
    }
}
