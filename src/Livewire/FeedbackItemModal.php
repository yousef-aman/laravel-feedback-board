<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Livewire;

use Livewire\Component;
use YousefAman\FeedbackBoard\Actions\ReplyToFeedback;
use YousefAman\FeedbackBoard\Enums\AuthorRole;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class FeedbackItemModal extends Component
{
    public FeedbackItem $item;
    public string $body = '';

    public function mount(FeedbackItem $item): void
    {
        $this->item = $item;
    }

    public function reply(ReplyToFeedback $reply): void
    {
        $this->authorize('reply', $this->item);
        $this->validate(['body' => ['required', 'string']]);

        $reply->handle($this->item, $this->body, auth()->user(), AuthorRole::Reporter);
        $this->reset('body');
        $this->item->refresh();
    }

    public function render()
    {
        $comments = $this->item->comments()
            ->when(! \YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy::isDeveloper(auth()->user()),
                fn ($q) => $q->where('is_internal', false))
            ->oldest()->get();

        return view('feedback-board::livewire.feedback-item-modal', ['comments' => $comments]);
    }
}
