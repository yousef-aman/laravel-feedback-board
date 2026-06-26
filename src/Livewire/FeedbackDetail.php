<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Livewire;

use Livewire\Component;
use YousefAman\FeedbackBoard\Actions\AssignFeedback;
use YousefAman\FeedbackBoard\Actions\ReplyToFeedback;
use YousefAman\FeedbackBoard\Actions\TransitionStatus;
use YousefAman\FeedbackBoard\Enums\AuthorRole;
use YousefAman\FeedbackBoard\Enums\FeedbackPriority;
use YousefAman\FeedbackBoard\Enums\FeedbackStatus;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy;

class FeedbackDetail extends Component
{
    public FeedbackItem $item;
    public string $body = '';
    public string $note = '';

    public function mount(FeedbackItem $item): void
    {
        abort_unless(FeedbackItemPolicy::isDeveloper(auth()->user()), 403); // hard enforcement
        $this->item = $item;
    }

    protected function guard(): void
    {
        abort_unless(FeedbackItemPolicy::isDeveloper(auth()->user()), 403);
    }

    public function reply(ReplyToFeedback $reply): void
    {
        $this->guard();
        $this->validate(['body' => ['required', 'string']]);
        $reply->handle($this->item, $this->body, auth()->user(), AuthorRole::Developer);
        $this->reset('body');
        $this->item->refresh();
    }

    public function internalNote(ReplyToFeedback $reply): void
    {
        $this->guard();
        $this->validate(['note' => ['required', 'string']]);
        $reply->handle($this->item, $this->note, auth()->user(), AuthorRole::Developer, isInternal: true);
        $this->reset('note');
        $this->item->refresh();
    }

    public function assignToMe(AssignFeedback $assign): void
    {
        $this->guard();
        $assign->handle($this->item, auth()->user(), auth()->user());
        $this->item->refresh();
    }

    public function unassign(AssignFeedback $assign): void
    {
        $this->guard();
        $assign->handle($this->item, null, auth()->user());
        $this->item->refresh();
    }

    public function setPriority(string $priority): void
    {
        $this->guard();
        $this->item->update(['priority' => FeedbackPriority::from($priority)]);
        $this->item->refresh();
    }

    public function changeStatus(string $status, TransitionStatus $transition): void
    {
        $this->guard();
        $transition->handle($this->item, FeedbackStatus::from($status), auth()->user());
        $this->item->refresh();
    }

    public function render()
    {
        return view('feedback-board::livewire.feedback-detail', [
            'comments' => $this->item->comments()->oldest()->get(), // developer sees internal too
            'activities' => $this->item->activities, // latest-first (relation default)
            'attachments' => $this->item->attachments,
            'statuses' => FeedbackStatus::cases(),
            'priorities' => FeedbackPriority::cases(),
        ]);
    }
}
