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

## 1. Overview & Scope

The package cannot be installed on the current mainstream stack today:
`composer.json` requires `livewire/livewire ^3.0` while advertising Filament
`^5`, but Filament 5 requires Livewire `^4` — so any Filament 5 app fails to
resolve. Laravel 13 is not allowed at all. Separately, the only documented way
to configure developer access puts closures in `config/feedback-board.php`,
which makes `php artisan config:cache` / `optimize` fail in production.

**In scope**
- Widened, honest dependency constraints + a CI matrix that exercises them (§2).
- Dual Livewire 3/4 component registration; removal of a dead Blade
  `componentNamespace` line (§3).
- A `FeedbackBoard` facade for developer access/recipients that is safe under
  `config:cache` (§4).
- A `feedback-board:install` Artisan command (§5).
- README/config documentation for the above, plus correcting the wrong
  `notifications.developers` example (§6).

**Out of scope (pre-publish step, separate)**
- README Roadmap rewrite; completing the config reference table with `ai.*` /
  `runner.*` keys.
- `LICENSE`, `CHANGELOG.md`, `.gitattributes` export-ignore rules.
- Pushing to GitHub, tagging `v1.0.0`, Packagist submission.
- Phase 2-D hub aggregation (v1.1).

## 2. Dependency Matrix & CI

### 2.1 Support matrix

| | Supported |
|---|---|
| PHP | `^8.2` |
| Laravel | 12, 13 |
| Livewire | 3, 4 |
| Filament (optional) | 4 (with Livewire 3), 5 (with Livewire 4) |

Laravel 11 is dropped: its security support ended March 2026 and the package
has no released users, so dropping it has no compatibility cost.

### 2.2 `composer.json`

| Package | Current | New |
|---|---|---|
| `illuminate/contracts` | `^11.0 \|\| ^12.0` | `^12.0 \|\| ^13.0` |
| `livewire/livewire` | `^3.0` | `^3.0 \|\| ^4.0` |
| `spatie/laravel-package-tools` | `^1.15.0` | `^1.19` |
| `orchestra/testbench` (dev) | `^9.0 \|\| ^10.0` | `^10.0 \|\| ^11.0` |
| `pestphp/pest` (dev) | `^3.0` | `^3.0 \|\| ^4.0` |
| `pestphp/pest-plugin-laravel` (dev) | `^3.0` | `^3.0 \|\| ^4.0` |
| `filament/filament` (dev) | `^4.0 \|\| ^5.0` | unchanged |
| `php` | `^8.2` | unchanged |

Add a `suggest` entry:

```json
"suggest": {
    "filament/filament": "^4.0 || ^5.0 — developer Kanban page, resource and database notifications"
}
```

Facts the constraints rely on (verified against Packagist 2026-10-07):
- `spatie/laravel-package-tools` 1.15.0 supports only Laravel 9/10; **1.19.0 is
  the first release allowing Laravel 12**, so the floor `^1.19` is the honest
  minimum. It also provides everything §5 uses (`hasInstallCommand`,
  `publishConfigFile`, `publishMigrations`, `askToRunMigrations`, `endWith`).
- Laravel 13 requires PHP `^8.3`; Pest 4 requires PHP `^8.3`; Pest 3 does not
  allow Laravel 13. Hence the `^3.0 || ^4.0` Pest range: PHP 8.2 lanes resolve
  Pest 3 + Laravel 12, PHP ≥ 8.3 lanes may resolve Pest 4.
- Testbench 10 ↔ Laravel 12, Testbench 11 ↔ Laravel 13.
- Livewire 3.8 allows Laravel 13; Filament 4 allows Laravel 12/13 and requires
  Livewire `^3.5`; Filament 5 requires Livewire `^4.0`. All four
  Laravel × Livewire combinations are therefore installable, and Composer picks
  the Filament major that matches the Livewire lane.

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
    name: PHP ${{ matrix.php }} · L${{ matrix.laravel }} · LW${{ matrix.livewire }} · ${{ matrix.stability }}
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          coverage: none
      - run: composer require "laravel/framework:${{ matrix.laravel }}" "livewire/livewire:${{ matrix.livewire }}" --no-update --no-interaction
      - run: composer update --${{ matrix.stability }} --prefer-dist --no-interaction
      - run: vendor/bin/pest
```

14 `prefer-stable` jobs (16 combinations minus the two PHP 8.2 + Laravel 13
lanes) plus one `prefer-lowest` job, which proves the declared floors resolve
and pass. The `include` entry introduces a new `stability` value, so GitHub
adds it as a separate job rather than merging it into an existing one.

**Verification limit:** CI does not run until the repository has a GitHub
remote (none exists yet). Before merge, the local verification in §7 covers the
combinations runnable on the local PHP 8.4; PHP 8.2 and 8.5 lanes are first
exercised once the repository is pushed.

## 3. Livewire 3/4 Component Registration

### 3.1 Root cause

The package registers its five components with explicit names:
`Livewire::component('feedback-board::feedback-form', FeedbackForm::class)`.

- **Livewire 3** keys explicit registrations by the full name, so
  `feedback-board::feedback-form` resolves.
- **Livewire 4** parses any name containing `::` as `namespace::component`
  (`Finder::parseNamespaceAndName`). `resolveClassComponentClassName()` then
  consults **only** the registered class namespaces for that prefix and returns
  `null` when the namespace is unknown — it never falls through to the explicit
  registry. Result: `ComponentNotFoundException: Unable to find component:
  [feedback-board::feedback-form]` (all 19 probe failures).

### 3.2 Change — `src/FeedbackBoardServiceProvider.php::packageBooted()`

Immediately before the five existing `Livewire::component(...)` calls, add:

```php
// Livewire 4 resolves "namespace::name" through registered class namespaces only;
// Livewire 3 has no addNamespace() and resolves the explicit registrations below.
if (method_exists(app('livewire'), 'addNamespace')) {
    \Livewire\Livewire::addNamespace('feedback-board', classNamespace: 'YousefAman\\FeedbackBoard\\Livewire');
}
```

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

### 3.3 Dead line removal

Delete from `packageBooted()`:

```php
\Illuminate\Support\Facades\Blade::componentNamespace('YousefAman\\FeedbackBoard\\View\\Components', 'feedback-board');
```

`src/View/Components` does not exist. `<x-feedback-board::launcher />` is an
anonymous component under `resources/views/components/`, resolved by Blade
through the `feedback-board` view namespace registered by `->hasViews()`
(`feedback-board::components.launcher`). The adjacent comment explaining
anonymous auto-registration stays.

### 3.4 Tests

1. **Name resolution** (runs on whichever Livewire major is installed): each of
   `feedback-form`, `my-reports-board`, `feedback-item-modal`,
   `feedback-kanban`, `feedback-detail` under the `feedback-board::` prefix
   resolves to its class. This makes the probe's failure mode an explicit,
   named test instead of collateral damage in 19 unrelated tests.
2. **Launcher render** (currently untested): rendering
   `<x-feedback-board::launcher />` as an authenticated user succeeds and the
   output contains the nested feedback form. Proves the §3.3 deletion is safe
   and covers the nested `@livewire('feedback-board::feedback-form')`.

## 4. Developer Access API (`config:cache`-safe)

### 4.1 Problem

- `authorization.developer_resolver` is read only from config
  (`FeedbackBoardServiceProvider:43-46`), and both the README and the config
  file's own comment show a closure (`fn ($user) => …`). Laravel's
  `config:cache` (run by `optimize`) cannot serialize closures and fails with
  `Your configuration files are not serializable.` The failure is invisible in
  local development, where config is not cached, and surfaces on first deploy.
- `notifications.developers` has the same issue when set to a closure, which the
  README also suggests.

### 4.2 New units

**`src/FeedbackBoard.php`** — `YousefAman\FeedbackBoard\FeedbackBoard`, bound
as a **singleton** in `packageRegistered()` (same pattern as
`DestinationManager` / `TriageManager`):

```php
public function resolveDevelopersUsing(callable $callback): void;  // fn (Authenticatable $user): bool
public function notifyDevelopersUsing(callable $callback): void;   // fn (): iterable<notifiable>
public function isDeveloper(Authenticatable $user): bool;
public function developerRecipients(): Collection;
```

Callbacks are stored as `Closure` (`Closure::fromCallable`).

**`src/Facades/FeedbackBoard.php`** — facade over the singleton, with
`@method static` docblocks for the four methods. User code, in
`AppServiceProvider::boot()`:

```php
use YousefAman\FeedbackBoard\Facades\FeedbackBoard;

FeedbackBoard::resolveDevelopersUsing(fn ($user) => $user->is_admin);
FeedbackBoard::notifyDevelopersUsing(fn () => User::where('is_admin', true)->get());
```

A container singleton (not static properties) is deliberate: every Testbench
application starts clean (no state leaking between tests), and it behaves
correctly under Octane, where the value is set once per worker at boot.

### 4.3 Precedence (existing behaviour preserved as the fallback)

`isDeveloper($user)`:
1. Callback registered via `resolveDevelopersUsing` → `(bool) $callback($user)`.
2. Else `config('feedback-board.authorization.developer_resolver')` if
   `is_callable` → `(bool) $resolver($user)` (unchanged; a static callable such
   as `[DeveloperCheck::class, 'handle']` or `'App\…\DeveloperCheck::handle'`
   is `config:cache`-safe).
3. Else `false` (unchanged default-deny).

`developerRecipients()`:
1. Callback registered via `notifyDevelopersUsing` → `collect($callback())`.
2. Else the current `DeveloperRecipients` logic, moved verbatim:
   `notifications.developers` callable → `collect($config())`; otherwise an
   array of e-mail addresses → `Notification::route('mail', $email)` each.

### 4.4 Changes to existing code

- **`FeedbackBoardServiceProvider::packageBooted()`** — the
  `feedback-board.manage` gate becomes
  `Gate::define('feedback-board.manage', fn ($user) => app(FeedbackBoard::class)->isDeveloper($user));`.
  The parameter stays untyped and non-nullable, so guests are still denied by
  the gate before the callback runs (unchanged).
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
  `AppServiceProvider::boot()`, or a static callable
  (`[Class::class, 'method']`). Default values are unchanged.

### 4.5 Tests

1. A registered `resolveDevelopersUsing` callback grants
   `feedback-board.manage` when it returns `true` and denies when `false`.
2. A registered callback takes precedence over a config resolver that returns
   the opposite.
3. A static-method callable in `authorization.developer_resolver` still works.
4. With neither configured, access is denied (default-deny preserved).
5. `notifyDevelopersUsing` recipients receive the new-report notification
   (`SendFeedbackNotifications`) and are the targets of the delivery-failure
   database notification (`DeliverToDestination`).
6. **Permanent guard:** the shipped `config/feedback-board.php`, loaded raw,
   round-trips through `var_export` + evaluation — the same serializability
   check `config:cache` relies on. Any future closure added to the shipped
   config fails this test.

Existing tests that set `developer_resolver` to a closure through
`config()->set()` at runtime remain valid: runtime config is never cached.

## 5. Install Command — `feedback-board:install`

### 5.1 Behaviour

Registered in `configurePackage()` via Spatie's `hasInstallCommand`. Spatie
derives the command name from the package name: `feedback-board:install`.

```php
->hasInstallCommand(function (InstallCommand $command) {
    $command
        ->publishConfigFile()
        ->publishMigrations()
        ->askToRunMigrations()
        ->endWith(function (InstallCommand $command) {
            // print next steps (below)
        });
});
```

Order of execution (Spatie's `handle()`): publish config → publish migrations →
confirm "Would you like to run the migrations now?" (default **no**; under
`--no-interaction` the default is taken) → `endWith` → "feedback-board has been
installed!".

Next steps printed by `endWith` (English console output):
1. Add `<x-feedback-board::launcher />` to the authenticated layout.
2. In `AppServiceProvider::boot()`, call
   `FeedbackBoard::resolveDevelopersUsing(...)` and
   `FeedbackBoard::notifyDevelopersUsing(...)` (§4).
3. **Only if `class_exists(\Filament\Panel::class)`:** register
   `FeedbackBoardPlugin::make()` in the panel provider.
4. A pointer to the README for destinations, AI triage and the runner.

Migrations are published in the existing `hasMigrations([...])` order with
Spatie's sequential timestamps, so `feedback_items` is created before the
comment/attachment/activity tables that reference it.

### 5.2 Re-run safety (verified in Spatie's source)

- `processPublishes()` calls `vendor:publish --tag=feedback-board-{config,migrations}`
  **without `--force`**: an existing, user-edited `config/feedback-board.php` is
  never overwritten.
- `ProcessMigrations::generateMigrationName()` reuses the filename of an
  already-published migration with the same suffix, so a second run does not
  create duplicate migrations.

### 5.3 Tests

1. Run with the migration prompt answered **no**: the config file and exactly
   four migrations are published; the output contains the launcher and
   `FeedbackBoard::resolveDevelopersUsing` steps and — Filament being installed
   in dev — the plugin step.
2. Run twice, with the published config edited between runs: still exactly four
   migrations, and the edit survives.
3. Published files are removed after each test (the Testbench skeleton is shared
   across the suite), and the suite's own migrations
   (`defineDatabaseMigrations` → `loadMigrationsFrom`) are unaffected.

**Not automated (accepted):** the "Filament absent" branch — Filament is always
present in dev; the branch is a single `class_exists` check.

## 6. Documentation

Only the parts this work touches:

- **Requirements** (`README.md` §Requirements): PHP 8.2+, Laravel 12/13,
  Livewire 3/4, Filament 4/5 (optional).
- **Installation**: primary path becomes `composer require …` then
  `php artisan feedback-board:install`. The manual `vendor:publish` commands
  remain as the alternative; views/translations publishing stays optional.
- **Usage §2 (developer resolver)**: replace the config-closure example with
  `FeedbackBoard::resolveDevelopersUsing()` in `AppServiceProvider::boot()`,
  state explicitly that a closure in the config file breaks `config:cache`, and
  show the static-callable alternative.
- **Usage §3 (developer notifications)**: fix the incorrect example — the
  README shows user IDs (`[1, 2]`), but `DeveloperRecipients` treats array
  entries as **e-mail addresses** (an ID would be mailed to the address `"1"`).
  Show an e-mail array (or `FEEDBACK_BOARD_DEVELOPER_EMAIL`) and
  `FeedbackBoard::notifyDevelopersUsing()` for model notifiables; drop the
  config-closure alternative.
- **Configuration reference table**: update the `authorization.developer_resolver`
  and `notifications.developers` rows to match §4.

## 7. Verification Before Merge

1. Full suite on the repository's resolved stack (Laravel 12 / Livewire 3 /
   Filament 4).
2. Full suite in throwaway copies (scratchpad), PHP 8.4:
   - Laravel 13 / Livewire 4 / Filament 5
   - Laravel 12 / Livewire 4 / Filament 5
   - Laravel 13 / Livewire 3 / Filament 4
   - `composer update --prefer-lowest` (Laravel 12 / Livewire 3)
3. `composer validate --strict`.
4. **Not verifiable locally:** PHP 8.2 and 8.5 lanes (CI after push), and
   browser-side JavaScript behaviour on Livewire 4 — Kanban drag-and-drop
   (`$wire.moveTo`) and attachment uploads — which the PHP test suite does not
   execute. Views use only `wire:click`, `wire:model`, `wire:submit`,
   `wire:poll`, `wire:change`, `wire:key` and `$wire` calls, all present in
   Livewire 4; the author's dogfooding covers the browser path.

Work happens on `feature/v1-release-readiness`, merged to `master` like prior
phases.

## 8. Files

| File | Change |
|------|--------|
| `composer.json` | constraints per §2.2; `suggest` |
| `.github/workflows/tests.yml` | matrix per §2.3 |
| `src/FeedbackBoardServiceProvider.php` | Livewire `addNamespace` (§3.2); remove dead `componentNamespace` (§3.3); `FeedbackBoard` singleton + gate via `isDeveloper` (§4.4); `hasInstallCommand` (§5.1) |
| `src/FeedbackBoard.php` | new — developer access/recipients (§4.2) |
| `src/Facades/FeedbackBoard.php` | new — facade (§4.2) |
| `src/Support/DeveloperRecipients.php` | delegate to `FeedbackBoard` (§4.4) |
| `config/feedback-board.php` | comments only (§4.4) |
| `README.md` | §6 |
| `tests/…` | §3.4, §4.5, §5.3 |

## 9. Deferred

- Pre-publish packaging (§1 out of scope).
- Phase 2-D hub aggregation → v1.1.
- A registry for other config callables, should more be added later (none
  exist today besides the two in §4).
