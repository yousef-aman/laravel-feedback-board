<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Livewire;

use Livewire\Component;
use YousefAman\FeedbackBoard\Actions\ReplyToFeedback;
use YousefAman\FeedbackBoard\Enums\AuthorRole;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class ReplyInline extends Component
{
    public FeedbackItem $item;
    public string $body = '';

    public function reply(ReplyToFeedback $action): void
    {
        $this->authorize('reply', $this->item);

        $this->validate(['body' => ['required', 'string', 'max:5000']]);

        $action->handle($this->item, $this->body, auth()->user(), AuthorRole::Reporter);

        $this->reset('body');
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('feedback-board::livewire.reply-inline', ['item' => $this->item]);
    }
}
