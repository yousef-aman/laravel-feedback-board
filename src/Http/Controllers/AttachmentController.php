<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Http\Controllers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use YousefAman\FeedbackBoard\Models\FeedbackAttachment;
use YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy;

class AttachmentController
{
    // Allow-listed images render inline (thumbnails, covers, the lightbox) under a policy that
    // allows no script and sandboxes the document; everything else, SVG included, downloads.
    private const IMAGE_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        'Cache-Control' => 'private, max-age=3600',
    ];

    public function show(FeedbackAttachment $attachment): StreamedResponse
    {
        abort_unless(Gate::allows('view', $attachment->item), 403);

        // Internal notes are developer-only, and so are their images.
        if ($attachment->comment?->is_internal) {
            abort_unless(FeedbackItemPolicy::isDeveloper(auth()->user()), 403);
        }

        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists($attachment->path), 404);

        if ($attachment->isImage()) {
            return $disk->response($attachment->path, $attachment->original_name, [
                ...self::IMAGE_HEADERS,
                'Content-Type' => strtolower((string) $attachment->mime), // allow-listed by isImage()
            ]);
        }

        return $disk->download($attachment->path, $attachment->original_name);
    }
}
