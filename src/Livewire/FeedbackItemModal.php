<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Livewire\Component;
use YousefAman\FeedbackBoard\Actions\ReplyToFeedback;
use YousefAman\FeedbackBoard\Enums\{ActivityAction, AuthorRole, FeedbackStatus};
use YousefAman\FeedbackBoard\Livewire\Concerns\WithStagedUploads;
use YousefAman\FeedbackBoard\Models\{FeedbackAttachment, FeedbackComment, FeedbackItem};
use YousefAman\FeedbackBoard\Services\AttachmentStorage;

class FeedbackItemModal extends Component
{
    use WithStagedUploads;

    public FeedbackItem $item;
    public string $body = '';

    /** Replies after this moment are tagged "new"; MyReportsBoard sets it before marking the report read. */
    #[Locked]
    public ?string $readBefore = null;

    #[Locked]
    public string $titleId = '';

    #[Locked]
    public bool $imagesFailed = false;

    public function mount(FeedbackItem $item, ?string $readBefore = null, ?string $titleId = null): void
    {
        $this->item = $item;
        $this->readBefore = $readBefore;
        $this->titleId = $titleId ?? 'fb-'.$this->getId().'-title';
    }

    public function reply(ReplyToFeedback $reply, AttachmentStorage $storage): void
    {
        $this->authorize('reply', $this->item);
        $data = $this->validate($this->composerRules($storage), $this->composerMessages());

        $comment = $reply->handle($this->item, (string) ($data['body'] ?? ''), auth()->user(), AuthorRole::Reporter);
        $this->imagesFailed = ! $this->storeStagedFiles($storage, $this->item, $comment);
        $this->reset('body');

        // Replies that arrived while the reporter was reading are read now; readBefore stays, so
        // the "new" tags already shown remain.
        $this->item->markReadBy(auth()->user());
        $this->item->refresh();
    }

    protected function uploadRules(): array
    {
        return app(AttachmentStorage::class)->imageRules();
    }

    public function render(): View
    {
        $user = auth()->user();
        $readBefore = $this->readBefore !== null ? CarbonImmutable::parse($this->readBefore) : null;
        $own = $this->item->attachments()->whereNull('feedback_comment_id')->orderBy('id')->get();
        $terminal = in_array($this->item->status, [FeedbackStatus::Closed, FeedbackStatus::Declined], true);

        return view('feedback-board::livewire.feedback-item-modal', [
            'uid' => 'fb-'.$this->getId(),
            // The reporter view never shows internal notes, whoever is looking (developers included).
            'comments' => $this->item->comments()->where('is_internal', false)->with('attachments')->oldest()->orderBy('id')->get(),
            'images' => $own->filter(fn (FeedbackAttachment $a) => $a->isImage())->values(),
            'documents' => $own->reject(fn (FeedbackAttachment $a) => $a->isImage())->values(),
            'steps' => $this->steps(),
            'terminal' => $terminal,
            'terminalAt' => $terminal
                ? $this->item->activities()->where('action', ActivityAction::StatusChanged)->first()?->created_at
                : null,
            'canAttach' => app(AttachmentStorage::class)->imageMimes() !== [],
            'isMine' => fn (FeedbackComment $comment): bool => $user instanceof Model
                && $comment->author_type === $user->getMorphClass()
                && (string) $comment->author_id === (string) $user->getKey(),
            'isNew' => fn (FeedbackComment $comment): bool => $readBefore !== null
                && $comment->author_role !== AuthorRole::Reporter
                && $comment->created_at !== null
                && $comment->created_at->gt($readBefore),
        ]);
    }

    /** @return list<array{status: FeedbackStatus, done: bool, current: bool}> */
    private function steps(): array
    {
        $order = [FeedbackStatus::New, FeedbackStatus::InReview, FeedbackStatus::InProgress, FeedbackStatus::Resolved];
        $at = array_search($this->item->status, $order, true);

        return array_map(fn (FeedbackStatus $status, int $index) => [
            'status' => $status,
            'current' => $index === $at,
            'done' => $at !== false && ($index < $at || ($index === $at && $status === FeedbackStatus::Resolved)),
        ], $order, array_keys($order));
    }
}
