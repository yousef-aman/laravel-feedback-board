# v1.0 Release Readiness — Design Spec

> Prepares `yousefaman/feedback-board` for its first public release. The package
> will be **dogfooded in the author's own apps first** (installed via a Composer
> path/VCS repository) and only then made public on Packagist. v1.0 ships the
> existing MVP + Phase 2-A/2-B/2-C feature set; the Phase 2-D hub
> (`2026-07-01-hub-aggregation-design.md`) moves to v1.1.
>
> Grounded in a 2026-10-07 throwaway probe: the unchanged codebase was installed
> on Laravel 13.35 / Livewire 4.4.7 / Filament 5.10 / Pest 4.7 / Testbench 11.3
> (PHP 8.4). 19 of 200 tests failed, all with one root cause (§3). With a
> one-line conditional fix, all 200 passed.
>
> Hardened against the 2026-10-08 adversarial review (two reviewers; 3 Critical,
> 6 Important, 13 Minor confirmed). Review-driven changes are marked **[R]**.

## 1. Overview & Scope

The package cannot be installed on the current mainstream stack today:
`composer.json` requires `livewire/livewire ^3.0` while advertising Filament
`^5`, but Filament 5 requires Livewire `^4` — so any Filament 5 app fails to
resolve. Laravel 13 is not allowed at all. Beyond installability, three defects
would break a first real install or deploy:

- the only documented way to configure developer access puts closures in
  `config/feedback-board.php`, which makes `php artisan config:cache` /
  `optimize` fail;
- **[R]** `php artisan optimize` (`view:cache`) fails in every app without
  Filament, because a Filament-only Blade view sits on the package's default
  view path (§4);
- **[R]** notifications are sent synchronously inside the database transaction
  that creates a report, a reply or a status change, so any notification failure
  (missing `notifications` table for the default `database` channel, mail
  transport outage, a malformed recipient) **rolls back and loses the user's
  action** (§6).

**In scope**
- Widened, honest, **[R]** advisory-free dependency constraints + a CI matrix
  that exercises them (§2).
- Dual Livewire 3/4 component registration; removal of a dead Blade
  `componentNamespace` line (§3).
- **[R]** Filament-only views loaded only when Filament is registered (§4).
- A `FeedbackBoard` facade for developer access/recipients that is safe under
  `config:cache` (§5).
- **[R]** Notification reliability: after-commit, best-effort delivery;
  `mail`-only default channel; recipient validation (§6).
- A `feedback-board:install` Artisan command (§7).
- README/config documentation for the above, plus correcting the wrong
  `notifications.developers` example (§8).

**Out of scope**
- **[R] UI styling.** The package currently ships **no CSS**: the `fb-*`
  classes used by the launcher, modal, form, Kanban and detail views have no
  styles anywhere, so the launcher renders as an inline unstyled button and the
  Kanban columns stack as plain blocks (including inside Filament). This gets
  its **own spec, done after this one and before dogfooding**.
- Pre-publish packaging: README Roadmap rewrite; completing the config reference
  table with `ai.*` / `runner.*` keys; `LICENSE`, `CHANGELOG.md`,
  `.gitattributes` export-ignore rules.
- Pushing to GitHub, tagging `v1.0.0`, Packagist submission.
- Phase 2-D hub aggregation (v1.1).

## 2. Dependency Matrix & CI

### 2.1 Support matrix

| | Supported |
|---|---|
| PHP | `^8.2` |
| Laravel | 12, 13 |
| Livewire | 3 (≥ 3.8.3), 4 (≥ 4.3.4) |
| Filament (optional) | 4 (with Livewire 3), 5 (with Livewire 4) |

Laravel 11 is dropped: its security support ended March 2026 and the package
has no released users, so dropping it has no compatibility cost.

### 2.2 `composer.json`

| Key | Current | New |
|---|---|---|
| `illuminate/contracts` | `^11.0 \|\| ^12.0` | `^12.0 \|\| ^13.0` |
| `livewire/livewire` | `^3.0` | **[R]** `^3.8.3 \|\| ^4.3.4` |
| `spatie/laravel-package-tools` | `^1.15.0` | `^1.19` |
| `orchestra/testbench` (dev) | `^9.0 \|\| ^10.0` | `^10.0 \|\| ^11.0` |
| `pestphp/pest` (dev) | `^3.0` | `^3.0 \|\| ^4.0` |
| `pestphp/pest-plugin-laravel` (dev) | `^3.0` | `^3.0 \|\| ^4.0` |
| `filament/filament` (dev) | `^4.0 \|\| ^5.0` | unchanged |
| `php` | `^8.2` | unchanged |
| `minimum-stability` | `"dev"` | **[R]** removed (defaults to `stable`); `prefer-stable` removed with it |

Add a `suggest` entry:

```json
"suggest": {
    "filament/filament": "^4.0 || ^5.0 — developer Kanban page, resource and database notifications"
}
```

**[R] Livewire floor = security floor.** Packagist's advisory database lists
CVE-2025-54068 (critical RCE, `>=3.0.0-beta.1,<3.6.4`), CVE-2024-47823 (RCE on
file uploads, `<3.5.2`) and CVE-2026-81887 (DOM XSS, `<=3.8.2` and
`>=4.0.0-beta.1,<=4.3.3`). With Laravel 12 the reachable Livewire 3 floor under
`^3.0` was 3.5.20 — inside the RCE range. `^3.8.3 || ^4.3.4` excludes every
listed advisory. The package uses `WithFileUploads`, so the upload RCE is
directly relevant.

**[R] `minimum-stability`.** `"dev"` let a `prefer-lowest` resolution pick dev
branches (`guzzlehttp/guzzle 7.8.x-dev`, `guzzlehttp/psr7 1.9.x-dev`). No
resolution needs an unstable package, so the key is removed.

Facts the constraints rely on (verified against Packagist and vendor source):
- `spatie/laravel-package-tools` 1.15.0 supports only Laravel 9/10; **1.19.0 is
  the first release allowing Laravel 12**. 1.19.0 provides everything §7 uses
  (`hasInstallCommand`, `publishConfigFile`, `publishMigrations`,
  `askToRunMigrations`, `endWith`).
- Laravel 13 and Pest 4 require PHP `^8.3`; `pest-plugin-laravel` 3.x allows at
  most Laravel `^12.9.2`. Hence the `^3.0 || ^4.0` Pest range: PHP 8.2 lanes
  resolve Pest 3 + Laravel 12, PHP ≥ 8.3 lanes may resolve Pest 4.
- Testbench 10 ↔ Laravel 12, Testbench 11 ↔ Laravel 13.
- Livewire ≥ 3.7.15 allows Laravel 13; Filament 4 requires Livewire `^3.5`
  (later releases `^3.7`/`^3.8.x`) and allows Laravel 13 from 4.9.0; Filament 5
  requires Livewire `^4.0` (later `^4.1`+). All four Laravel × Livewire
  combinations resolve, and Composer picks the Filament major matching the
  Livewire lane.

### 2.3 CI — `.github/workflows/tests.yml`

```yaml
name: tests
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        php: ['8.2', '8.3', '8.4', '8.5']
        laravel: ['12.*', '13.*']
        livewire: ['3.*', '4.*']
        stability: ['prefer-stable']
        exclude:
          - php: '8.2'
            laravel: '13.*'
        include:
          - php: '8.2'
            laravel: '12.*'
            livewire: '3.*'
            stability: 'prefer-lowest'
          - php: '8.2'
            laravel: '12.*'
            livewire: '4.*'
            stability: 'prefer-lowest'
    name: PHP ${{ matrix.php }} · L${{ matrix.laravel }} · LW${{ matrix.livewire }} · ${{ matrix.stability }}
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          coverage: none
      - run: composer require "laravel/framework:${{ matrix.laravel }}" "livewire/livewire:${{ matrix.livewire }}" --no-update --no-interaction
      - if: matrix.stability == 'prefer-stable'
        run: composer update --prefer-stable --prefer-dist --no-interaction
      - if: matrix.stability == 'prefer-lowest'
        run: composer update --prefer-lowest --prefer-dist --no-interaction
        env:
          COMPOSER_NO_SECURITY_BLOCKING: '1'
      - run: vendor/bin/pest
```

14 `prefer-stable` jobs (16 combinations minus the two PHP 8.2 + Laravel 13
lanes) plus **[R] two** `prefer-lowest` jobs. Each `include` entry introduces a
new `stability` value, so GitHub adds it as a separate job.

- **[R]** The Livewire 4 `prefer-lowest` lane is the only lane exercising the
  §3.2 code path at its floor (Livewire 4.3.4 / Filament 5.0.x).
- **[R]** `setup-php` installs the latest Composer (≥ 2.9), whose
  `audit.block-insecure` (default `true`) silently skips any version with a
  known advisory — so a `prefer-lowest` run would not resolve the declared
  floors. `COMPOSER_NO_SECURITY_BLOCKING=1` is set **only** on the
  `prefer-lowest` step, whose purpose is to test exactly the declared floors.

**Verification limit:** CI does not run until the repository has a GitHub
remote (none exists yet). §9 covers the combinations runnable locally on PHP
8.4; PHP 8.2 and 8.5 lanes are first exercised once the repository is pushed.

## 3. Livewire 3/4 Component Registration

### 3.1 Root cause

The package registers its five components with explicit names:
`Livewire::component('feedback-board::feedback-form', FeedbackForm::class)`.

- **Livewire 3** keys explicit registrations (`ComponentRegistry`) by the full
  name, so `feedback-board::feedback-form` resolves.
- **Livewire 4** parses any name containing `::` as `namespace::component`
  (`Finder::parseNamespaceAndName`). `resolveClassComponentClassName()` then
  consults **only** the registered class namespaces for that prefix and returns
  `null` when the namespace is unknown — it never falls through to the explicit
  registry (identical in 4.0.0 and 4.4.7). Result: `ComponentNotFoundException:
  Unable to find component: [feedback-board::feedback-form]` (all 19 probe
  failures).

### 3.2 Change — `src/FeedbackBoardServiceProvider.php::packageBooted()`

Immediately before the five existing `Livewire::component(...)` calls, add:

```php
// Livewire 4 resolves "namespace::name" through registered class namespaces only;
// Livewire 3 has no addNamespace() and resolves the explicit registrations below.
if (method_exists(app('livewire'), 'addNamespace')) {
    \Livewire\Livewire::addNamespace('feedback-board', classNamespace: 'YousefAman\\FeedbackBoard\\Livewire');
}
```

- `'livewire'` is bound to `LivewireManager` in both majors; v3 (3.8.x) has no
  `addNamespace` under any signature; v4 (4.0.0–4.4.7) has
  `addNamespace($namespace, $viewPath = null, $classNamespace = null, …)`, so
  the named argument is valid through the facade.
- The five `Livewire::component(...)` calls **stay** on both versions: Livewire 3
  needs them for name → class; Livewire 4 uses them for class → name
  (`Finder::normalizeName`, e.g. `Livewire::test(FeedbackForm::class)`).
- **Feature detection, not version detection:** `method_exists` on the bound
  manager instead of `InstalledVersions`, so it tracks the actual API.
- Livewire 4 maps `feedback-board::feedback-form` → `…\Livewire\FeedbackForm`
  via studly-casing (`Finder::generateClassFromName`); all five names map to
  their existing class names.
- **Side effect (accepted):** on Livewire 4 any class placed in
  `src/Livewire/` becomes addressable as `feedback-board::<kebab-name>`. Only
  the five package components live there.
- Public component names are unchanged — no README or layout changes for users.
- No other Livewire 4 breaking change affects the package (reviewed: no
  `<livewire:>` tags, `.blur`/`.change` modifiers, `wire:transition`,
  `wire:scroll`, `stream()`, layout config or full-page component routes;
  `@livewire(..., key())`, `@livewireStyles`/`@livewireScripts`,
  `WithFileUploads`, `#[On]`, `dispatch` all exist in v4; the string id sent by
  `$wire.moveTo` is coerced on both majors).

### 3.3 Dead line removal

Delete from `packageBooted()`:

```php
\Illuminate\Support\Facades\Blade::componentNamespace('YousefAman\\FeedbackBoard\\View\\Components', 'feedback-board');
```

`src/View/Components` does not exist. `<x-feedback-board::launcher />` is an
anonymous component under `resources/views/components/`:
`ComponentTagCompiler::findClassByComponent` checks `class_exists` and falls
through, and `guessAnonymousComponentUsingNamespaces` resolves
`feedback-board::components.launcher` (verified on Laravel 12 and 13). The
adjacent comment explaining anonymous auto-registration stays.

### 3.4 Tests

1. **Name resolution** (runs on whichever Livewire major is installed): each of
   `feedback-form`, `my-reports-board`, `feedback-item-modal`,
   `feedback-kanban`, `feedback-detail` under the `feedback-board::` prefix
   resolves to its class. **[R]** Use `Livewire::new($name)` (both majors);
   do not use `Livewire::exists()` (v4-only).
2. **Launcher render** (currently untested): rendering
   `<x-feedback-board::launcher />` as an authenticated user succeeds and the
   output contains the nested feedback form. Proves the §3.3 deletion is safe
   and covers the nested `@livewire('feedback-board::feedback-form')`.

## 4. [R] Filament-Only Views

### 4.1 Problem

`resources/views/filament/board-page.blade.php` wraps its content in
`<x-filament-panels::page>`. `->hasViews()` registers all of `resources/views`
as the `feedback-board` hint path, and `view:cache` (run by `optimize`)
compiles every file on every hint path (`ViewCacheCommand::paths()`). Component
tags are resolved at **compile** time, so in an app without Filament the
command throws `InvalidArgumentException: Unable to locate a class or view for
component [filament-panels::page]`. Reproduced in the package's default
`TestCase` (Filament installed but not registered) on all four
Laravel × Livewire combinations.

### 4.2 Change

- Move `resources/views/filament/` (`board-page`, `runner-result`,
  `triage-suggestion`) to **`resources/views-filament/filament/`**, keeping the
  relative names.
- In `packageBooted()`, when Filament's panel service provider is registered in
  the application, add that directory to the **same** `feedback-board`
  namespace:

  ```php
  if ($this->app->providerIsLoaded(\Filament\FilamentServiceProvider::class)) {
      $this->loadViewsFrom(__DIR__.'/../resources/views-filament', 'feedback-board');
  }
  ```

  `providerIsLoaded` reflects **registration**, and every provider is
  registered before any provider boots, so the check does not depend on boot
  order. On Filament 4, `Filament\FilamentServiceProvider` is the provider that
  registers the `filament-panels` package name (verified in vendor); the same
  is confirmed on Filament 5 during implementation.
- View names stay `feedback-board::filament.board-page` etc., so
  `FeedbackBoardPage::$view` and the action modal views need no reference
  changes, and a host override at
  `resources/views/vendor/feedback-board/filament/…` still takes precedence.
- Accepted limitation: `vendor:publish --tag=feedback-board-views` publishes
  `resources/views` only, so the three Filament views are not auto-published
  (they remain overridable manually at the vendor path above).

### 4.3 Tests

1. In the default `TestCase` (Filament installed, not registered):
   `Artisan::call('view:cache')` returns `0`.
2. In the Filament smoke `TestCase` (§9.2): `view:cache` returns `0` and the
   board page renders (proves the conditional path is loaded).

## 5. Developer Access API (`config:cache`-safe)

### 5.1 Problem

- `authorization.developer_resolver` is read only from config
  (`FeedbackBoardServiceProvider:43-46`), and both the README and the config
  file's own comment show a closure (`fn ($user) => …`). Laravel's
  `config:cache` (run by `optimize`) cannot serialize closures and fails. The
  failure is invisible in local development, where config is not cached, and
  surfaces on first deploy.
- `notifications.developers` has the same issue when set to a closure, which the
  README also suggests.
- These are the only two config callables in the package (verified: no other
  `is_callable` config reads).

### 5.2 New units

**`src/FeedbackBoard.php`** — `YousefAman\FeedbackBoard\FeedbackBoard`, bound
as a **singleton** in `packageRegistered()` (same pattern as
`DestinationManager` / `TriageManager`). **[R]** Public surface:

```php
// Registration (user-facing, on the facade)
public function resolveDevelopersUsing(callable $callback): void;  // fn (Authenticatable $user): bool
public function notifyDevelopersUsing(callable $callback): void;   // fn (): iterable<notifiable>

// Query (user-facing, on the facade) — goes through the gate
public function isDeveloper(Authenticatable $user): bool;          // Gate::forUser($user)->allows('feedback-board.manage')

// Internal (@internal, not on the facade)
public function resolveDeveloper(Authenticatable $user): bool;     // raw precedence, used only by the gate definition
public function developerRecipients(): Collection;                 // used only by DeveloperRecipients
```

**[R]** `isDeveloper()` deliberately answers through the gate, the same path
every package check uses (`FeedbackItemPolicy::isDeveloper` →
`Gate::forUser()->allows('feedback-board.manage')`). That path honours
`Gate::before`/`after` hooks (Filament Shield, Spatie Permission super-admin)
and gate overrides, so host code can never get a different answer than the
package enforces. The raw resolver is `resolveDeveloper()`, internal only.

Callbacks are stored as `Closure` (`Closure::fromCallable`).

**`src/Facades/FeedbackBoard.php`** — facade over the singleton, with
`@method static` docblocks for `resolveDevelopersUsing`,
`notifyDevelopersUsing` and `isDeveloper` only. User code, in
`AppServiceProvider::boot()`:

```php
use YousefAman\FeedbackBoard\Facades\FeedbackBoard;

FeedbackBoard::resolveDevelopersUsing(fn ($user) => $user->is_admin);
FeedbackBoard::notifyDevelopersUsing(fn () => User::where('is_admin', true)->get());
```

A container singleton (not static properties) is deliberate: every Testbench
application starts clean (no state leaking between tests), and it behaves
correctly under Octane and queue workers, where the value is set once per
worker at boot. The gate resolves `FeedbackBoard` at check time and captures no
state; `SendFeedbackNotifications` is constructed early by `Event::subscribe`,
but `DeveloperRecipients` reads lazily on each event, so nothing goes stale.

**[R]** Both new files declare `declare(strict_types=1);` — `tests/ArchTest.php`
enforces it for every `YousefAman\FeedbackBoard` class.

### 5.3 Precedence (existing behaviour preserved as the fallback)

`resolveDeveloper($user)`:
1. Callback registered via `resolveDevelopersUsing` → `(bool) $callback($user)`.
2. Else `config('feedback-board.authorization.developer_resolver')`:
   - `null` → `false` (unchanged default-deny);
   - `is_callable` → `(bool) $resolver($user)` (unchanged). A **public static
     method** callable (`[DeveloperCheck::class, 'handle']` or
     `'App\…\DeveloperCheck::handle'`) is `config:cache`-safe;
   - **[R]** any other non-null value → throw `InvalidArgumentException` naming
     the key and the two supported forms. On PHP 8.4,
     `is_callable([X::class, 'nonStaticMethod'])` and `is_callable(X::class)`
     for an invokable class are both `false`; today that silently denies every
     user. Failing loudly is still deny-by-default (the gate check errors
     instead of granting).
3. **[R]** A registered callback silently takes precedence over config values;
   this is documented (§8).

`developerRecipients()`:
1. Callback registered via `notifyDevelopersUsing` → `collect($callback())`.
2. Else `notifications.developers`: callable → `collect($config())`; otherwise
   **[R]** each entry that passes `FILTER_VALIDATE_EMAIL` becomes
   `Notification::route('mail', $email)`; invalid entries are skipped and a
   single `Log::warning` names the key (§6).

### 5.4 Changes to existing code

- **`FeedbackBoardServiceProvider::packageBooted()`** — the
  `feedback-board.manage` gate becomes
  `Gate::define('feedback-board.manage', fn ($user) => app(FeedbackBoard::class)->resolveDeveloper($user));`.
  The parameter stays untyped and non-nullable, so `Gate::parameterAllowsGuests`
  still denies guests before the callback runs (unchanged). Every caller passes
  an authenticated user; null-actor paths (`IngestController`, `RunnerIngest`)
  never reach the gate.
- **`src/Support/DeveloperRecipients.php`** — `resolve()` delegates to
  `app(FeedbackBoard::class)->developerRecipients()`. It remains the single
  entry point used by `SendFeedbackNotifications` (constructor injection) and
  `DeliverToDestination::notifyDevelopers()`; neither caller changes.
  `DeliverToDestination`'s existing filter (keep only Eloquent models for the
  Filament database notification) applies unchanged to API-registered
  recipients.
- **`config/feedback-board.php`** — replace the `e.g. fn ($user) => …` comment
  on `developer_resolver` and the `callable` wording on `developers` with: a
  closure here breaks `config:cache`; prefer
  `FeedbackBoard::resolveDevelopersUsing()` / `notifyDevelopersUsing()` in
  `AppServiceProvider::boot()`, or a **public static method** callable
  (`[Class::class, 'method']`). The `channels` default changes per §6.2; other
  defaults are unchanged.

### 5.5 Tests

1. A registered `resolveDevelopersUsing` callback grants
   `feedback-board.manage` when it returns `true` and denies when `false`.
2. A registered callback takes precedence over a config resolver that returns
   the opposite.
3. A public-static-method callable in `authorization.developer_resolver` still
   works.
4. With neither configured, access is denied (default-deny preserved).
5. **[R]** A non-null, non-callable `developer_resolver` (e.g. an invokable
   class name string) throws `InvalidArgumentException` on a gate check.
6. **[R]** `FeedbackBoard::isDeveloper()` honours a `Gate::before` hook that
   grants access even when the resolver returns `false`.
7. `notifyDevelopersUsing` recipients receive the new-report notification
   (`SendFeedbackNotifications`) and are the targets of the delivery-failure
   database notification (`DeliverToDestination`).
8. **Permanent guard:** the shipped `config/feedback-board.php`, loaded raw,
   **[R]** and `config('feedback-board')` in a freshly booted app (what
   `config:cache` actually serializes, via `getFreshConfiguration`) both
   round-trip through `var_export` + evaluation. Any future closure in the
   shipped config fails this test.

Existing tests that set `developer_resolver` to a closure through
`config()->set()` at runtime remain valid: runtime config is never cached.

## 6. [R] Notification Reliability

### 6.1 Problem (verified)

`CreateFeedbackItem`, `ReplyToFeedback` and `TransitionStatus` dispatch their
events inside `DB::transaction`. `SendFeedbackNotifications` is synchronous and
the three notifications are not queued, so a thrown exception escapes the
transaction and rolls it back. Reproduced:

- **Fresh app, no configuration:** the default channels are
  `['mail', 'database']`; for model notifiables (the reporter) the `database`
  channel needs a `notifications` table that a fresh Laravel 12/13 app does not
  have. A developer reply (→ `FeedbackRepliedNotification` to the reporter)
  and resolving an item (→ `FeedbackResolvedNotification`) both fail and are
  rolled back — the comment is not saved, the status stays `new`.
- **Model developer recipients** (the recommended
  `notifyDevelopersUsing(fn () => User::…->get())`) hit the same table error
  on every new report → **the report is lost**.
- **Mail transport outage** (unreachable SMTP) → `TransportException` → report
  lost.
- **The README's `[1, 2]` example**: `MailChannel::getRecipients` reads
  `->email` on an int → `ErrorException` → every submission lost. (It is not
  "mailed to the address `1`" as the first spec draft said.)

### 6.2 Change

- **`src/Listeners/SendFeedbackNotifications.php`** — every send
  (`Notification::send(...)` / `->notify(...)`) is wrapped as
  `DB::afterCommit(fn () => rescue(fn () => …, report: true))`:
  - **after commit:** notifications are sent only once the action is durably
    saved; if the transaction rolls back for another reason, nothing is sent.
    Outside a transaction `afterCommit` runs immediately.
  - **best-effort:** `rescue()` reports the exception through the host's
    exception handler (logged, visible in error tracking) and never propagates,
    so a notification failure can no longer lose or fail the user's action.
  - Recipient resolution happens inside the callback (lazy, post-commit).
- **`config/feedback-board.php`** — `notifications.channels` default changes
  from `['mail', 'database']` to **`['mail']`**. `database` becomes an explicit
  opt-in, documented as requiring `php artisan make:notifications-table` +
  `migrate` (and `->databaseNotifications()` on the Filament panel for the
  bell). The package is unreleased, so this default change has no
  compatibility cost.
- **Recipient validation** — §5.3 (`FILTER_VALIDATE_EMAIL`, skip + one
  `Log::warning`).
- `DeliverToDestination::notifyDevelopers()` already sends best-effort in a
  `try/catch` and runs in a queued job outside the user's transaction —
  unchanged.
- Notifications stay synchronous (sent in the request after commit). A queued
  option is deferred (§11).

### 6.3 Tests

1. Model developer recipients + `channels = ['mail', 'database']` + no
   `notifications` table → creating a report **persists the item**, and the
   exception is reported (`Exceptions::fake()` → `assertReported`).
2. A mail channel that throws (failing transport) → the report persists and the
   failure is reported.
3. Developer reply with a failing reporter notification → the comment persists;
   resolve with a failing reporter notification → the status change persists.
4. A transaction that rolls back after the event is dispatched → no
   notification is sent (`Notification::fake()`).
5. `notifications.developers = ['dev@example.com', 1, 'not-an-email']` → only
   `dev@example.com` is notified; one warning is logged.
6. Default config → `NewFeedbackNotification::via()` for a model notifiable
   returns `['mail']`.

## 7. Install Command — `feedback-board:install`

### 7.1 Behaviour

Registered in `configurePackage()` via Spatie's `hasInstallCommand`. Spatie
derives the command name from the package name: `feedback-board:install`.
**[R]** Spatie marks install commands `$hidden = true` (1.19 and 1.93), so it
does not appear in `php artisan list`; the README names it explicitly.

```php
->hasInstallCommand(function (InstallCommand $command) {
    $command
        ->publishConfigFile()
        ->publishMigrations()
        ->askToRunMigrations()
        ->endWith(function (InstallCommand $command) {
            // print next steps (listed below)
        });
});
```

Steps: publish config → publish migrations → confirm "Would you like to run the
migrations now?" (default **no**; under `--no-interaction` the default is
taken) → next steps. **[R]** The position of Spatie's "feedback-board has been
installed!" line differs by version (1.19.0 prints it before `endWith`, 1.93
after); nothing depends on that order.

Next steps printed by `endWith` (English console output):
1. Add `<x-feedback-board::launcher />` to the authenticated layout.
2. In `AppServiceProvider::boot()`, call
   `FeedbackBoard::resolveDevelopersUsing(...)` and
   `FeedbackBoard::notifyDevelopersUsing(...)` (§5).
3. **[R]** Optional in-app notifications: run
   `php artisan make:notifications-table`, migrate, and add `'database'` to
   `feedback-board.notifications.channels` (§6.2).
4. **Only if `class_exists(\Filament\Panel::class)`:** register
   `FeedbackBoardPlugin::make()` in the panel provider.
5. A pointer to the README for destinations, AI triage and the runner.

Migrations are published in the existing `hasMigrations([...])` order with
Spatie's sequential timestamps (verified: items → comments → attachments →
activities), so `feedback_items` is created before the tables that reference it.

### 7.2 Re-run safety (verified in Spatie 1.19.0 and 1.93.1 source)

- Publishing calls `vendor:publish --tag=feedback-board-{config,migrations}`
  **without `--force`**: an existing, user-edited `config/feedback-board.php` is
  never overwritten.
- `generateMigrationName()` (in `PackageServiceProvider` in 1.19.0, in
  `ProcessMigrations` in 1.93) reuses the filename of an already-published
  migration with the same suffix, so a later run does not create duplicates.
  Laravel's `ensureMigrationNameIsUpToDate` does not apply (Spatie uses
  `publishes()`, not `publishesMigrations()`).

### 7.3 Tests

1. Run with `expectsConfirmation('Would you like to run the migrations now?', 'no')`:
   the config file and exactly four migrations are published; **[R]** using
   unordered `expectsOutputToContain`, the output contains the launcher,
   `FeedbackBoard::resolveDevelopersUsing`, `make:notifications-table` and —
   Filament being installed in dev — plugin steps. (`--no-interaction` cannot
   be exercised through `$this->artisan()`; it was verified manually with
   `vendor/bin/testbench feedback-board:install --no-interaction`.)
2. **[R]** Re-run: run once, edit the published config, then
   `$this->travel(2)->minutes()` and
   `$this->app->register(FeedbackBoardServiceProvider::class, force: true)` (so
   Spatie recomputes the publish map with a new timestamp — without this the
   test cannot detect a broken filename-reuse), run again: still exactly four
   migrations, and the edit survives.
3. **[R]** Published files are removed in both `beforeEach` and `afterEach`
   (the Testbench skeleton is shared across the suite, and a stale published
   `config/feedback-board.php` there would be loaded into every later app). The
   suite's own migrations (`defineDatabaseMigrations` → `loadMigrationsFrom`
   with `--path`) are unaffected. These tests are not safe under
   `pest --parallel`, which the suite does not use.

**Not automated (accepted):** the "Filament absent" branch — Filament is always
present in dev; the branch is a single `class_exists` check.

## 8. Documentation

Only the parts this work touches:

- **Requirements** (`README.md` §Requirements): PHP 8.2+, Laravel 12/13,
  Livewire 3.8.3+/4.3.4+, Filament 4/5 (optional).
- **Installation**: primary path becomes `composer require …` then
  `php artisan feedback-board:install` (named explicitly — the command is hidden
  from `artisan list`). The manual `vendor:publish` commands remain as the
  alternative; views/translations publishing stays optional.
- **Usage §2 (developer resolver)**: replace the config-closure example with
  `FeedbackBoard::resolveDevelopersUsing()` in `AppServiceProvider::boot()`;
  state that a closure in the config file breaks `config:cache` (without
  quoting the exact error text, which differs between Laravel versions); show
  the **public static method** alternative; state that a registered callback
  overrides config values.
- **Usage §3 (developer notifications)**: replace the `[1, 2]` example with an
  e-mail array (or `FEEDBACK_BOARD_DEVELOPER_EMAIL`) and
  `FeedbackBoard::notifyDevelopersUsing()` for model notifiables; drop the
  config-closure alternative; state that a registered callback overrides the
  config e-mails.
- **[R] Notifications**: default channel is `mail`; how to enable `database`
  (§6.2); notifications are sent after the action is saved and a delivery
  failure is reported, never lost.
- **[R] Filament-only hosts**: a snippet showing the launcher rendered inside a
  panel via a render hook
  (`$panel->renderHook(PanelsRenderHook::BODY_END, fn () => Blade::render('<x-feedback-board::launcher />'))`),
  for apps whose reporters use a Filament panel rather than a custom layout.
- **Configuration reference table**: update the
  `authorization.developer_resolver`, `notifications.developers` and
  `notifications.channels` rows to match §5–§6.
- The README's "floating button … modal overlay" description of the launcher is
  left for the styling spec, which makes it true.

## 9. Verification Before Merge

### 9.1 Local runs

1. Full suite on the repository's resolved stack (Laravel 12 / Livewire 3 /
   Filament 4) after `composer update`.
2. Full suite in throwaway copies (scratchpad), PHP 8.4:
   - Laravel 13 / Livewire 4 / Filament 5
   - Laravel 12 / Livewire 4 / Filament 5
   - Laravel 13 / Livewire 3 / Filament 4
   - **[R]** `prefer-lowest`, Livewire 3 lane and Livewire 4 lane, run with a
     Composer ≥ 2.9 phar downloaded into the scratchpad and
     `COMPOSER_NO_SECURITY_BLOCKING=1` (matching CI); record the Composer
     version and the resolved floor versions.
3. **[R]** `composer validate --strict` after `composer update` (composer.lock
   is gitignored and goes stale after the constraint edit), or with
   `--no-check-lock`.

### 9.2 [R] Filament smoke coverage

The default `TestCase` registers only the package and Livewire providers, so
the existing suite never boots a panel — "passes on Filament 5" would otherwise
mean nothing for `src/Filament/*`. Add a dedicated `FilamentTestCase` with a
test panel provider registering `FeedbackBoardPlugin`, and tests that, as a
developer, `GET` the board page and the feedback-items resource index return
`200` and show an item, and as a non-developer return `403`. It runs on
whichever Filament major the lane installs (4 on Livewire 3 lanes, 5 on
Livewire 4 lanes).

**Provider-order gotcha:** on Filament 4, Filament's `SupportServiceProvider`
must be registered **before** `LivewireServiceProvider` (it binds `DataStore`
to `DataStoreOverride`); otherwise every render fails with
`ViewErrorBag::put(): Argument #2 … null given`.

### 9.3 Not verifiable locally

PHP 8.2 and 8.5 lanes (CI after push), and browser-side JavaScript on
Livewire 4 — Kanban drag-and-drop (`$wire.moveTo`) and attachment uploads —
which the PHP suite does not execute. The author's dogfooding covers the
browser path (after the styling spec).

Work happens on `feature/v1-release-readiness`, merged to `master` like prior
phases.

## 10. Files

| File | Change |
|------|--------|
| `composer.json` | constraints per §2.2; `suggest`; remove `minimum-stability`/`prefer-stable` |
| `.github/workflows/tests.yml` | matrix per §2.3 |
| `src/FeedbackBoardServiceProvider.php` | Livewire `addNamespace` (§3.2); remove dead `componentNamespace` (§3.3); conditional Filament view path (§4.2); `FeedbackBoard` singleton + gate via `resolveDeveloper` (§5.4); `hasInstallCommand` (§7.1) |
| `resources/views/filament/*` → `resources/views-filament/filament/*` | move (§4.2) |
| `src/FeedbackBoard.php` | new — developer access/recipients (§5.2) |
| `src/Facades/FeedbackBoard.php` | new — facade (§5.2) |
| `src/Support/DeveloperRecipients.php` | delegate to `FeedbackBoard` (§5.4) |
| `src/Listeners/SendFeedbackNotifications.php` | after-commit, best-effort sends (§6.2) |
| `config/feedback-board.php` | comments (§5.4); `notifications.channels` default `['mail']` (§6.2) |
| `README.md` | §8 |
| `tests/…` | §3.4, §4.3, §5.5, §6.3, §7.3, §9.2 (`FilamentTestCase` + test panel provider) |

## 11. Deferred

- **UI styling — separate spec, next, before dogfooding** (§1).
- Pre-publish packaging (§1 out of scope).
- Phase 2-D hub aggregation → v1.1.
- Queued notifications (opt-in `ShouldQueue`), if synchronous post-commit
  sending proves slow in practice.
- A registry for other config callables, should more be added later (none
  exist today besides the two in §5).
