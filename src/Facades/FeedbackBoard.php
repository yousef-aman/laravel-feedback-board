<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void resolveDevelopersUsing(callable $callback)
 * @method static void resolveReportersUsing(callable $callback)
 * @method static void notifyDevelopersUsing(callable $callback)
 * @method static void linkReportsUsing(callable $callback)
 * @method static string|null reportUrl(\YousefAman\FeedbackBoard\Models\FeedbackItem $item, string $audience, object|null $recipient = null)
 * @method static bool isDeveloper(\Illuminate\Contracts\Auth\Authenticatable|null $user)
 * @method static bool canReport(\Illuminate\Contracts\Auth\Authenticatable|null $user)
 * @method static \Illuminate\Contracts\Auth\Authenticatable|null user()
 *
 * @see \YousefAman\FeedbackBoard\FeedbackBoard
 */
class FeedbackBoard extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \YousefAman\FeedbackBoard\FeedbackBoard::class;
    }
}
