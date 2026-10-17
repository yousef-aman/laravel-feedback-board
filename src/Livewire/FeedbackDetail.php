<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;
use YousefAman\FeedbackBoard\Actions\{ApplyTriageSuggestion, AssignFeedback, DispatchToRunner, ReplyToFeedback, RequestTriage, SendItemToGithub, SetPriority, TransitionStatus};
use YousefAman\FeedbackBoard\Destinations\Support\DeliveryError;
use YousefAman\FeedbackBoard\Enums\{ActivityAction, AuthorRole, FeedbackPriority, FeedbackStatus};
use YousefAman\FeedbackBoard\Facades\FeedbackBoard;
use YousefAman\FeedbackBoard\Livewire\Concerns\WithStagedUploads;
use YousefAman\FeedbackBoard\Models\{FeedbackActivity, FeedbackComment, FeedbackItem};
use YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy;
use YousefAman\FeedbackBoard\Services\AttachmentStorage;
use YousefAman\FeedbackBoard\Support\{Person, Time, Ui};

class FeedbackDetail extends Component
{
    use WithStagedUploads;

    public FeedbackItem $item;

    /** Shared by reply() and internalNote(): the composer's mode decides which one the form calls. */
    public string $body = '';

    #[Locked]
    public bool $imagesFailed = false;

    /** When "Send to GitHub" was pressed here: the board waits for the issue, or a newer failure. */
    #[Locked]
    public ?string $githubQueuedAt = null;

    public function mount(FeedbackItem $item): void
    {
        abort_unless(FeedbackItemPolicy::isDeveloper(FeedbackBoard::user()), 403); // hard enforcement
        $this->item = $item;
    }

    protected function guard(): void
    {
        abort_unless(FeedbackItemPolicy::isDeveloper(FeedbackBoard::user()), 403);
    }

    /** A reply the reporter sees and is notified about. */
    public function reply(ReplyToFeedback $reply, AttachmentStorage $storage): void
    {
        $this->comment($reply, $storage, internal: false);
    }

    /** A note only developers see; nobody is notified. */
    public function internalNote(ReplyToFeedback $reply, AttachmentStorage $storage): void
    {
        $this->comment($reply, $storage, internal: true);
    }

    public function assignToMe(AssignFeedback $assign): void
    {
        $this->guard();
        $assign->handle($this->item, FeedbackBoard::user(), FeedbackBoard::user());
        $this->changed();
    }

    public function unassign(AssignFeedback $assign): void
    {
        $this->guard();
        $assign->handle($this->item, null, FeedbackBoard::user());
        $this->changed();
    }

    public function setPriority(string $priority, SetPriority $action): void
    {
        $this->guard();
        $p = $priority === '' ? null : (FeedbackPriority::tryFrom($priority) ?? abort(422));
        $action->handle($this->item, $p, FeedbackBoard::user());
        $this->changed();
    }

    public function changeStatus(string $status, TransitionStatus $transition): void
    {
        $this->guard();
        $s = FeedbackStatus::tryFrom($status) ?? abort(422);
        $transition->handle($this->item, $s, FeedbackBoard::user());
        $this->changed();
    }

    public function sendToGithub(SendItemToGithub $action): void
    {
        $this->guard();
        $action->handle($this->item, FeedbackBoard::user());
        $this->githubQueuedAt = now()->toIso8601String();
        $this->dispatch('feedback-sent-to-github'); // optimistic UI hook
    }

    public function requestTriage(RequestTriage $action): void
    {
        $this->guard();
        $action->handle($this->item, FeedbackBoard::user());
        $this->item->refresh();
        $this->dispatch('feedback-triage-requested');
    }

    public function applyTriage(string $field, ApplyTriageSuggestion $action): void
    {
        $this->guard();
        $action->handle($this->item, FeedbackBoard::user(), [$field]);
        $this->changed();
    }

    public function dispatchToRunner(DispatchToRunner $action): void
    {
        $this->guard();
        $action->handle($this->item, FeedbackBoard::user());
        $this->item->refresh();
        $this->dispatch('feedback-runner-dispatched');
    }

    protected function uploadRules(): array
    {
        return app(AttachmentStorage::class)->imageRules();
    }

    public function render(): View
    {
        $cover = $this->item->coverAttachment;

        return view('feedback-board::livewire.feedback-detail', [
            'uid' => 'fb-'.$this->getId(),
            // The relation filters by MIME type; isImage() also checks the extension, as on the board.
            'cover' => $cover?->isImage() ? $cover : null,
            // Every attachment, comments' and internal notes' included: this view is developer-only.
            'gallery' => $this->item->attachments()->orderBy('id')->get(),
            'feed' => $this->feed(),
            'statuses' => FeedbackStatus::cases(),
            'priorities' => FeedbackPriority::cases(),
            'reporterName' => $this->item->reporterLabel(),
            'assigneeName' => $this->item->assignee_id !== null ? Person::name($this->item->assignee, $this->item->assignee_id) : null,
            'context' => $this->context(),
            'canAttach' => app(AttachmentStorage::class)->imageMimes() !== [],
            'canGithub' => $this->canSendToGithub(),
            'githubIssue' => $this->githubIssue(),
            'githubPending' => $pending = $this->githubPending(),
            'githubQueued' => $pending, // the name a card view published from a pre-release reads
            'deliveryProblems' => $this->deliveryProblems(skipGithub: $pending),
        ]);
    }

    private function comment(ReplyToFeedback $reply, AttachmentStorage $storage, bool $internal): void
    {
        $this->guard();
        $data = $this->validate($this->composerRules($storage), $this->composerMessages());

        $comment = $reply->handle($this->item, (string) ($data['body'] ?? ''), FeedbackBoard::user(), AuthorRole::Developer, isInternal: $internal);
        $this->imagesFailed = ! $this->storeStagedFiles($storage, $this->item, $comment);
        $this->reset('body');
        $this->changed();
    }

    /** Reloads the report and tells the board, which redraws its card. */
    private function changed(): void
    {
        $this->item->refresh();
        $this->dispatch('feedback-updated');
    }

    /**
     * Comments and activity lines, newest first. "Replied" lines are left out: the comment itself
     * is the record. Ties (same second) put comments first, then the higher id.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function feed(): Collection
    {
        $comments = $this->item->comments()->with(['attachments', 'author'])->get();
        $activities = $this->item->activities()->with('actor')->where('action', '!=', ActivityAction::Replied->value)->get();
        $assignees = FeedbackActivity::assigneeNames($activities);

        return $comments
            ->map(fn (FeedbackComment $comment) => [
                'kind' => 'comment',
                'model' => $comment,
                'name' => $this->commentName($comment),
                'at' => $comment->created_at?->getTimestamp() ?? 0,
                'rank' => 1,
                'id' => $comment->id,
            ])
            ->concat($activities->map(fn (FeedbackActivity $activity) => [
                'kind' => 'activity',
                'model' => $activity,
                'sentence' => $activity->sentence(self::isolate(Person::name($activity->actor, $activity->actor_id)), self::isolate($assignees[$activity->id] ?? null)),
                'at' => $activity->created_at?->getTimestamp() ?? 0,
                'rank' => 0,
                'id' => $activity->id,
            ]))
            ->sortBy([['at', 'desc'], ['rank', 'desc'], ['id', 'desc']])
            ->values();
    }

    /** The author's name; a guest reporter's comment uses the report's reporter, an AI comment its role. */
    private function commentName(FeedbackComment $comment): string
    {
        return Person::name($comment->author, $comment->author_id)
            ?? ($comment->author_role === AuthorRole::Reporter ? $this->item->reporterLabel() : null)
            ?? $comment->author_role->label();
    }

    /** Unicode isolates (FSI … PDI), the text form of <bdi>: a Latin name keeps its order inside an Arabic sentence. */
    private static function isolate(?string $name): ?string
    {
        return $name === null ? null : "\u{2068}{$name}\u{2069}";
    }

    /** @return array{number: int|string, url: string}|null the GitHub issue, when its stored address is http(s) */
    private function githubIssue(): ?array
    {
        $ref = ($this->item->external_refs ?? [])['github'] ?? null;
        $url = Ui::safeUrl($ref['html_url'] ?? null);
        $number = $ref['issue_number'] ?? null;

        return $url !== null && (is_int($number) || is_string($number)) ? ['number' => $number, 'url' => $url] : null;
    }

    /** Sent to GitHub from here, and neither the issue nor a failure recorded since has come back. */
    private function githubPending(): bool
    {
        $queuedAt = Time::parse($this->githubQueuedAt);

        if ($queuedAt === null || isset(($this->item->external_refs ?? [])['github'])) {
            return false;
        }

        $failedAt = Time::parse(($this->item->external_refs ?? [])['github_last_error']['at'] ?? null);

        return $failedAt === null || $failedAt->lt($queuedAt);
    }

    /**
     * The destinations whose last delivery failed, as DeliveryError::describe() words them. While a
     * GitHub send is pending, GitHub's earlier failure is left out: the new attempt may succeed.
     *
     * @return list<array{text: string, when: string, at: ?string}>
     */
    private function deliveryProblems(bool $skipGithub): array
    {
        $problems = [];

        foreach ($this->item->external_refs ?? [] as $key => $record) {
            if (! is_string($key) || ! str_ends_with($key, '_last_error')) {
                continue;
            }

            $driver = substr($key, 0, -strlen('_last_error'));

            if (($driver !== 'github' || ! $skipGithub) && ($problem = DeliveryError::describe($driver, $record)) !== null) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    /** The resource action's conditions: configured, and no issue yet. */
    private function canSendToGithub(): bool
    {
        return FeedbackItemPolicy::isDeveloper(FeedbackBoard::user())
            && (bool) config('feedback-board.destinations.github.token')
            && (bool) config('feedback-board.destinations.github.repo')
            && ! isset(($this->item->external_refs ?? [])['github']);
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
