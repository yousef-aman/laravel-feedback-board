<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use YousefAman\FeedbackBoard\Models\FeedbackAttachment;

class AttachmentController
{
    public function show(FeedbackAttachment $attachment)
    {
        abort_unless(
            app(\YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy::class)
                ->view(auth()->user(), $attachment->item),
            403
        );

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name);
    }
}
