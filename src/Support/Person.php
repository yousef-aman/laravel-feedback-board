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
}
