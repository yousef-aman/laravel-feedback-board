<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use YousefAman\FeedbackBoard\Models\FeedbackAttachment;
use YousefAman\FeedbackBoard\Models\FeedbackComment;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class AttachmentStorage
{
    public function allowedRules(): array
    {
        $mimes = implode(',', config('feedback-board.attachments.allowed_mimes', []));
        $max = (int) config('feedback-board.attachments.max_kb', 10240);
        return ['file', 'mimes:'.$mimes, 'max:'.$max];
    }

    public function store(UploadedFile $file, FeedbackItem $item, ?FeedbackComment $comment = null): FeedbackAttachment
    {
        Validator::make(['file' => $file], ['file' => $this->allowedRules()])->validate();

        $disk = config('feedback-board.attachments.disk', 'local');
        $dir = config('feedback-board.attachments.directory', 'feedback-board').'/'.$item->getKey();
        $path = $file->store($dir, $disk);

        return $item->attachments()->create([
            'feedback_comment_id' => $comment?->getKey(),
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);
    }
}
