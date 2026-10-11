<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Filament;

use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Support\Facades\Vite;

/**
 * Prints the panel's primary colour as the package's internal bridge tokens (read by the stylesheet
 * only inside .fi .fb-scope, beneath the public tokens). Only allow-listed values are printed:
 * oklch() with plain numbers, or hex. Str::sanitizeCssColor() is absent in early Filament 4.x.
 */
final class PanelColors
{
    private const WCAG_AA_TEXT = 4.5;

    private const WHITE = '#ffffff';

    public static function style(): string
    {
        $tokens = self::tokens(FilamentColor::getColor('primary') ?? []);

        if ($tokens === null) {
            return '';
        }

        $nonce = Vite::cspNonce();

        return '<style'.($nonce ? ' nonce="'.e($nonce).'"' : '').'>'
            .':root{--fb-panel-accent:'.$tokens['accent'].';--fb-panel-accent-hover:'.$tokens['hover'].';--fb-panel-accent-contrast:'.$tokens['contrast'].'}'
            .'</style>';
    }

    /**
     * Button text on the 600 shade: white or the 950 shade, whichever passes WCAG AA (white first);
     * if neither does, the one with the higher ratio. Null when a shade is missing or not allowed.
     *
     * @param  array<int|string, mixed>  $palette
     * @return array{accent: string, hover: string, contrast: string}|null
     */
    public static function tokens(array $palette): ?array
    {
        $accent = $palette[600] ?? null;
        $hover = $palette[500] ?? null;
        $dark = $palette[950] ?? null;

        if (! self::allowed($accent) || ! self::allowed($hover) || ! self::allowed($dark)) {
            return null;
        }

        $onWhite = Color::calculateContrastRatio($accent, self::WHITE);
        $onDark = Color::calculateContrastRatio($accent, $dark);

        $contrast = match (true) {
            $onWhite >= self::WCAG_AA_TEXT => self::WHITE,
            $onDark >= self::WCAG_AA_TEXT => $dark,
            default => $onWhite >= $onDark ? self::WHITE : $dark,
        };

        return ['accent' => $accent, 'hover' => $hover, 'contrast' => $contrast];
    }

    public static function allowed(mixed $value): bool
    {
        return is_string($value) && (
            preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})\z/i', $value) === 1
            || preg_match('/^oklch\(\d*\.?\d+%? \d*\.?\d+ \d*\.?\d+\)\z/', $value) === 1
        );
    }
}
