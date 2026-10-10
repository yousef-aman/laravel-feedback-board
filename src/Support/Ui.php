<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Support;

final class Ui
{
    /** @var list<string> */
    public const THEMES = ['class', 'system', 'light', 'dark'];

    public static function stylesheetPath(): string
    {
        return dirname(__DIR__, 2).'/resources/dist/feedback-board.css';
    }

    // Not memoized: long-lived workers (Octane) can outlive a package upgrade, and hashing
    // the ~20 KB file costs microseconds.
    public static function stylesheetVersion(): string
    {
        return substr((string) md5_file(self::stylesheetPath()), 0, 12);
    }

    // Absolute, like Livewire's own script URL: a root-relative route() drops a sub-directory base path.
    public static function stylesheetUrl(): string
    {
        return route('feedback-board.assets.css', ['id' => self::stylesheetVersion()]);
    }

    public static function theme(): string
    {
        $theme = config('feedback-board.ui.theme', 'class');

        return in_array($theme, self::THEMES, true) ? $theme : 'class';
    }

    // For the standalone pages' <meta name="color-scheme"> (viewport scrollbars and canvas).
    public static function colorScheme(): string
    {
        return match (self::theme()) {
            'light' => 'light',
            'dark' => 'dark',
            default => 'light dark',
        };
    }

    // Allow-list: only http(s) URLs may become an href (meta.url comes from a client-controlled header).
    public static function safeUrl(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true) ? $url : null;
    }
}
