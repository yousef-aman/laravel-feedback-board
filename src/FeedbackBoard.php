<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard;

use Closure;
use Generator;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use UnexpectedValueException;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Support\Ui;
use YousefAman\FeedbackBoard\Triage\TriageManager;

/**
 * The signed-in user, developer and reporter access, developer recipients, report links and triage drivers. Callbacks are registered from the
 * host app's AppServiceProvider::boot() and live in this container singleton, never in config, so
 * `php artisan config:cache` stays safe.
 */
class FeedbackBoard
{
    protected ?Closure $developerResolver = null;

    protected ?Closure $reporterResolver = null;

    protected ?Closure $developerRecipients = null;

    protected ?Closure $reportLinks = null;

    /** @var array<string, true> problems already reported by this instance */
    private array $reported = [];

    /** @var array<string, true> configuration warnings already logged by this instance */
    private array $warned = [];

    /** @param  callable(Authenticatable): bool  $callback */
    public function resolveDevelopersUsing(callable $callback): void
    {
        $this->developerResolver = Closure::fromCallable($callback);
    }

    /** @param  callable(Authenticatable): bool  $callback  who may send reports */
    public function resolveReportersUsing(callable $callback): void
    {
        $this->reporterResolver = Closure::fromCallable($callback);
    }

    /** @param  callable(): iterable<object>  $callback  notifiables to receive developer notifications */
    public function notifyDevelopersUsing(callable $callback): void
    {
        $this->developerRecipients = Closure::fromCallable($callback);
    }

    /**
     * @param  callable(FeedbackItem, string, ?object): ?string  $callback  the link a notification gives for a
     *   report: its audience is 'reporter' or 'developer', and the recipient is the notified model (null for
     *   an e-mail-only recipient); null leaves the link out
     */
    public function linkReportsUsing(callable $callback): void
    {
        $this->reportLinks = Closure::fromCallable($callback);
    }

    /**
     * Add an AI triage driver, chosen with feedback-board.ai.default (FEEDBACK_BOARD_AI_DRIVER) set to $name.
     * The factory receives the settings under feedback-board.ai.{$name} and returns a TriageDriver; one that
     * also implements ChecksConfiguration decides whether triage runs.
     *
     * @param  callable(array<string, mixed>): \YousefAman\FeedbackBoard\Triage\Contracts\TriageDriver  $factory
     */
    public function extendTriage(string $name, callable $factory): void
    {
        app(TriageManager::class)->extend($name, fn () => $factory((array) config("feedback-board.ai.{$name}", [])));
    }

    /**
     * The link that opens a report for its reporter ('reporter': My reports) or for developers
     * ('developer': the board), as notifications give it to $recipient (the notified model; null or an
     * on-demand notifiable for an e-mail address); null when there is none.
     *
     * The app's linkReportsUsing() callback decides when it is set; only an absolute http(s) URL is
     * kept, and a callback that throws is reported once and gives no link. Otherwise the page is in the
     * first Filament panel, the default panel first, that has the plugin and no tenancy and recognises
     * the audience: for the reporter, a panel on a guard whose model is the reporter's, with My reports
     * on; for developers, a panel on a developer guard. A recipient model must also be its guard's
     * model and, as a FilamentUser, allowed into the panel. Without such a panel, the standalone page.
     */
    public function reportUrl(FeedbackItem $item, string $audience, ?object $recipient = null): ?string
    {
        $recipient = $recipient instanceof Model ? $recipient : null;

        if ($this->reportLinks !== null) {
            try {
                return Ui::safeUrl(($this->reportLinks)($item, $audience, $recipient));
            } catch (Throwable $e) {
                $this->reportOnce('report-links', $e);

                return null;
            }
        }

        $developer = $audience === 'developer';

        try {
            $url = $this->panelReportUrl($item, $developer, $recipient);
        } catch (Throwable $e) {
            // A panel whose URLs cannot be built here: the standalone page still opens the report.
            $this->reportOnce('report-links-panel', $e);
            $url = null;
        }

        return $url ?? route($developer ? 'feedback-board.board.page' : 'feedback-board.page', ['report' => $item->getKey()]);
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
     * Answers through the feedback-board.report gate, so Gate::before/after hooks apply. A guest
     * (null) is never a reporter.
     */
    public function canReport(?Authenticatable $user): bool
    {
        return $user !== null && Gate::forUser($user)->allows('feedback-board.report');
    }

    /**
     * @internal Raw precedence behind the feedback-board.manage gate. Call isDeveloper().
     */
    public function resolveDeveloper(Authenticatable $user): bool
    {
        return $this->decide('developer', $this->developerResolver, false, $user);
    }

    /**
     * @internal Raw precedence behind the feedback-board.report gate. Call canReport().
     */
    public function resolveReporter(Authenticatable $user): bool
    {
        return $this->decide('reporter', $this->reporterResolver, true, $user);
    }

    /**
     * @internal Whether the user may be a developer at all, by type: an instance of a developer
     * guard's provider model. Always true in implicit mode. It holds for any user passed in, not only
     * the signed-in one.
     */
    public function onDeveloperGuard(Authenticatable $user): bool
    {
        $developerGuards = $this->developerGuards();

        if ($developerGuards === null) {
            return true;
        }

        $models = [];

        foreach ($developerGuards as $guard) {
            $model = $this->providerModel($guard);

            if ($model === null) {
                $this->warnOnce('developer-guard-provider-'.$guard, 'Feedback Board cannot check developers on the "'.$guard.'" guard: '
                    .'its user provider names no Eloquent model class.');

                continue;
            }

            $models[] = $model;
        }

        if ($models === []) {
            return false;
        }

        // A listed non-developer guard whose model extends a developer model (class Client extends
        // User) would pass instanceof; its users stay out.
        $others = $this->otherGuardModels($developerGuards);

        foreach ($models as $model) {
            if (! $user instanceof $model) {
                continue;
            }

            foreach ($others as $other) {
                if (is_subclass_of($other, $model) && $user instanceof $other) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
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
     * Strict boolean from whatever a resolver returned:
     * - true, 1 and '1' allow; false, 0, '0' and null deny;
     * - a gate Response answers through allowed();
     * - anything else (another string such as 'N' or 'false', another number, an object, an array)
     *   is not an answer: it denies and is reported once. The report names the type, never the value.
     */
    protected function normalise(mixed $result, string $resolver = 'developer'): bool
    {
        if ($result instanceof Response) {
            return $result->allowed();
        }

        if ($result === true || $result === 1 || $result === '1') {
            return true;
        }

        if ($result === false || $result === 0 || $result === '0' || $result === null) {
            return false;
        }

        $this->reportOnce('unexpected-result-'.$resolver, new UnexpectedValueException(
            'A '.$resolver.' resolver returned '.get_debug_type($result).'; it must return a bool. Access was denied.'
        ));

        return false;
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

    /**
     * The precedence both resolvers share: the registered callback, then the config key, where null
     * answers $whenUnset. Anything else denies, but loudly: throwing here would turn every page that
     * checks access (Filament navigation included) into a 500 for every user.
     */
    private function decide(string $kind, ?Closure $callback, bool $whenUnset, Authenticatable $user): bool
    {
        $key = 'feedback-board.authorization.'.$kind.'_resolver';
        $resolver = $callback ?? config($key);

        if ($resolver === null) {
            return $whenUnset;
        }

        if (! is_callable($resolver)) {
            $name = ucfirst($kind);

            $this->reportOnce('invalid-resolver-'.$kind, new InvalidArgumentException(
                $key.' must be null or a public static method callable such as ['.$name.'Check::class, \'handle\']. '
                .'Register anything else with FeedbackBoard::resolve'.$name.'sUsing() in AppServiceProvider::boot().'
            ));

            return false;
        }

        try {
            $result = $resolver($user);
        } catch (Throwable $e) {
            $this->reportOnce('resolver-threw-'.$kind, new RuntimeException(
                'A '.$kind.' resolver threw '.$e::class.' ('.$e->getMessage().'); access was denied.', 0, $e
            ));

            return false;
        }

        return $this->normalise($result, $kind);
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

    /** The report's page in the first Filament panel that suits the audience (see reportUrl()); null when none does. */
    private function panelReportUrl(FeedbackItem $item, bool $developer, ?Model $recipient): ?string
    {
        // Filament may be installed but not booted (an app without panels): check the provider, not the class.
        if (! app()->providerIsLoaded(\Filament\FilamentServiceProvider::class)) {
            return null;
        }

        $configured = $this->configuredGuards();
        $developerGuards = $developer ? $this->developerGuards() : null;
        $reporterModel = $developer || $item->reporter_type === null
            ? null
            : (Relation::getMorphedModel($item->reporter_type) ?? $item->reporter_type);

        if (! $developer && $reporterModel === null) {
            return null;
        }

        $panels = \Filament\Facades\Filament::getPanels();

        foreach ($panels as $id => $panel) {
            if ($panel->isDefault()) {
                $panels = [$id => $panel, ...$panels];

                break;
            }
        }

        foreach ($panels as $panel) {
            $plugin = $panel->hasPlugin('feedback-board') ? $panel->getPlugin('feedback-board') : null;
            $guard = $panel->getAuthGuard();

            if (! $plugin instanceof Filament\FeedbackBoardPlugin || $panel->hasTenancy()
                || ($configured !== null && ! in_array($guard, $configured, true))) {
                continue;
            }

            // The recipient signs in on this guard, and the panel lets them in.
            if ($recipient !== null && ($this->providerModel($guard) !== $recipient::class
                || ($recipient instanceof \Filament\Models\Contracts\FilamentUser && ! $recipient->canAccessPanel($panel)))) {
                continue;
            }

            if ($developer && ($developerGuards === null || in_array($guard, $developerGuards, true))) {
                return Filament\Pages\FeedbackBoardPage::getUrl(['report' => $item->getKey()], panel: $panel->getId());
            }

            if (! $developer && $plugin->hasMyReports() && $this->providerModel($guard) === $reporterModel) {
                return Filament\Pages\MyFeedbackPage::getUrl(['report' => $item->getKey()], panel: $panel->getId());
            }
        }

        return null;
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
     * feedback-board.auth.developer_guards resolved against the valid guards; null = unrestricted
     * (implicit mode). With one guard and no list, that guard; with several and no list, none.
     *
     * @return list<string>|null
     */
    private function developerGuards(): ?array
    {
        $guards = $this->configuredGuards();

        if ($guards === null) {
            return null;
        }

        $value = config('feedback-board.auth.developer_guards');

        if ($value === null || $value === []) {
            if (count($guards) > 1) {
                $this->warnOnce('developer-guards-missing', 'Feedback Board has no developers: feedback-board.auth.guards lists several guards, '
                    .'so set feedback-board.auth.developer_guards to the guards whose users may be developers.');

                return [];
            }

            return $guards;
        }

        if (! is_array($value)) {
            $this->warnOnce('invalid-developer-guards', 'Feedback Board has no developers: feedback-board.auth.developer_guards must be null or an array of guard names.', [
                'given' => get_debug_type($value),
            ]);

            return [];
        }

        [$developerGuards, $skipped] = self::pick($value, $guards);

        if ($skipped !== []) {
            $this->warnOnce('unknown-developer-guards', 'Feedback Board skipped entries in feedback-board.auth.developer_guards that are not in feedback-board.auth.guards.', [
                'skipped' => $skipped,
            ]);
        }

        return $developerGuards;
    }

    /**
     * The models of the listed guards that are not developer guards. A guard whose model cannot be
     * determined is reported once, because its users cannot be told apart from developers by type.
     * The database driver is exempt: it returns GenericUser, never an instance of an Eloquent model.
     *
     * @param  list<string>  $developerGuards
     * @return list<class-string<Model>>
     */
    private function otherGuardModels(array $developerGuards): array
    {
        $models = [];

        foreach (array_diff($this->configuredGuards() ?? [], $developerGuards) as $guard) {
            $model = $this->providerModel($guard);

            if ($model !== null) {
                $models[] = $model;
            } elseif ($this->providerDriver($guard) !== 'database') {
                $this->warnOnce('guard-model-unknown-'.$guard, 'Feedback Board cannot tell users of the "'.$guard.'" guard from developers by type: '
                    .'its user provider names no Eloquent model class.');
            }
        }

        return $models;
    }

    /**
     * The Eloquent model class behind a guard's user provider, whatever its driver (eloquent, or a
     * custom one such as eloquent-webauthn); null when the provider names none (the database driver,
     * LdapRecord's own model classes, a missing class).
     *
     * @return class-string<Model>|null
     */
    private function providerModel(string $guard): ?string
    {
        $model = $this->providerSettings($guard)['model'] ?? null;

        return is_string($model) && $model !== '' && is_subclass_of($model, Model::class) ? $model : null;
    }

    private function providerDriver(string $guard): ?string
    {
        $driver = $this->providerSettings($guard)['driver'] ?? null;

        return is_string($driver) ? $driver : null;
    }

    /** @return array<string, mixed> the guard's user provider settings from config/auth.php; [] when it has none */
    private function providerSettings(string $guard): array
    {
        $provider = config('auth.guards')[$guard]['provider'] ?? null;
        $settings = is_string($provider) ? (config('auth.providers')[$provider] ?? null) : null;

        return is_array($settings) ? $settings : [];
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
