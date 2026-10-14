<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Support;

use YousefAman\FeedbackBoard\FeedbackBoard;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

final class Ui
{
    /** @var list<string> */
    public const THEMES = ['class', 'system', 'light', 'dark'];

    private static ?string $stylesheetPath = null;

    /** @internal Test seam: simulates an install whose built stylesheet is missing. */
    public static function useStylesheetPath(?string $path): void
    {
        self::$stylesheetPath = $path;
    }

    public static function stylesheetPath(): string
    {
        return self::$stylesheetPath ?? dirname(__DIR__, 2).'/resources/dist/feedback-board.css';
    }

    public static function stylesheetExists(): bool
    {
        return is_file(self::stylesheetPath());
    }

    // Not memoized: long-lived workers (Octane) can outlive a package upgrade, and hashing
    // the ~20 KB file costs microseconds. A missing file (an install that stripped
    // resources/dist) must leave host pages unstyled, not fail every page with a 500.
    public static function stylesheetVersion(): string
    {
        return self::stylesheetExists() ? substr((string) md5_file(self::stylesheetPath()), 0, 12) : 'missing';
    }

    // Absolute, like Livewire's own script URL: a root-relative route() drops a sub-directory base path.
    public static function stylesheetUrl(): string
    {
        return route('feedback-board.assets.css', ['id' => self::stylesheetVersion()]);
    }

    public static function theme(): string
    {
        $theme = config('feedback-board.ui.theme', 'class');

        if (is_string($theme)) {
            $theme = strtolower(trim($theme));
        }

        return in_array($theme, self::THEMES, true) ? $theme : 'class';
    }

    // For the standalone pages' <meta name="color-scheme">. With `class` there is no host page to
    // set the dark class, so the standalone pages are light; `system` follows the OS.
    public static function colorScheme(): string
    {
        return match (self::theme()) {
            'dark' => 'dark',
            'system' => 'light dark',
            default => 'light',
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

    /**
     * What the current user may do as a reporter: 'form' (send reports and follow them), 'reports'
     * (follow earlier reports only: may not report, but has reports) or null (nothing, a guest
     * included). The launcher, "My reports" and its "New report" actions all read it.
     */
    public static function reporterAccess(): ?string
    {
        $board = app(FeedbackBoard::class);
        $user = $board->user();

        if ($user === null) {
            return null;
        }

        if ($board->canReport($user)) {
            return 'form';
        }

        return FeedbackItem::query()->ownedBy($user)->exists() ? 'reports' : null;
    }

    /** "My reports": the panel page inside a Filament panel that enables it, else the standalone page. */
    public static function reportsUrl(?int $reportId = null): string
    {
        $url = self::panelReportsUrl() ?? route('feedback-board.page');

        return $reportId === null ? $url : self::reportLink($url, $reportId);
    }

    // Filament may be installed but not booted (an app without panels): check the provider, not the class.
    private static function panelReportsUrl(): ?string
    {
        if (! app()->providerIsLoaded(\Filament\FilamentServiceProvider::class)) {
            return null;
        }

        return \YousefAman\FeedbackBoard\Filament\FeedbackBoardPlugin::current()?->hasMyReports()
            ? \YousefAman\FeedbackBoard\Filament\Pages\MyFeedbackPage::getUrl()
            : null;
    }

    /** Appends the deep-link query that opens one report. */
    public static function reportLink(string $base, int $reportId): string
    {
        return $base.(str_contains($base, '?') ? '&' : '?').'report='.$reportId;
    }
}
