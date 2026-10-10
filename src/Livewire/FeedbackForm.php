<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Livewire;

use Livewire\Component;
use Livewire\WithFileUploads;
use YousefAman\FeedbackBoard\Actions\CreateFeedbackItem;
use YousefAman\FeedbackBoard\Enums\FeedbackType;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Services\AttachmentStorage;

class FeedbackForm extends Component
{
    use WithFileUploads;

    public string $title = '';
    public string $description = '';
    public string $type = 'bug';
    public array $files = [];
    public bool $sent = false;

    public function submit(CreateFeedbackItem $create, AttachmentStorage $storage): void
    {
        $this->authorize('create', FeedbackItem::class);
        $this->sent = false;

        $data = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['required', 'in:bug,feature,question,other'],
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

        foreach ($this->files as $file) {
            $storage->store($file, $item);
        }

        $this->reset('title', 'description', 'files');
        $this->sent = true;
        $this->dispatch('feedback-submitted', id: $item->id);
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        $limits = app(AttachmentStorage::class)->limits();

        return view('feedback-board::livewire.feedback-form', [
            'attachmentTypes' => $limits['types'],
            'attachmentSize' => $limits['size'],
        ]);
    }
}
