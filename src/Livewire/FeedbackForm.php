<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use YousefAman\FeedbackBoard\Actions\CreateFeedbackItem;
use YousefAman\FeedbackBoard\Enums\FeedbackType;
use YousefAman\FeedbackBoard\Livewire\Concerns\WithStagedUploads;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Services\AttachmentStorage;
use YousefAman\FeedbackBoard\Support\Ui;

class FeedbackForm extends Component
{
    use WithStagedUploads;

    public string $title = '';
    public string $description = '';
    public string $type = 'bug';
    public bool $sent = false;

    /** The reference shown once the report exists. */
    #[Locked]
    public ?int $sentId = null;

    /** "My reports" (in the current Filament panel when there is one), fixed at mount. */
    #[Locked]
    public string $reportsUrl = '';

    /** The page the form was opened on, for the context notice. */
    #[Locked]
    public string $pageUrl = '';

    #[Locked]
    public bool $attachmentsFailed = false;

    /** The heading id the surrounding dialog is labelled by; empty derives it from the component id. */
    #[Locked]
    public string $titleId = '';

    public function mount(string $titleId = ''): void
    {
        $this->titleId = $titleId;
        $this->reportsUrl = Ui::reportsUrl();
        $this->pageUrl = url()->current();
    }

    public function submit(CreateFeedbackItem $create, AttachmentStorage $storage): void
    {
        $this->authorize('create', FeedbackItem::class);
        $this->sent = false;

        $data = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['required', 'in:bug,feature,question,other'],
            'files' => ['array', 'max:'.$storage->maxFiles()],
            'files.*' => $storage->allowedRules(),
        ]);

        $item = $create->handle([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'type' => FeedbackType::from($data['type']),
            'meta' => [
                'url' => request()->headers->get('referer'),
                'user_agent' => request()->userAgent(),
                'locale' => app()->getLocale(),
            ],
        ], auth()->user());

        $this->attachmentsFailed = ! $this->storeStagedFiles($storage, $item);

        $this->reset('title', 'description', 'type');
        $this->resetErrorBag();
        $this->sentId = $item->id;
        $this->sent = true;
        $this->dispatch('feedback-submitted', id: $item->id);
    }

    protected function uploadRules(): array
    {
        return app(AttachmentStorage::class)->allowedRules();
    }

    public function render(): View
    {
        $storage = app(AttachmentStorage::class);
        $limits = $storage->limits();

        return view('feedback-board::livewire.feedback-form', [
            'uid' => 'fb-'.$this->getId(),
            'titleId' => $this->titleId !== '' ? $this->titleId : 'fb-'.$this->getId().'-title',
            'types' => FeedbackType::cases(),
            'attachmentTypes' => $limits['types'],
            'attachmentSize' => $limits['size'],
            'maxFiles' => $storage->maxFiles(),
            'followUrl' => $this->sentId === null ? $this->reportsUrl : Ui::reportLink($this->reportsUrl, $this->sentId),
        ]);
    }
}
