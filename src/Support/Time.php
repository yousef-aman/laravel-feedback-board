<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Support;

use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;

/** Times the package stores as ISO-8601 strings (in ai_state and external_refs), read back and shown. */
final class Time
{
    /** The stored time, or null when it is not a non-empty string Carbon can parse (Carbon reads '' as now). */
    public static function parse(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (InvalidFormatException) {
            return null;
        }
    }

    /**
     * "5 minutes ago" in the app's language, set explicitly (Carbon follows it only through its Laravel
     * provider), English for a language Carbon does not know. $absolute drops "ago": "5 minutes".
     */
    public static function ago(CarbonInterface $time, bool $absolute = false): string
    {
        return $time->locale(app()->getLocale(), 'en')
            ->diffForHumans($absolute ? ['syntax' => CarbonInterface::DIFF_ABSOLUTE] : []);
    }
}
