<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Livewire\Concerns;

use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};
use YousefAman\FeedbackBoard\Services\AttachmentStorage;

/**
 * One upload path for the picker, drop and paste: the browser sends each file on its own to the
 * `upload` slot ($wire.upload('upload', file)), and updatedUpload() validates it and moves it into
 * the locked `files` list. The client can never set `files` directly. The same JavaScript runs on
 * Livewire 3 and 4, with no uploadMultiple() (its S3 restriction and single large POST).
 */
trait WithStagedUploads
{
    use WithFileUploads;

    /** The staging slot for one finished upload; always empty between requests. */
    public $upload = null;

    /** @var list<TemporaryUploadedFile> */
    #[Locked]
    public array $files = [];

    /** The validation rules for one staged file. */
    abstract protected function uploadRules(): array;

    public function updatedUpload(mixed $value): void
    {
        try {
            if (! $value instanceof TemporaryUploadedFile) {
                return;
            }

            $validator = Validator::make(
                ['upload' => $value],
                ['upload' => $this->uploadRules()],
                [],
                ['upload' => $value->getClientOriginalName()],
            );

            if ($validator->fails()) {
                $value->delete();
                $this->addError('upload', $validator->errors()->first('upload'));

                return;
            }

            $max = app(AttachmentStorage::class)->maxFiles();

            if (count($this->files) >= $max) {
                $value->delete();
                $this->addError('upload', __('feedback-board::feedback-board.form.max_files_reached', ['count' => $max]));

                return;
            }

            $this->files[] = $value;
        } finally {
            $this->upload = null;
        }
    }

    public function removeFile(int $index): void
    {
        $file = $this->files[$index] ?? null;

        if ($file instanceof TemporaryUploadedFile) {
            $file->delete();
        }

        unset($this->files[$index]);
        $this->files = array_values($this->files);
    }

    /**
     * Stores the staged files once their record exists (after its transaction: disk writes cannot
     * roll back) and empties the list. A failing file is reported and skipped; false tells the
     * view to say so.
     */
    protected function storeStagedFiles(AttachmentStorage $storage, FeedbackItem $item, ?FeedbackComment $comment = null): bool
    {
        $stored = true;

        foreach ($this->files as $file) {
            try {
                $storage->store($file, $item, $comment);
            } catch (\Throwable $e) {
                report($e);
                $stored = false;
            }
        }

        $this->files = [];

        return $stored;
    }
}
