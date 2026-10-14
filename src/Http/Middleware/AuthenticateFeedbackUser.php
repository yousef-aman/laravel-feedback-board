<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use YousefAman\FeedbackBoard\FeedbackBoard;

/**
 * `feedback-board.auth`: signs the package routes in as FeedbackBoard::user(), across
 * feedback-board.auth.guards, and makes that user's guard the request's default, so `can:`
 * middleware, policies, views and host middleware after it see the same user. A guest gets
 * Laravel's standard unauthenticated response: the host's login redirect, or a JSON 401.
 */
class AuthenticateFeedbackUser extends Authenticate
{
    public const ALIAS = 'feedback-board.auth';

    /**
     * The route middleware with this one appended unless it is already there (by alias or class,
     * with or without parameters), so a host's own list never leaves the package routes open.
     *
     * @return list<mixed>
     */
    public static function ensureIn(mixed $middleware): array
    {
        $middleware = array_values(Arr::wrap($middleware));

        foreach ($middleware as $entry) {
            if (is_string($entry) && in_array(Str::before($entry, ':'), [self::ALIAS, self::class], true)) {
                return $middleware;
            }
        }

        return [...$middleware, self::ALIAS];
    }

    /**
     * @param  \Illuminate\Http\Request  $request
     * @param  array<int, string|null>  $guards  route parameters; feedback-board.auth.guards decides instead
     */
    protected function authenticate($request, array $guards)
    {
        $board = app(FeedbackBoard::class);
        $resolved = $board->resolveUser();

        if ($resolved === null) {
            $this->unauthenticated($request, $board->candidateGuards()); // throws
        }

        $this->auth->shouldUse($resolved['guard']);
    }
}
