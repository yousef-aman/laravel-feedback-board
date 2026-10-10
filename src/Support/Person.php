<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Support;

use Illuminate\Database\Eloquent\Model;

/** Display names for the people on a report: users of the host app, whatever their columns. */
final class Person
{
    /** The name, then the e-mail, then "#id"; null when there is nothing to show. */
    public static function name(?Model $model, int|string|null $id = null): ?string
    {
        foreach (['name', 'email'] as $attribute) {
            $value = data_get($model, $attribute);

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        $id ??= $model?->getKey();

        return $id === null || $id === '' ? null : '#'.$id;
    }

    /** One letter for an avatar: a single code point, never two joined Arabic letters. */
    public static function initial(string $name): string
    {
        $letter = mb_strtoupper(mb_substr(trim($name), 0, 1));

        return $letter !== '' ? $letter : '?';
    }

    /** A stable 1–4 avatar hue from the name. */
    public static function hue(string $name): int
    {
        return crc32($name) % 4 + 1;
    }
}
