<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard;

use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Throwable;
use UnexpectedValueException;

/**
 * Developer access and developer recipients, registered from the host app's
 * AppServiceProvider::boot(). Registered callbacks live in this container
 * singleton, never in config, so `php artisan config:cache` stays safe.
 */
class FeedbackBoard
{
    protected ?Closure $developerResolver = null;

    protected ?Closure $developerRecipients = null;

    /** @var array<string, true> problems already reported by this instance */
    private array $reported = [];

    /** @param  callable(Authenticatable): bool  $callback */
    public function resolveDevelopersUsing(callable $callback): void
    {
        $this->developerResolver = Closure::fromCallable($callback);
    }

    /** @param  callable(): iterable<object>  $callback  notifiables to receive developer notifications */
    public function notifyDevelopersUsing(callable $callback): void
    {
        $this->developerRecipients = Closure::fromCallable($callback);
    }

    /**
     * Answers through the gate, so Gate::before/after hooks apply exactly as in the
     * package's own checks. A guest (null) is never a developer.
     */
    public function isDeveloper(?Authenticatable $user): bool
    {
        return Gate::forUser($user)->allows('feedback-board.manage');
    }

    /**
     * @internal Raw precedence behind the feedback-board.manage gate. Call isDeveloper().
     */
    public function resolveDeveloper(Authenticatable $user): bool
    {
        if ($this->developerResolver !== null) {
            return $this->normalise(($this->developerResolver)($user));
        }

        $resolver = config('feedback-board.authorization.developer_resolver');

        if ($resolver === null) {
            return false;
        }

        if (! is_callable($resolver)) {
            // Deny, but loudly: throwing here would turn every page that checks access
            // (Filament navigation included) into a 500 for every user.
            $this->reportOnce('invalid-resolver', new InvalidArgumentException(
                'feedback-board.authorization.developer_resolver must be null or a public static method callable '
                .'such as [DeveloperCheck::class, \'handle\']. Register anything else with '
                .'FeedbackBoard::resolveDevelopersUsing() in AppServiceProvider::boot().'
            ));

            return false;
        }

        return $this->normalise($resolver($user));
    }

    /**
     * @internal Used by Support\DeveloperRecipients.
     */
    public function developerRecipients(): Collection
    {
        if ($this->developerRecipients !== null) {
            return Collection::wrap(($this->developerRecipients)());
        }

        $config = config('feedback-board.notifications.developers');

        if (is_callable($config)) {
            return Collection::wrap($config());
        }

        // Laravel's `email` rule (RFC validation): accepts what the mailer accepts,
        // including dev@localhost-style addresses used with local mail catchers.
        [$emails, $invalid] = collect($config ?? [])
            ->map(fn ($entry) => is_string($entry) ? trim($entry) : $entry)
            ->partition(fn ($entry) => is_string($entry)
                && Validator::make(['email' => $entry], ['email' => ['required', 'email']])->passes());

        if ($invalid->isNotEmpty()) {
            Log::warning('Feedback Board skipped entries in feedback-board.notifications.developers that are not e-mail addresses.', [
                'skipped' => $invalid->count(),
            ]);
        }

        return $emails->values()->map(fn (string $email) => Notification::route('mail', $email));
    }

    /**
     * Strict boolean from whatever a resolver returned. A Response is honoured; any other
     * object or array (a Collection, an unexecuted query) is truthy in PHP but is not an
     * answer, so it denies. Scalars and null keep their usual meaning (1, '1', 0).
     */
    protected function normalise(mixed $result): bool
    {
        if ($result instanceof Response) {
            return $result->allowed();
        }

        if (is_object($result) || is_array($result)) {
            $this->reportOnce('unexpected-result', new UnexpectedValueException(
                'A developer resolver returned '.get_debug_type($result).'; it must return a bool. Access was denied.'
            ));

            return false;
        }

        return (bool) $result;
    }

    /** Report each distinct problem once per instance, so a bad config cannot flood the error tracker. */
    protected function reportOnce(string $key, Throwable $e): void
    {
        if (isset($this->reported[$key])) {
            return;
        }

        $this->reported[$key] = true;
        report($e);
    }
}
