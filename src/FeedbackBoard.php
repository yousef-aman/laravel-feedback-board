<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard;

use Closure;
use Generator;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Throwable;
use UnexpectedValueException;

/**
 * The signed-in user, developer access and developer recipients. Callbacks are registered from the
 * host app's AppServiceProvider::boot() and live in this container singleton, never in config, so
 * `php artisan config:cache` stays safe.
 */
class FeedbackBoard
{
    protected ?Closure $developerResolver = null;

    protected ?Closure $developerRecipients = null;

    /** @var array<string, true> problems already reported by this instance */
    private array $reported = [];

    /** @var array<string, true> configuration warnings already logged by this instance */
    private array $warned = [];

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
     * The signed-in user the package works with: the first user found on feedback-board.auth.guards
     * (the guard in use when that is null). Not memoised: guards cache their own user, and the guard
     * in use can change mid-request.
     */
    public function user(): ?Authenticatable
    {
        return $this->resolveUser()['user'] ?? null;
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
     * @internal The first recognised user and its guard: the single source for user() and the
     * feedback-board.auth middleware.
     *
     * @return array{guard: string, user: Authenticatable&Model}|null
     */
    public function resolveUser(): ?array
    {
        foreach ($this->signedIn() as $entry) {
            return $entry;
        }

        return null;
    }

    /**
     * @internal Every recognised user signed in on this request, in resolution order. Attachments
     * accept any of them: each is a session genuinely signed in in this browser.
     *
     * @return list<array{guard: string, user: Authenticatable&Model}>
     */
    public function recognisedUsers(): array
    {
        return iterator_to_array($this->signedIn(), false);
    }

    /**
     * @internal The guards a user may be resolved from on this request, in order: inside a Filament
     * panel the panel's guard alone; elsewhere the guard in use first, then the configured list.
     *
     * @return list<string>
     */
    public function candidateGuards(): array
    {
        $configured = $this->configuredGuards();
        $panel = $this->currentPanel();

        if ($panel !== null) {
            if ($configured === null || in_array($panel['guard'], $configured, true)) {
                return [$panel['guard']];
            }

            if ($configured !== []) {
                $this->warnOnce('panel-guard-'.$panel['id'], 'Feedback Board recognises nobody in the "'.$panel['id'].'" Filament panel: '
                    .'its guard "'.$panel['guard'].'" is not in feedback-board.auth.guards.');
            }

            // No fallback: a panel never shows or files a report as a user of another guard.
            return [];
        }

        $inUse = Auth::getDefaultDriver();

        if ($configured === null) {
            return [$inUse];
        }

        return in_array($inUse, $configured, true)
            ? [$inUse, ...array_values(array_diff($configured, [$inUse]))]
            : $configured;
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

    /**
     * Log each distinct configuration problem once per instance, like reportOnce().
     *
     * @param  array<string, mixed>  $context
     */
    protected function warnOnce(string $key, string $message, array $context = []): void
    {
        if (isset($this->warned[$key])) {
            return;
        }

        $this->warned[$key] = true;
        Log::warning($message, $context);
    }

    /** @return Generator<int, array{guard: string, user: Authenticatable&Model}> */
    private function signedIn(): Generator
    {
        foreach ($this->candidateGuards() as $guard) {
            $user = Auth::guard($guard)->user();

            // A non-Eloquent user (a `database` provider's GenericUser) cannot own a report.
            if ($user instanceof Model) {
                yield ['guard' => $guard, 'user' => $user];
            }
        }
    }

    /**
     * feedback-board.auth.guards: null in implicit mode (null or []); otherwise its entries that name a
     * guard in config/auth.php, duplicates removed and order kept. Anything else fails closed.
     *
     * @return list<string>|null
     */
    private function configuredGuards(): ?array
    {
        $value = config('feedback-board.auth.guards');

        if ($value === null || $value === []) {
            return null;
        }

        if (! is_array($value)) {
            $this->warnOnce('invalid-guards', 'Feedback Board recognises nobody: feedback-board.auth.guards must be null or an array of guard names.', [
                'given' => get_debug_type($value),
            ]);

            return [];
        }

        [$guards, $skipped] = self::pick($value, array_keys((array) config('auth.guards', [])));

        if ($skipped !== []) {
            $this->warnOnce('unknown-guards', 'Feedback Board skipped entries in feedback-board.auth.guards that name no guard in config/auth.php.', [
                'skipped' => $skipped,
            ]);
        }

        return $guards;
    }

    /** @return array{id: string, guard: string}|null the current Filament panel; null outside one */
    private function currentPanel(): ?array
    {
        // Filament may be installed but not booted (an app without panels): check the provider, not the class.
        if (! app()->providerIsLoaded(\Filament\FilamentServiceProvider::class)) {
            return null;
        }

        $panel = \Filament\Facades\Filament::getCurrentPanel();

        return $panel === null ? null : ['id' => $panel->getId(), 'guard' => $panel->getAuthGuard()];
    }

    /**
     * Splits a configured list into its entries found in $allowed (duplicates removed, order kept) and
     * the rest, described for a warning.
     *
     * @param  array<mixed>  $entries
     * @param  list<int|string>  $allowed
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function pick(array $entries, array $allowed): array
    {
        $kept = [];
        $skipped = [];

        foreach ($entries as $entry) {
            if (is_string($entry) && in_array($entry, $allowed, true)) {
                $kept[$entry] = $entry;
            } else {
                $skipped[] = is_string($entry) ? $entry : get_debug_type($entry);
            }
        }

        return [array_values($kept), $skipped];
    }
}
