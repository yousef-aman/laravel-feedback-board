<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Http\Controllers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use YousefAman\FeedbackBoard\FeedbackBoard;
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
        // Any session signed in in this browser may open what it is allowed to see: a client's own
        // images must not 403 because the browser also holds a staff session. Internal notes are
        // developer-only, and so are their images.
        $internal = (bool) $attachment->comment?->is_internal;

        abort_unless(collect(app(FeedbackBoard::class)->recognisedUsers())->contains(
            fn (array $entry): bool => Gate::forUser($entry['user'])->allows('view', $attachment->item)
                && (! $internal || FeedbackItemPolicy::isDeveloper($entry['user'])),
        ), 403);

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
