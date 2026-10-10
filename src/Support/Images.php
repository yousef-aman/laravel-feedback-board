<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Support;

/** The only images the package renders inline: thumbnails, covers, previews and the lightbox. */
final class Images
{
    /** Extension => the only MIME type an image with that extension may have. */
    public const MIMES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];

    /** @return list<string> */
    public static function mimes(): array
    {
        return array_values(array_unique(self::MIMES));
    }

    /** @return list<string> */
    public static function extensions(): array
    {
        return array_keys(self::MIMES);
    }

    // Both must agree: the stored MIME comes from the file's content, the extension from the
    // client's file name, and browsers pick a renderer from either.
    public static function matches(?string $mime, ?string $name): bool
    {
        if ($mime === null || $name === null) {
            return false;
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return (self::MIMES[$extension] ?? null) === strtolower($mime);
    }
}
