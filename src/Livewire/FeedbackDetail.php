<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Livewire;

use Livewire\Component;
use YousefAman\FeedbackBoard\Actions\AssignFeedback;
use YousefAman\FeedbackBoard\Actions\ReplyToFeedback;
use YousefAman\FeedbackBoard\Actions\SetPriority;
use YousefAman\FeedbackBoard\Actions\TransitionStatus;
use YousefAman\FeedbackBoard\Enums\AuthorRole;
use YousefAman\FeedbackBoard\Enums\FeedbackPriority;
use YousefAman\FeedbackBoard\Enums\FeedbackStatus;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy;
use YousefAman\FeedbackBoard\Support\Ui;

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

    public function setPriority(string $priority, SetPriority $action): void
    {
        $this->guard();
        $p = $priority === '' ? null : (FeedbackPriority::tryFrom($priority) ?? abort(422));
        $action->handle($this->item, $p, auth()->user());
        $this->item->refresh();
    }

    public function changeStatus(string $status, TransitionStatus $transition): void
    {
        $this->guard();
        $s = FeedbackStatus::tryFrom($status) ?? abort(422);
        $transition->handle($this->item, $s, auth()->user());
        $this->item->refresh();
    }

    public function sendToGithub(\YousefAman\FeedbackBoard\Actions\SendItemToGithub $action): void
    {
        $action->handle($this->item, auth()->user());
        $this->dispatch('feedback-sent-to-github'); // optimistic UI hook
    }

    public function requestTriage(\YousefAman\FeedbackBoard\Actions\RequestTriage $action): void
    {
        $this->guard();
        $action->handle($this->item, auth()->user());
        $this->item->refresh();
        $this->dispatch('feedback-triage-requested');
    }

    public function applyTriage(string $field, \YousefAman\FeedbackBoard\Actions\ApplyTriageSuggestion $action): void
    {
        $this->guard();
        $action->handle($this->item, auth()->user(), [$field]);
        $this->item->refresh();
    }

    public function dispatchToRunner(\YousefAman\FeedbackBoard\Actions\DispatchToRunner $action): void
    {
        $this->guard();
        $action->handle($this->item, auth()->user());
        $this->item->refresh();
        $this->dispatch('feedback-runner-dispatched');
    }

    public function render()
    {
        return view('feedback-board::livewire.feedback-detail', [
            'comments' => $this->item->comments()->oldest()->get(), // developer sees internal too
            'activities' => $this->item->activities, // latest-first (relation default)
            'attachments' => $this->item->attachments,
            'statuses' => FeedbackStatus::cases(),
            'priorities' => FeedbackPriority::cases(),
            'reporterName' => $this->item->reporter_name ?: ($this->item->reporter_email ?: null),
            'assigneeName' => $this->assigneeName(),
            'context' => $this->context(),
        ]);
    }

    private function assigneeName(): ?string
    {
        if (! $this->item->assignee_id) {
            return null;
        }

        $name = data_get($this->item->assignee, 'name') ?: data_get($this->item->assignee, 'email');

        return is_string($name) && $name !== '' ? $name : '#'.$this->item->assignee_id;
    }

    /**
     * Captured request context (page URL, browser, locale). The URL comes from the Referer header
     * of the reporter's request, so it only becomes a link when it is http(s).
     *
     * @return list<array{label: string, value: string, href: ?string}>
     */
    private function context(): array
    {
        $meta = is_array($this->item->meta) ? $this->item->meta : [];
        $rows = [];

        foreach (['url' => 'page_url', 'user_agent' => 'browser', 'locale' => 'locale'] as $key => $label) {
            $value = $meta[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $rows[] = [
                'label' => __('feedback-board::feedback-board.board.'.$label),
                'value' => $value,
                'href' => $key === 'url' ? Ui::safeUrl($value) : null,
            ];
        }

        return $rows;
    }
}
