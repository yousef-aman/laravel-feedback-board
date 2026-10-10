<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void resolveDevelopersUsing(callable $callback)
 * @method static void notifyDevelopersUsing(callable $callback)
 * @method static bool isDeveloper(\Illuminate\Contracts\Auth\Authenticatable|null $user)
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
