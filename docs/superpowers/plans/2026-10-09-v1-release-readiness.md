# v1.0 Release Readiness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `yousefaman/feedback-board` installable and deployable on Laravel 12/13 × Livewire 3/4 × Filament 4/5. This covers `config:cache`/`view:cache`-safe operation, notifications that can never lose a user's action, a `feedback-board:install` command, a CI matrix and accurate docs.

**Architecture:** The package keeps its spatie/laravel-package-tools service provider. A new container singleton, `FeedbackBoard`, and its facade replace closures in config for developer access and recipients. The `feedback-board.manage` gate delegates to it. Notification sends move behind `DB::afterCommit` + `rescue()`. A Filament-only Blade view moves to a second view root, which is loaded only when Filament's panel provider is registered. A dedicated Pest test case boots a real Filament panel for smoke coverage.

**Tech Stack:** PHP 8.2+, Laravel 12/13, Livewire 3.8.3+/4.3.4+, Filament 4/5 (optional), spatie/laravel-package-tools ^1.19, Pest 3/4, Orchestra Testbench 10/11, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-10-08-v1-release-readiness-design.md` (approved, hardened). Read it alongside this plan.

## Prototype evidence and spec deltas

All code and tests in this plan were run in a throwaway copy of the repository before the plan was written:

- **230 tests passed on six lanes.** The lanes were:
  - L12.62 / LW3.8.1 / F4.11.7
  - L13.35 / LW4.4.7 / F5.10.1
  - L12.69 / LW4.4.7 / F5.10.1
  - L13.35 / LW3.8.10 / F4.15.1
  - L12.0.1 / LW3.5.20 / F4.0.0 / package-tools 1.19.0
  - L12.0.1 / LW4.0.0 / F5.0.0 / package-tools 1.19.0
- **The five Review Focus tests** were run on the first lane only.
- **The re-run install test is proven to catch a regression.** With Spatie's migration filename reuse disabled, it fails with "actual size 8 matches expected size 4".

The prototype found five places where the plan departs from the spec, each for a stated reason:

1. **§4.2: only `board-page.blade.php` moves to `resources/views-filament/`.** It is the only view that uses a Filament component (`<x-filament-panels::page>`). `runner-result` and `triage-suggestion` are plain Blade. Moving them broke two existing tests that render them without Filament registered (`RunnerFilamentTest`, `TriageFilamentTest`). Keeping them in place also keeps them publishable with `--tag=feedback-board-views`.
2. **§5.2: `isDeveloper()` accepts `?Authenticatable`.** A guest (`null`) returns `false` instead of a `TypeError`. This is Review Focus #2: host code calls `FeedbackBoard::isDeveloper(auth()->user())` in layouts that guests also see.
3. **§9.1.1: the repository resolves L13 / LW4.4.7 / F5.10.1 / Pest 4 after `composer update`, not L12/LW3/F4.** A dry-run on PHP 8.4 confirmed this. The repository stack therefore exposes the Livewire 4 failure as Task 1's red phase, and the L12 / LW3 / F4 combination becomes a scratch lane in Task 8.
4. **§8: the README's Filament page URL is corrected.** It now reads `/admin/feedback-board-page`, the page's actual slug (verified with `FeedbackBoardPage::getUrl()`). It sits inside the Filament section that §8 already edits.
5. **§9.2: `FilamentTestCase` needs a narrower `tests/Pest.php` scope.** Pest rejects two test-case classes on overlapping directories, so `TestCase` is now scoped to `Feature`, `Unit` and `Smoke`, and `FilamentTestCase` to `Filament`. `ArchTest.php` needs no test case.

## Global Constraints

**Dependencies (`composer.json`)**
- `php` `^8.2`
- `illuminate/contracts` `^12.0 || ^13.0`
- `livewire/livewire` `^3.8.3 || ^4.3.4` — this is the security floor
- `spatie/laravel-package-tools` `^1.19`
- dev: `orchestra/testbench` `^10.0 || ^11.0`, `pestphp/pest` `^3.0 || ^4.0`, `pestphp/pest-plugin-laravel` `^3.0 || ^4.0`, `filament/filament` `^4.0 || ^5.0`
- No `minimum-stability` or `prefer-stable` keys.
- `composer.lock` is gitignored. Never commit it.

**Code rules**
- Every class under `src/` starts with `declare(strict_types=1);`, which `tests/ArchTest.php` enforces. Test support classes under `tests/` follow the existing test files and have no `strict_types`.
- Public names stay as they are:
  - Livewire components: `feedback-board::feedback-form`, `::my-reports-board`, `::feedback-item-modal`, `::feedback-kanban`, `::feedback-detail`.
  - The anonymous component `<x-feedback-board::launcher />`.
  - View names: `feedback-board::filament.board-page`, `::filament.runner-result`, `::filament.triage-suggestion`.
- No closure may appear in the shipped `config/feedback-board.php`. `tests/Smoke/ConfigSerializableTest.php` guards this from Task 4 onward.
- Developer access stays deny-by-default. The gate parameter stays untyped and non-nullable, so guests are denied before the callback runs.
- Notifications stay synchronous. They are sent after the action's transaction commits and are best-effort (`rescue(..., report: true)`). The default channel is `['mail']`.
- Match the surrounding style: fully qualified facade calls inline in `FeedbackBoardServiceProvider`, compact Pest tests, English comments and console output.

**Testing and git**
- Run tests with `vendor/bin/pest`. Never pass `--parallel`: the install-command tests publish into the shared Testbench skeleton.
- Stage explicit paths only. Never `git add -A` or `git add .`, because the repository has an untracked `.claude/` that must stay out.
- Commit messages describe the work only. **No `Co-Authored-By` trailer and no Claude, Anthropic or "Generated with" attribution of any kind.** This is the user's standing rule and it overrides any harness default.
- Work happens on branch `feature/v1-release-readiness`, in a worktree created via superpowers:using-git-worktrees, and is merged to `master` like prior phases.

## Review Focus

These are inputs the spec implies but no spec-listed test exercises. Each line names its owning task, and that task carries the test.

1. **Default install with no developer e-mail configured** (`FEEDBACK_BOARD_DEVELOPER_EMAIL` unset). A new report is saved, nothing is sent, and no warning is logged on every report. Owner: Task 4.
2. **`FeedbackBoard::isDeveloper(null)` for a guest**, for example in a layout guests also see. It returns `false` with no `TypeError`. Owner: Task 4 (spec delta 2).
3. **A `notifyDevelopersUsing` callback that throws**, for example when its user query fails. The report is kept and the exception is reported. Owner: Task 5.
4. **A reporter's own reply when the developer notification fails.** The reporter's comment is kept. The spec covers only the developer-reply direction. Owner: Task 5.
5. **A package action wrapped in the host app's own outer transaction that commits.** The notification is sent exactly once, after the outer commit, and not before. Owner: Task 5.

## File Map

| File | Task | Responsibility |
|---|---|---|
| `composer.json` | 1 | Constraints, `suggest`, no stability keys |
| `.github/workflows/tests.yml` | 1 | 14 prefer-stable + 2 prefer-lowest jobs |
| `src/FeedbackBoardServiceProvider.php` | 1, 3, 4, 6 | Livewire `addNamespace`; Filament view root; `FeedbackBoard` singleton + gate; install command |
| `tests/Feature/LivewireRegistrationTest.php` | 1 | Name resolution on both majors; launcher render |
| `tests/Pest.php` | 2 | Test-case scoping |
| `tests/Filament/FilamentTestCase.php` | 2 | Boots a real panel |
| `tests/Filament/TestPanelProvider.php` | 2 | Panel `admin` with `FeedbackBoardPlugin` |
| `tests/Filament/FilamentTestUser.php` | 2 | `FilamentUser` test model |
| `tests/Filament/PanelSmokeTest.php` | 2, 3 | HTTP smoke tests; `view:cache` with Filament |
| `resources/views/filament/board-page.blade.php` → `resources/views-filament/filament/board-page.blade.php` | 3 | Filament-only view |
| `tests/Feature/ViewCacheTest.php` | 3 | `view:cache` without Filament |
| `src/FeedbackBoard.php` | 4 | Developer access and recipients |
| `src/Facades/FeedbackBoard.php` | 4 | Facade |
| `src/Support/DeveloperRecipients.php` | 4 | Delegates to `FeedbackBoard` |
| `config/feedback-board.php` | 4, 5 | Comments; `channels` default |
| `tests/Fixtures/DeveloperCheck.php` | 4 | Static-callable fixture |
| `tests/Feature/DeveloperAccessTest.php` | 4 | §5.5 tests 1–7, §6.3 test 5, Review Focus 1–2 |
| `tests/Smoke/ConfigSerializableTest.php` | 4 | §5.5 test 8 |
| `src/Listeners/SendFeedbackNotifications.php` | 5 | After-commit, best-effort sends |
| `tests/Feature/NotificationReliabilityTest.php` | 5 | §6.3 tests 1–4 and 6, Review Focus 3–5 |
| `tests/Feature/InstallCommandTest.php` | 6 | §7.3 |
| `README.md` | 7 | §8 |

Expected suite size after each task: 200 → **206** (T1) → **209** (T2) → **211** (T3) → **225** (T4) → **233** (T5) → **235** (T6).

---

### Task 1: Laravel 13 / Livewire 4 support — constraints, component registration, CI

**Files:**
- Modify: `composer.json`
- Modify: `src/FeedbackBoardServiceProvider.php:57-63`
- Replace: `.github/workflows/tests.yml`
- Create: `tests/Feature/LivewireRegistrationTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - The dev stack every later task runs on: Laravel 13 / Livewire 4 / Filament 5 / Pest 4 / Testbench 11 / package-tools 1.93+.
  - Livewire names resolve on both majors through the unchanged public names (see Global Constraints).

- [ ] **Step 1: Widen the constraints**

Replace `composer.json` with exactly:

```json
{
    "name": "yousefaman/feedback-board",
    "description": "Self-hosted in-app feedback board for Laravel — clients report, developers resolve.",
    "keywords": ["laravel", "filament", "livewire", "feedback", "bug-report", "support"],
    "license": "MIT",
    "authors": [{ "name": "Yousef Aman", "role": "Developer" }],
    "require": {
        "php": "^8.2",
        "illuminate/contracts": "^12.0 || ^13.0",
        "livewire/livewire": "^3.8.3 || ^4.3.4",
        "spatie/laravel-package-tools": "^1.19"
    },
    "require-dev": {
        "orchestra/testbench": "^10.0 || ^11.0",
        "pestphp/pest": "^3.0 || ^4.0",
        "pestphp/pest-plugin-laravel": "^3.0 || ^4.0",
        "filament/filament": "^4.0 || ^5.0"
    },
    "suggest": {
        "filament/filament": "^4.0 || ^5.0 — developer Kanban page, resource and database notifications"
    },
    "autoload": { "psr-4": { "YousefAman\\FeedbackBoard\\": "src/", "YousefAman\\FeedbackBoard\\Database\\Factories\\": "database/factories/" } },
    "autoload-dev": { "psr-4": { "YousefAman\\FeedbackBoard\\Tests\\": "tests/" } },
    "extra": { "laravel": { "providers": ["YousefAman\\FeedbackBoard\\FeedbackBoardServiceProvider"] } },
    "config": { "allow-plugins": { "pestphp/pest-plugin": true } }
}
```

- [ ] **Step 2: Resolve the new stack**

Run:
```bash
composer update --prefer-dist --no-interaction
composer show | grep -E "^(laravel/framework|livewire/livewire|filament/filament|pestphp/pest|orchestra/testbench|spatie/laravel-package-tools) "
```
Expected (PHP 8.4): `laravel/framework` 13.x, `livewire/livewire` 4.x (≥ 4.3.4), `filament/filament` 5.x, `pestphp/pest` 4.x, `orchestra/testbench` 11.x, `spatie/laravel-package-tools` ≥ 1.93.

If Livewire 3 resolves instead, stop and report: Step 3's red phase depends on Livewire 4.

- [ ] **Step 3: Observe the Livewire 4 failure**

Run: `vendor/bin/pest 2>&1 | tail -5`

Expected: about 19 failed and about 181 passed. Every failure is `Livewire\Exceptions\ComponentNotFoundException: Unable to find component: [feedback-board::…]` (spec §3.1).

- [ ] **Step 4: Write the failing test**

Create `tests/Feature/LivewireRegistrationTest.php`:

```php
<?php

use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use YousefAman\FeedbackBoard\Livewire\{FeedbackDetail, FeedbackForm, FeedbackItemModal, FeedbackKanban, MyReportsBoard};
use YousefAman\FeedbackBoard\Tests\TestUser;

it('resolves every feedback-board:: component name on the installed Livewire major', function (string $name, string $class) {
    // Livewire::new() exists on Livewire 3 and 4; Livewire::exists() is 4-only.
    expect(Livewire::new('feedback-board::'.$name))->toBeInstanceOf($class);
})->with([
    ['feedback-form', FeedbackForm::class],
    ['my-reports-board', MyReportsBoard::class],
    ['feedback-item-modal', FeedbackItemModal::class],
    ['feedback-kanban', FeedbackKanban::class],
    ['feedback-detail', FeedbackDetail::class],
]);

it('renders the launcher with the nested feedback form for an authenticated user', function () {
    $this->actingAs(TestUser::create(['name' => 'Client', 'email' => 'c@x.com']));

    $html = Blade::render('<x-feedback-board::launcher />');

    expect($html)->toContain('fb-launcher')
        ->and($html)->toContain('wire:submit="submit"');
});
```

- [ ] **Step 5: Run it to verify it fails**

Run: `vendor/bin/pest tests/Feature/LivewireRegistrationTest.php`

Expected: 6 FAIL with `ComponentNotFoundException`. The launcher fails on its nested `@livewire('feedback-board::feedback-form')`.

- [ ] **Step 6: Register the Livewire 4 class namespace and delete the dead Blade line**

In `src/FeedbackBoardServiceProvider.php::packageBooted()`, replace the block from the first `\Livewire\Livewire::component(` line through the trailing comment with:

```php
        // Livewire 4 resolves "namespace::name" through registered class namespaces only;
        // Livewire 3 has no addNamespace() and resolves the explicit registrations below.
        if (method_exists(app('livewire'), 'addNamespace')) {
            \Livewire\Livewire::addNamespace('feedback-board', classNamespace: 'YousefAman\\FeedbackBoard\\Livewire');
        }

        \Livewire\Livewire::component('feedback-board::feedback-form', \YousefAman\FeedbackBoard\Livewire\FeedbackForm::class);
        \Livewire\Livewire::component('feedback-board::my-reports-board', \YousefAman\FeedbackBoard\Livewire\MyReportsBoard::class);
        \Livewire\Livewire::component('feedback-board::feedback-item-modal', \YousefAman\FeedbackBoard\Livewire\FeedbackItemModal::class);
        \Livewire\Livewire::component('feedback-board::feedback-kanban', \YousefAman\FeedbackBoard\Livewire\FeedbackKanban::class);
        \Livewire\Livewire::component('feedback-board::feedback-detail', \YousefAman\FeedbackBoard\Livewire\FeedbackDetail::class);
        // anonymous components in resources/views/components are auto-registered via ->hasViews('feedback-board')
```

This removes the line `\Illuminate\Support\Facades\Blade::componentNamespace('YousefAman\\FeedbackBoard\\View\\Components', 'feedback-board');`. `src/View/Components` does not exist, and the launcher is an anonymous component (spec §3.3).

- [ ] **Step 7: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/LivewireRegistrationTest.php`, then `vendor/bin/pest`.

Expected: 6 passed, then **206 passed**.

- [ ] **Step 8: Validate the package manifest**

Run: `composer validate --strict`

Expected: `./composer.json is valid`. The lock is fresh after Step 2.

- [ ] **Step 9: Replace the CI workflow**

Replace `.github/workflows/tests.yml` with exactly:

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
      # Composer >= 2.9 skips versions with known advisories by default, which would hide the declared floors.
      - if: matrix.stability == 'prefer-lowest'
        run: composer update --prefer-lowest --prefer-dist --no-interaction
        env:
          COMPOSER_NO_SECURITY_BLOCKING: '1'
      - run: vendor/bin/pest
```

Run: `python3 -c "import yaml; print(yaml.safe_load(open('.github/workflows/tests.yml'))['jobs']['test']['strategy'])"`

Expected: a dict with `fail-fast: False`, the four matrix axes, one `exclude` entry and two `include` entries. That gives 14 prefer-stable jobs plus 2 prefer-lowest jobs.

- [ ] **Step 10: Commit**

```bash
git add composer.json .github/workflows/tests.yml src/FeedbackBoardServiceProvider.php tests/Feature/LivewireRegistrationTest.php
git commit -m "feat(compat): support Laravel 13 and Livewire 4

Widen constraints to Laravel 12/13, Livewire ^3.8.3 || ^4.3.4 (advisory-free
floor), package-tools ^1.19, Pest/Testbench for both; drop minimum-stability.
Register the Livewire 4 class namespace so feedback-board:: names resolve on
both majors, remove the dead Blade componentNamespace line, and run CI across
PHP 8.2-8.5 x Laravel 12/13 x Livewire 3/4 plus two prefer-lowest lanes."
```

---

### Task 2: Filament panel smoke harness

**Files:**
- Modify: `tests/Pest.php` (full replacement)
- Create: `tests/Filament/FilamentTestCase.php`
- Create: `tests/Filament/TestPanelProvider.php`
- Create: `tests/Filament/FilamentTestUser.php`
- Create: `tests/Filament/PanelSmokeTest.php`

**Interfaces:**
- Consumes: Task 1's stack.
- Produces:
  - `YousefAman\FeedbackBoard\Tests\Filament\FilamentTestCase`, applied by `tests/Pest.php` to `tests/Filament/`.
  - Panel id `admin` at path `/admin`, the default panel, with `FeedbackBoardPlugin`.
  - `FilamentTestUser::create(['name' => …])`, which is always allowed into the panel. The package gate still decides developer access.
  - Task 3 edits `PanelSmokeTest.php`.

These are characterization tests of existing behaviour, so they pass on their first run. Their value is that every later task, and every CI lane, boots a real Filament 4 or 5 panel.

- [ ] **Step 1: Scope the two test cases in `tests/Pest.php`**

Replace `tests/Pest.php` with:

```php
<?php

uses(YousefAman\FeedbackBoard\Tests\TestCase::class)->in('Feature', 'Unit', 'Smoke');

// Filament smoke tests boot a real panel; see tests/Filament/FilamentTestCase.php.
uses(YousefAman\FeedbackBoard\Tests\Filament\FilamentTestCase::class)->in('Filament');

// FeedbackDetailTest asserts that Livewire::test() throws an HttpException when a
// non-developer opens the detail panel (mount calls abort_unless). Livewire v3's test
// framework normally converts abort() to a 403 response (not a throw), so we apply
// withoutExceptionHandling() only for this file to make exceptions propagate as expected.
// KanbanTest must NOT have this active because it relies on ->assertForbidden().
// We use an empty uses() to add a beforeEach hook without conflicting with the TestCase
// already set for Feature/ above.
uses()
    ->beforeEach(fn () => $this->withoutExceptionHandling())
    ->in('Feature/FeedbackDetailTest.php');
```

Pest throws if two test-case classes cover overlapping paths, so the root-level `in(__DIR__)` has to go. `tests/ArchTest.php` needs no test case.

- [ ] **Step 2: Create the test case**

Create `tests/Filament/FilamentTestCase.php`:

```php
<?php

namespace YousefAman\FeedbackBoard\Tests\Filament;

use YousefAman\FeedbackBoard\FeedbackBoardServiceProvider;
use YousefAman\FeedbackBoard\Tests\TestCase;

/**
 * Boots a real Filament panel ("admin", see TestPanelProvider) with FeedbackBoardPlugin,
 * on whichever Filament major the lane resolves (4 with Livewire 3, 5 with Livewire 4).
 */
class FilamentTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            \BladeUI\Heroicons\BladeHeroiconsServiceProvider::class,
            \BladeUI\Icons\BladeIconsServiceProvider::class,
            \RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider::class,
            \Filament\FilamentServiceProvider::class,
            \Filament\Actions\ActionsServiceProvider::class,
            \Filament\Forms\FormsServiceProvider::class,
            \Filament\Infolists\InfolistsServiceProvider::class,
            \Filament\Notifications\NotificationsServiceProvider::class,
            \Filament\Schemas\SchemasServiceProvider::class,
            // Must be registered before LivewireServiceProvider: on Filament 4 it binds
            // Livewire's DataStore override; otherwise every render fails in ViewErrorBag::put().
            \Filament\Support\SupportServiceProvider::class,
            \Filament\Tables\TablesServiceProvider::class,
            \Filament\Widgets\WidgetsServiceProvider::class,
            \Livewire\LivewireServiceProvider::class,
            FeedbackBoardServiceProvider::class,
            TestPanelProvider::class,
        ];
    }
}
```

- [ ] **Step 3: Create the panel provider**

Create `tests/Filament/TestPanelProvider.php`:

```php
<?php

namespace YousefAman\FeedbackBoard\Tests\Filament;

use Filament\Http\Middleware\{Authenticate, DisableBladeIconComponents, DispatchServingFilamentEvent};
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\{AddQueuedCookiesToResponse, EncryptCookies};
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use YousefAman\FeedbackBoard\Filament\FeedbackBoardPlugin;

class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->plugin(FeedbackBoardPlugin::make())
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                ShareErrorsFromSession::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Authenticate::class]);
    }
}
```

- [ ] **Step 4: Create the panel user**

Create `tests/Filament/FilamentTestUser.php`:

```php
<?php

namespace YousefAman\FeedbackBoard\Tests\Filament;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use YousefAman\FeedbackBoard\Tests\TestUser;

/** Panel access is open; the package's own gate decides developer access. */
class FilamentTestUser extends TestUser implements FilamentUser
{
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
```

Filament's `Authenticate` middleware returns 403 for any user that is not a `FilamentUser` outside `APP_ENV=local`. Implementing `FilamentUser` avoids switching the test app to `local`.

- [ ] **Step 5: Write the smoke tests**

Create `tests/Filament/PanelSmokeTest.php`:

```php
<?php

use YousefAman\FeedbackBoard\Filament\Pages\FeedbackBoardPage;
use YousefAman\FeedbackBoard\Filament\Resources\FeedbackItemResource;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\Filament\FilamentTestUser;

beforeEach(function () {
    config()->set('feedback-board.authorization.developer_resolver', fn ($user) => $user->name === 'Dev');
    FeedbackItem::factory()->create(['title' => 'Smoke item']);
});

it('serves the board page to a developer', function () {
    $this->actingAs(FilamentTestUser::create(['name' => 'Dev']))
        ->get(FeedbackBoardPage::getUrl())
        ->assertOk()
        ->assertSee('Smoke item');
});

it('serves the feedback items resource index to a developer', function () {
    $this->actingAs(FilamentTestUser::create(['name' => 'Dev']))
        ->get(FeedbackItemResource::getUrl('index'))
        ->assertOk()
        ->assertSee('Smoke item');
});

it('forbids the board page and the resource index to a non-developer', function () {
    $this->actingAs(FilamentTestUser::create(['name' => 'Client']));

    $this->get(FeedbackBoardPage::getUrl())->assertForbidden();
    $this->get(FeedbackItemResource::getUrl('index'))->assertForbidden();
});
```

- [ ] **Step 6: Run the smoke tests and the full suite**

Run: `vendor/bin/pest tests/Filament`, then `vendor/bin/pest`.

Expected: 3 passed under `Tests\Filament\PanelSmokeTest`, then **209 passed**. `Tests\ArchTest` must still appear as PASS. If a render fails with `ViewErrorBag::put(): Argument #2 … null given`, the provider order in Step 2 was changed.

- [ ] **Step 7: Commit**

```bash
git add tests/Pest.php tests/Filament/FilamentTestCase.php tests/Filament/TestPanelProvider.php tests/Filament/FilamentTestUser.php tests/Filament/PanelSmokeTest.php
git commit -m "test(filament): boot a real panel for board page and resource smoke tests

The default TestCase never boots a panel, so src/Filament was untested on
any Filament major. FilamentTestCase registers the Filament providers
(Support before Livewire, required on Filament 4) and a test panel with
FeedbackBoardPlugin; smoke tests cover 200 for developers, 403 otherwise."
```

---

### Task 3: Filament-only view off the default view path

**Files:**
- Move: `resources/views/filament/board-page.blade.php` → `resources/views-filament/filament/board-page.blade.php`
- Modify: `src/FeedbackBoardServiceProvider.php` (end of `packageBooted()`)
- Create: `tests/Feature/ViewCacheTest.php`
- Modify: `tests/Filament/PanelSmokeTest.php` (full replacement)

**Interfaces:**
- Consumes: Task 2's `FilamentTestCase` and `PanelSmokeTest.php`.
- Produces: view `feedback-board::filament.board-page`, which resolves only when `Filament\FilamentServiceProvider` is registered. `runner-result` and `triage-suggestion` stay in `resources/views/filament/` (spec delta 1).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ViewCacheTest.php`:

```php
<?php

use Illuminate\Support\Facades\Artisan;

afterEach(fn () => Artisan::call('view:clear'));

it('compiles every package view without Filament registered (php artisan optimize)', function () {
    // The default TestCase installs Filament but does not register its providers,
    // like a host app without Filament.
    expect(Artisan::call('view:cache'))->toBe(0);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Feature/ViewCacheTest.php`

Expected: FAIL with `InvalidArgumentException: Unable to locate a class or view for component [filament-panels::page]`.

- [ ] **Step 3: Add the Filament-side `view:cache` guard**

Replace `tests/Filament/PanelSmokeTest.php` with:

```php
<?php

use Illuminate\Support\Facades\Artisan;
use YousefAman\FeedbackBoard\Filament\Pages\FeedbackBoardPage;
use YousefAman\FeedbackBoard\Filament\Resources\FeedbackItemResource;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\Filament\FilamentTestUser;

beforeEach(function () {
    config()->set('feedback-board.authorization.developer_resolver', fn ($user) => $user->name === 'Dev');
    FeedbackItem::factory()->create(['title' => 'Smoke item']);
});

afterEach(fn () => Artisan::call('view:clear'));

it('serves the board page to a developer', function () {
    $this->actingAs(FilamentTestUser::create(['name' => 'Dev']))
        ->get(FeedbackBoardPage::getUrl())
        ->assertOk()
        ->assertSee('Smoke item');
});

it('serves the feedback items resource index to a developer', function () {
    $this->actingAs(FilamentTestUser::create(['name' => 'Dev']))
        ->get(FeedbackItemResource::getUrl('index'))
        ->assertOk()
        ->assertSee('Smoke item');
});

it('forbids the board page and the resource index to a non-developer', function () {
    $this->actingAs(FilamentTestUser::create(['name' => 'Client']));

    $this->get(FeedbackBoardPage::getUrl())->assertForbidden();
    $this->get(FeedbackItemResource::getUrl('index'))->assertForbidden();
});

it('compiles every view, Filament-only ones included, with view:cache', function () {
    expect(Artisan::call('view:cache'))->toBe(0)
        ->and(view()->exists('feedback-board::filament.board-page'))->toBeTrue();
});
```

- [ ] **Step 4: Move the view and confirm the Filament path breaks**

Run:
```bash
mkdir -p resources/views-filament/filament
git mv resources/views/filament/board-page.blade.php resources/views-filament/filament/board-page.blade.php
vendor/bin/pest tests/Feature/ViewCacheTest.php tests/Filament
```

Expected:
- `ViewCacheTest` PASSES.
- `serves the board page to a developer` FAILS: a 500 instead of 200, caused by `View [filament.board-page] not found`.
- `compiles every view…` FAILS on the `exists` assertion.
- The 403 and resource tests still pass.

This proves the next step is needed.

- [ ] **Step 5: Load the Filament-only view root when Filament is registered**

Append to the end of `packageBooted()` in `src/FeedbackBoardServiceProvider.php`, after the anonymous-components comment:

```php

        // Filament-only views (they use <x-filament-panels::*>) live outside resources/views so
        // `php artisan view:cache` does not compile them in apps without Filament.
        if ($this->app->providerIsLoaded(\Filament\FilamentServiceProvider::class)) {
            $this->loadViewsFrom(__DIR__.'/../resources/views-filament', 'feedback-board');
        }
```

`providerIsLoaded` reflects registration, and every provider registers before any boots, so the check does not depend on boot order. The `::class` constant does not autoload, so the check is safe without Filament installed.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/ViewCacheTest.php tests/Filament`, then `vendor/bin/pest`.

Expected: 5 passed, then **211 passed**.

- [ ] **Step 7: Commit**

```bash
git status --short resources/   # expect: R  resources/views/filament/board-page.blade.php -> resources/views-filament/filament/board-page.blade.php (staged by git mv)
git add src/FeedbackBoardServiceProvider.php tests/Feature/ViewCacheTest.php tests/Filament/PanelSmokeTest.php
git commit -m "fix(views): keep the Filament board page out of view:cache without Filament

board-page wraps <x-filament-panels::page>, which view:cache resolves at
compile time, so php artisan optimize failed in every app without Filament.
Move it to resources/views-filament and load that root under the same
feedback-board namespace only when Filament's panel provider is registered."
```

---

### Task 4: `config:cache`-safe developer API (`FeedbackBoard` facade)

**Files:**
- Create: `src/FeedbackBoard.php`
- Create: `src/Facades/FeedbackBoard.php`
- Create: `tests/Fixtures/DeveloperCheck.php`
- Create: `tests/Feature/DeveloperAccessTest.php`
- Create: `tests/Smoke/ConfigSerializableTest.php`
- Modify: `src/FeedbackBoardServiceProvider.php` (`packageRegistered()`, gate in `packageBooted()`)
- Modify: `src/Support/DeveloperRecipients.php` (full replacement)
- Modify: `config/feedback-board.php:6-9` and `:21-25`

**Interfaces:**
- Consumes: none from earlier tasks.
- Produces:
  - `YousefAman\FeedbackBoard\FeedbackBoard`, a container singleton:
    - `resolveDevelopersUsing(callable $callback): void`
    - `notifyDevelopersUsing(callable $callback): void`
    - `isDeveloper(?Authenticatable $user): bool`
    - `@internal resolveDeveloper(Authenticatable $user): bool`
    - `@internal developerRecipients(): Collection`
  - Facade `YousefAman\FeedbackBoard\Facades\FeedbackBoard`, which documents the first three methods.
  - `DeveloperRecipients::resolve(): Collection` keeps its signature, so Task 5 relies on it unchanged.

- [ ] **Step 1: Create the static-callable fixture**

Create `tests/Fixtures/DeveloperCheck.php`:

```php
<?php

namespace YousefAman\FeedbackBoard\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;

/** Config-cache-safe callables for feedback-board config keys (public static methods). */
class DeveloperCheck
{
    public static function handle(Authenticatable $user): bool
    {
        return $user->name === 'Dev';
    }

    public function __invoke(Authenticatable $user): bool
    {
        return true;
    }
}
```

- [ ] **Step 2: Write the failing tests**

Create `tests/Feature/DeveloperAccessTest.php`:

```php
<?php

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use YousefAman\FeedbackBoard\Actions\CreateFeedbackItem;
use YousefAman\FeedbackBoard\Enums\FeedbackType;
use YousefAman\FeedbackBoard\Events\FeedbackItemCreated;
use YousefAman\FeedbackBoard\Facades\FeedbackBoard;
use YousefAman\FeedbackBoard\Jobs\DeliverToDestination;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Notifications\NewFeedbackNotification;
use YousefAman\FeedbackBoard\Tests\Fixtures\DeveloperCheck;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    $this->dev = TestUser::create(['name' => 'Dev', 'email' => 'dev@x.com']);
    $this->client = TestUser::create(['name' => 'Client', 'email' => 'c@x.com']);
});

it('grants and denies through a registered resolveDevelopersUsing callback', function () {
    FeedbackBoard::resolveDevelopersUsing(fn ($user) => $user->name === 'Dev');

    expect(Gate::forUser($this->dev)->allows('feedback-board.manage'))->toBeTrue()
        ->and(Gate::forUser($this->client)->allows('feedback-board.manage'))->toBeFalse();
});

it('lets a registered callback take precedence over the config resolver', function () {
    config()->set('feedback-board.authorization.developer_resolver', fn () => true);
    FeedbackBoard::resolveDevelopersUsing(fn () => false);

    expect(Gate::forUser($this->dev)->allows('feedback-board.manage'))->toBeFalse();
});

it('accepts a public static method callable in config', function (mixed $callable) {
    config()->set('feedback-board.authorization.developer_resolver', $callable);

    expect(Gate::forUser($this->dev)->allows('feedback-board.manage'))->toBeTrue()
        ->and(Gate::forUser($this->client)->allows('feedback-board.manage'))->toBeFalse();
})->with([
    'array form' => [[DeveloperCheck::class, 'handle']],
    'string form' => [DeveloperCheck::class.'::handle'],
]);

it('denies everyone when nothing is configured', function () {
    expect(Gate::forUser($this->dev)->allows('feedback-board.manage'))->toBeFalse();
});

it('fails loudly on a non-callable developer_resolver instead of silently denying', function () {
    // An invokable class name is not callable as a string, so it used to deny every user.
    config()->set('feedback-board.authorization.developer_resolver', DeveloperCheck::class);

    Gate::forUser($this->dev)->allows('feedback-board.manage');
})->throws(InvalidArgumentException::class, 'feedback-board.authorization.developer_resolver');

it('answers isDeveloper() through the gate, honouring Gate::before', function () {
    FeedbackBoard::resolveDevelopersUsing(fn () => false);
    Gate::before(fn ($user, string $ability) => $user->name === 'Dev' ? true : null);

    expect(FeedbackBoard::isDeveloper($this->dev))->toBeTrue()
        ->and(FeedbackBoard::isDeveloper($this->client))->toBeFalse();
});

it('treats a guest as not a developer', function () {
    FeedbackBoard::resolveDevelopersUsing(fn () => true);

    expect(FeedbackBoard::isDeveloper(null))->toBeFalse();
});

it('sends new-report notifications to notifyDevelopersUsing recipients', function () {
    Notification::fake();
    FeedbackBoard::notifyDevelopersUsing(fn () => [$this->dev]);

    app(CreateFeedbackItem::class)->handle(['title' => 'X', 'type' => FeedbackType::Bug], $this->client);

    Notification::assertSentTo($this->dev, NewFeedbackNotification::class);
    Notification::assertNotSentTo($this->client, NewFeedbackNotification::class);
});

it('targets notifyDevelopersUsing recipients with the delivery-failure database notification', function () {
    \Illuminate\Support\Facades\Schema::create('notifications', function ($t) {
        $t->uuid('id')->primary();
        $t->string('type');
        $t->morphs('notifiable');
        $t->text('data');
        $t->timestamp('read_at')->nullable();
        $t->timestamps();
    });
    FeedbackBoard::notifyDevelopersUsing(fn () => collect([$this->dev]));
    $item = FeedbackItem::factory()->create();

    (new DeliverToDestination(new FeedbackItemCreated($item), 'slack', 'dlv-api'))
        ->failed(new \Illuminate\Http\Client\ConnectionException('connection refused'));

    expect($this->dev->notifications()->count())->toBe(1)
        ->and($this->client->notifications()->count())->toBe(0);
});

it('notifies only valid developer e-mails and warns once about the rest', function () {
    Notification::fake();
    Log::spy();
    config()->set('feedback-board.notifications.developers', ['dev@example.com', 1, 'not-an-email']);

    app(CreateFeedbackItem::class)->handle(['title' => 'X', 'type' => FeedbackType::Bug], null);

    Notification::assertSentOnDemandTimes(NewFeedbackNotification::class, 1);
    Notification::assertSentOnDemand(
        NewFeedbackNotification::class,
        fn ($notification, array $channels, object $notifiable) => $notifiable->routes['mail'] === 'dev@example.com',
    );
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message) => str_contains($message, 'feedback-board.notifications.developers'));
});

it('stays silent on a default install with no developer e-mails', function () {
    Notification::fake();
    Log::spy();

    app(CreateFeedbackItem::class)->handle(['title' => 'Quiet', 'type' => FeedbackType::Bug], null);

    expect(FeedbackItem::where('title', 'Quiet')->exists())->toBeTrue();
    Notification::assertNothingSent();
    Log::shouldNotHaveReceived('warning');
});
```

- [ ] **Step 3: Run them to verify they fail**

Run: `vendor/bin/pest tests/Feature/DeveloperAccessTest.php`

Expected:
- Every test that uses the facade FAILS with `Class "YousefAman\FeedbackBoard\Facades\FeedbackBoard" not found`.
- The `throws` test FAILS because the current gate silently denies.
- The invalid-e-mail test FAILS because all three entries become mail routes: 3 on-demand sends, not 1.
- Three tests already PASS: the static-callable datasets, `denies everyone…` and `stays silent…`. They pin existing behaviour that must survive the change.

- [ ] **Step 4: Create the `FeedbackBoard` class**

Create `src/FeedbackBoard.php`:

```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * Developer access and developer recipients, registered from the host app's
 * AppServiceProvider::boot(). Registered callbacks live in this container
 * singleton, never in config, so `php artisan config:cache` stays safe.
 */
class FeedbackBoard
{
    protected ?Closure $developerResolver = null;

    protected ?Closure $developerRecipients = null;

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
            return (bool) ($this->developerResolver)($user);
        }

        $resolver = config('feedback-board.authorization.developer_resolver');

        if ($resolver === null) {
            return false;
        }

        if (! is_callable($resolver)) {
            throw new InvalidArgumentException(
                'feedback-board.authorization.developer_resolver must be null or a public static method callable '
                .'such as [DeveloperCheck::class, \'handle\']. Register anything else with '
                .'FeedbackBoard::resolveDevelopersUsing() in AppServiceProvider::boot().'
            );
        }

        return (bool) $resolver($user);
    }

    /**
     * @internal Used by Support\DeveloperRecipients.
     */
    public function developerRecipients(): Collection
    {
        if ($this->developerRecipients !== null) {
            return collect(($this->developerRecipients)());
        }

        $config = config('feedback-board.notifications.developers');

        if (is_callable($config)) {
            return collect($config());
        }

        [$emails, $invalid] = collect($config ?? [])->partition(
            fn ($entry) => is_string($entry) && filter_var($entry, FILTER_VALIDATE_EMAIL) !== false
        );

        if ($invalid->isNotEmpty()) {
            Log::warning('Feedback Board skipped entries in feedback-board.notifications.developers that are not e-mail addresses.', [
                'skipped' => $invalid->count(),
            ]);
        }

        return $emails->values()->map(fn (string $email) => Notification::route('mail', $email));
    }
}
```

- [ ] **Step 5: Create the facade**

Create `src/Facades/FeedbackBoard.php`:

```php
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
```

- [ ] **Step 6: Bind the singleton and route the gate through it**

In `src/FeedbackBoardServiceProvider.php`, make two edits.

First, make the first statement of `packageRegistered()`:

```php
        $this->app->singleton(FeedbackBoard::class);

```

Second, replace the existing gate definition at the top of `packageBooted()`, the four lines from `\Illuminate\Support\Facades\Gate::define('feedback-board.manage', function ($user) {` through `});`, with:

```php
        // Untyped, non-nullable parameter: Gate denies guests before this runs.
        \Illuminate\Support\Facades\Gate::define(
            'feedback-board.manage',
            fn ($user) => app(FeedbackBoard::class)->resolveDeveloper($user),
        );
```

`FeedbackBoard` is in the provider's own namespace, so no import is needed.

- [ ] **Step 7: Delegate `DeveloperRecipients`**

Replace `src/Support/DeveloperRecipients.php` with:

```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Support;

use Illuminate\Support\Collection;
use YousefAman\FeedbackBoard\FeedbackBoard;

class DeveloperRecipients
{
    public function resolve(): Collection
    {
        return app(FeedbackBoard::class)->developerRecipients();
    }
}
```

Its callers are `SendFeedbackNotifications`, through constructor injection, and `DeliverToDestination::notifyDevelopers()`. Neither changes.

- [ ] **Step 8: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/DeveloperAccessTest.php`, then `vendor/bin/pest`.

Expected: 12 passed, then **223 passed**. Existing tests that `config()->set(...)` a closure resolver still pass, because runtime config is never cached.

- [ ] **Step 9: Replace the closure examples in the config comments**

In `config/feedback-board.php`, replace the `authorization` block with:

```php
    'authorization' => [
        // Who may manage (triage/resolve) feedback. Prefer registering a callback in
        // AppServiceProvider::boot(): FeedbackBoard::resolveDevelopersUsing(...); it takes
        // precedence over this key. A closure here breaks `php artisan config:cache`; use
        // null or a public static method callable, e.g. [DeveloperCheck::class, 'handle'].
        'developer_resolver' => null,
    ],
```

Then replace the comment and the `developers` line in the `notifications` block, keeping `'channels'` unchanged until Task 5:

```php
        // E-mail addresses to notify. For notifiable models, register
        // FeedbackBoard::notifyDevelopersUsing(...) in AppServiceProvider::boot() instead;
        // it takes precedence over this key. A closure here breaks `php artisan config:cache`;
        // a public static method callable returning notifiables is safe.
        'developers' => array_filter([env('FEEDBACK_BOARD_DEVELOPER_EMAIL')]),
```

- [ ] **Step 10: Add the permanent `config:cache` guard**

Create `tests/Smoke/ConfigSerializableTest.php`:

```php
<?php

// `php artisan config:cache` writes var_export(config) and requires it back; a closure
// anywhere in the config cannot survive that round-trip. This guards the shipped file.
function roundTripsThroughVarExport(array $config): bool
{
    return eval('return '.var_export($config, true).';') === $config;
}

it('ships a config file that survives config:cache', function () {
    $raw = require __DIR__.'/../../config/feedback-board.php';

    expect(roundTripsThroughVarExport($raw))->toBeTrue();
});

it('boots a feedback-board config that survives config:cache', function () {
    expect(roundTripsThroughVarExport(config('feedback-board')))->toBeTrue();
});
```

Run: `vendor/bin/pest tests/Smoke/ConfigSerializableTest.php`

Expected: 2 passed. The shipped config is already closure-free.

- [ ] **Step 11: Prove the guard has teeth, then revert**

Temporarily change `'developer_resolver' => null,` in `config/feedback-board.php` to `'developer_resolver' => fn () => true,` and run `vendor/bin/pest tests/Smoke/ConfigSerializableTest.php`.

Expected: 2 FAIL with `Error: Call to undefined method Closure::__set_state()`.

Revert the line to `'developer_resolver' => null,` and confirm with `git diff config/feedback-board.php` that only the comment edits from Step 9 remain.

- [ ] **Step 12: Run the full suite**

Run: `vendor/bin/pest`

Expected: **225 passed**, with `Tests\ArchTest` PASS: both new `src/` classes declare `strict_types`.

- [ ] **Step 13: Commit**

```bash
git add src/FeedbackBoard.php src/Facades/FeedbackBoard.php src/FeedbackBoardServiceProvider.php src/Support/DeveloperRecipients.php config/feedback-board.php tests/Fixtures/DeveloperCheck.php tests/Feature/DeveloperAccessTest.php tests/Smoke/ConfigSerializableTest.php
git commit -m "feat(api): config:cache-safe FeedbackBoard facade for developer access

Closures in config/feedback-board.php break php artisan config:cache. Add
FeedbackBoard::resolveDevelopersUsing()/notifyDevelopersUsing() (container
singleton, registered from AppServiceProvider::boot) and isDeveloper(),
which answers through the gate so Gate::before hooks apply. Registered
callbacks take precedence; config keeps null or public static callables and
now throws on any other non-null resolver instead of silently denying.
Developer e-mails are validated: invalid entries are skipped with one
warning. A var_export round-trip test guards the shipped config."
```

---

### Task 5: Notification reliability — after commit, best-effort, `mail` by default

**Files:**
- Modify: `src/Listeners/SendFeedbackNotifications.php` (full replacement)
- Modify: `config/feedback-board.php` (`notifications.channels`)
- Create: `tests/Feature/NotificationReliabilityTest.php`

**Interfaces:**
- Consumes:
  - `FeedbackBoard::notifyDevelopersUsing(callable): void` (Task 4).
  - `DeveloperRecipients::resolve(): Collection` (Task 4).
- Produces:
  - `SendFeedbackNotifications` keeps the same `subscribe()` map.
  - New `protected function sendAfterCommit(Closure $send): void`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/NotificationReliabilityTest.php`:

```php
<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use YousefAman\FeedbackBoard\Actions\{CreateFeedbackItem, ReplyToFeedback, TransitionStatus};
use YousefAman\FeedbackBoard\Enums\{AuthorRole, FeedbackStatus, FeedbackType};
use YousefAman\FeedbackBoard\Facades\FeedbackBoard;
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};
use YousefAman\FeedbackBoard\Notifications\NewFeedbackNotification;
use YousefAman\FeedbackBoard\Tests\TestUser;

// Simulates an unreachable SMTP server: every send throws like a real transport outage.
function useFailingMailer(): void
{
    Mail::extend('failing', fn () => new class extends AbstractTransport {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('Connection could not be established');
        }

        public function __toString(): string
        {
            return 'failing://';
        }
    });
    config()->set('mail.mailers.failing', ['transport' => 'failing']);
    config()->set('mail.default', 'failing');
}

it('keeps the report when a model recipient needs the missing notifications table', function () {
    Exceptions::fake();
    config()->set('feedback-board.notifications.channels', ['mail', 'database']);
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'dev@x.com']);
    FeedbackBoard::notifyDevelopersUsing(fn () => [$dev]);

    app(CreateFeedbackItem::class)->handle(['title' => 'Kept', 'type' => FeedbackType::Bug], null);

    expect(FeedbackItem::where('title', 'Kept')->exists())->toBeTrue();
    Exceptions::assertReported(\Illuminate\Database\QueryException::class);
});

it('keeps the report when the mail transport is down', function () {
    Exceptions::fake();
    useFailingMailer();
    config()->set('feedback-board.notifications.developers', ['dev@example.com']);

    app(CreateFeedbackItem::class)->handle(['title' => 'Kept', 'type' => FeedbackType::Bug], null);

    expect(FeedbackItem::where('title', 'Kept')->exists())->toBeTrue();
    Exceptions::assertReported(TransportException::class);
});

it('keeps the report when the notifyDevelopersUsing callback throws', function () {
    Exceptions::fake();
    FeedbackBoard::notifyDevelopersUsing(fn () => throw new RuntimeException('users table unavailable'));

    app(CreateFeedbackItem::class)->handle(['title' => 'Kept', 'type' => FeedbackType::Bug], null);

    expect(FeedbackItem::where('title', 'Kept')->exists())->toBeTrue();
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'users table unavailable');
});

it('keeps a developer reply and a resolve when the reporter notification fails', function () {
    Exceptions::fake();
    useFailingMailer();
    $client = TestUser::create(['name' => 'C', 'email' => 'c@x.com']);
    $item = FeedbackItem::factory()->for($client, 'reporter')->create(['status' => FeedbackStatus::New]);

    app(ReplyToFeedback::class)->handle($item, 'On it', null, AuthorRole::Developer);
    app(TransitionStatus::class)->handle($item, FeedbackStatus::Resolved, null);

    expect(FeedbackComment::where('body', 'On it')->exists())->toBeTrue()
        ->and($item->fresh()->status)->toBe(FeedbackStatus::Resolved);
    Exceptions::assertReported(TransportException::class);
});

it('keeps a reporter reply when the developer notification fails', function () {
    Exceptions::fake();
    FeedbackBoard::notifyDevelopersUsing(fn () => throw new RuntimeException('boom'));
    $client = TestUser::create(['name' => 'C', 'email' => 'c@x.com']);
    $item = FeedbackItem::factory()->for($client, 'reporter')->create();

    app(ReplyToFeedback::class)->handle($item, 'Still broken', $client, AuthorRole::Reporter);

    expect(FeedbackComment::where('body', 'Still broken')->exists())->toBeTrue();
    Exceptions::assertReported(RuntimeException::class);
});

it('sends nothing when the surrounding transaction rolls back', function () {
    Notification::fake();
    config()->set('feedback-board.notifications.developers', ['dev@example.com']);

    try {
        DB::transaction(function () {
            app(CreateFeedbackItem::class)->handle(['title' => 'Rolled back', 'type' => FeedbackType::Bug], null);
            throw new RuntimeException('later step failed');
        });
    } catch (RuntimeException) {
    }

    expect(FeedbackItem::count())->toBe(0);
    Notification::assertNothingSent();
});

it('notifies once, after the host app commits its own outer transaction', function () {
    Notification::fake();
    config()->set('feedback-board.notifications.developers', ['dev@example.com']);

    DB::transaction(function () {
        app(CreateFeedbackItem::class)->handle(['title' => 'Nested', 'type' => FeedbackType::Bug], null);
        Notification::assertNothingSent(); // still inside the host transaction
    });

    Notification::assertSentOnDemandTimes(NewFeedbackNotification::class, 1);
});

it('defaults to the mail channel only', function () {
    $item = FeedbackItem::factory()->create();

    expect((new NewFeedbackNotification($item))->via(new TestUser()))->toBe(['mail']);
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/pest tests/Feature/NotificationReliabilityTest.php`

Expected: 8 FAIL.
- The first five error with the propagated exception: `QueryException`, `TransportException`, `RuntimeException`, `TransportException` and `RuntimeException`, because the action rolled back.
- The rollback test fails on `assertNothingSent`.
- The outer-transaction test fails on the inner `assertNothingSent`.
- `defaults to the mail channel only` fails with `['mail', 'database']`.

- [ ] **Step 3: Make every send after-commit and best-effort**

Replace `src/Listeners/SendFeedbackNotifications.php` with:

```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Listeners;

use Closure;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use YousefAman\FeedbackBoard\Enums\{AuthorRole, FeedbackStatus};
use YousefAman\FeedbackBoard\Events\{FeedbackItemCreated, FeedbackReplied, FeedbackStatusChanged};
use YousefAman\FeedbackBoard\Notifications\{FeedbackRepliedNotification, FeedbackResolvedNotification, NewFeedbackNotification};
use YousefAman\FeedbackBoard\Support\DeveloperRecipients;

class SendFeedbackNotifications
{
    public function __construct(protected DeveloperRecipients $recipients) {}

    public function onCreated(FeedbackItemCreated $event): void
    {
        $this->sendAfterCommit(function () use ($event) {
            $devs = $this->recipients->resolve();
            if ($devs->isNotEmpty()) {
                Notification::send($devs, new NewFeedbackNotification($event->item));
            }
        });
    }

    public function onReplied(FeedbackReplied $event): void
    {
        $comment = $event->comment;
        if ($comment->is_internal) {
            return;
        }

        // developer reply -> notify reporter; reporter reply -> notify developers
        if ($comment->author_role === AuthorRole::Reporter) {
            $this->sendAfterCommit(function () use ($comment) {
                $devs = $this->recipients->resolve();
                if ($devs->isNotEmpty()) {
                    Notification::send($devs, new FeedbackRepliedNotification($comment));
                }
            });

            return;
        }

        $this->sendAfterCommit(fn () => $comment->item->reporter?->notify(new FeedbackRepliedNotification($comment)));
    }

    public function onStatusChanged(FeedbackStatusChanged $event): void
    {
        if ($event->to !== FeedbackStatus::Resolved) {
            return;
        }

        $this->sendAfterCommit(fn () => $event->item->reporter?->notify(new FeedbackResolvedNotification($event->item)));
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            FeedbackItemCreated::class => 'onCreated',
            FeedbackReplied::class => 'onReplied',
            FeedbackStatusChanged::class => 'onStatusChanged',
        ];
    }

    /**
     * Send only once the action's transaction has committed (immediately when there is none),
     * and best-effort: a failure is reported to the host's exception handler, never thrown,
     * so it can neither roll back nor fail the user's report, reply or status change.
     */
    protected function sendAfterCommit(Closure $send): void
    {
        DB::afterCommit(fn () => rescue($send, report: true));
    }
}
```

Recipients and the reporter relation resolve inside the callback, so the lookup happens lazily after commit. Any failure in it is rescued too.

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/pest tests/Feature/NotificationReliabilityTest.php`

Expected: 7 passed. `defaults to the mail channel only` still FAILS with `['mail', 'database']`.

- [ ] **Step 5: Make `mail` the only default channel**

In `config/feedback-board.php`, replace `'channels' => ['mail', 'database'],` with:

```php

        // Add 'database' for in-app notifications after `php artisan make:notifications-table`
        // and `php artisan migrate` (and ->databaseNotifications() on a Filament panel).
        'channels' => ['mail'],
```

The blank line separates it from the `developers` entry above.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/NotificationReliabilityTest.php`, then `vendor/bin/pest`.

Expected: 8 passed, then **233 passed**. The existing `NotificationTest` sets channels explicitly and is unaffected.

- [ ] **Step 7: Commit**

```bash
git add src/Listeners/SendFeedbackNotifications.php config/feedback-board.php tests/Feature/NotificationReliabilityTest.php
git commit -m "fix(notifications): send after commit and never lose the user's action

Notifications were sent synchronously inside the transaction that saves a
report, reply or status change, so a mail outage, a missing notifications
table or a failing recipient callback rolled the action back. Every send now
runs in DB::afterCommit + rescue(report: true): nothing is sent if the
transaction rolls back, and a failure is reported instead of thrown.
notifications.channels defaults to ['mail']; 'database' is an explicit
opt-in after make:notifications-table."
```

---

### Task 6: `feedback-board:install` command

**Files:**
- Modify: `src/FeedbackBoardServiceProvider.php` (`configurePackage()`, a new `printNextSteps()` and a new `use` import)
- Create: `tests/Feature/InstallCommandTest.php`

**Interfaces:**
- Consumes:
  - The facade method names from Task 4, printed in the next steps.
  - The `channels` opt-in from Task 5.
- Produces: the hidden Artisan command `feedback-board:install`, which Task 7's README names.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/InstallCommandTest.php`:

```php
<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use YousefAman\FeedbackBoard\FeedbackBoardServiceProvider;

// The command publishes into the Testbench skeleton, which every test app shares:
// a stale config/feedback-board.php there would be loaded by later tests.
// Not safe under `pest --parallel` (the suite does not use it).
function removePublishedInstallFiles(): void
{
    File::delete(config_path('feedback-board.php'));
    File::delete(publishedFeedbackMigrations());
}

function publishedFeedbackMigrations(): array
{
    return glob(database_path('migrations/*_create_feedback_*_table.php')) ?: [];
}

beforeEach(fn () => removePublishedInstallFiles());
afterEach(fn () => removePublishedInstallFiles());

it('publishes the config and the four migrations, then prints the next steps', function () {
    $this->artisan('feedback-board:install')
        ->expectsConfirmation('Would you like to run the migrations now?', 'no')
        ->expectsOutputToContain('<x-feedback-board::launcher />')
        ->expectsOutputToContain('FeedbackBoard::resolveDevelopersUsing')
        ->expectsOutputToContain('make:notifications-table')
        ->expectsOutputToContain('FeedbackBoardPlugin::make()')
        ->assertSuccessful();

    expect(File::exists(config_path('feedback-board.php')))->toBeTrue()
        ->and(publishedFeedbackMigrations())->toHaveCount(4)
        ->and(Artisan::all()['feedback-board:install']->isHidden())->toBeTrue();
});

it('is safe to re-run: keeps an edited config and does not duplicate migrations', function () {
    $this->artisan('feedback-board:install')
        ->expectsConfirmation('Would you like to run the migrations now?', 'no')
        ->assertSuccessful();
    File::append(config_path('feedback-board.php'), "\n// edited by the host app\n");

    // A later run recomputes the publish map with new timestamps; without filename
    // reuse it would publish a second set of migrations.
    $this->travel(2)->minutes();
    $this->app->register(FeedbackBoardServiceProvider::class, force: true);

    $this->artisan('feedback-board:install')
        ->expectsConfirmation('Would you like to run the migrations now?', 'no')
        ->assertSuccessful();

    expect(publishedFeedbackMigrations())->toHaveCount(4)
        ->and(File::get(config_path('feedback-board.php')))->toContain('// edited by the host app');
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/pest tests/Feature/InstallCommandTest.php`

Expected: 2 FAIL with `The command "feedback-board:install" does not exist.`

- [ ] **Step 3: Register the install command**

In `src/FeedbackBoardServiceProvider.php`, make three edits.

First, add this import directly **above** `use Spatie\LaravelPackageTools\Package;`, so the three imports read `Commands\InstallCommand`, `Package`, `PackageServiceProvider`:

```php
use Spatie\LaravelPackageTools\Commands\InstallCommand;
```

Second, in `configurePackage()`, replace `->hasRoute('web');` with:

```php
            ->hasRoute('web')
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->askToRunMigrations()
                    ->endWith(fn (InstallCommand $command) => $this->printNextSteps($command));
            });
```

Third, add this method after `packageBooted()`:

```php

    protected function printNextSteps(InstallCommand $command): void
    {
        $command->newLine();
        $command->info('Next steps:');
        $command->line('  1. Add the launcher to your authenticated layout: <x-feedback-board::launcher />');
        $command->line('  2. In AppServiceProvider::boot(), tell the package who your developers are:');
        $command->line('       FeedbackBoard::resolveDevelopersUsing(fn ($user) => $user->is_admin);');
        $command->line('       FeedbackBoard::notifyDevelopersUsing(fn () => User::where(\'is_admin\', true)->get());');
        $command->line('  3. Optional in-app notifications: run `php artisan make:notifications-table`, migrate,');
        $command->line('     and add \'database\' to feedback-board.notifications.channels.');

        if (class_exists(\Filament\Panel::class)) {
            $command->line('  4. Register the Filament plugin in your panel provider: ->plugin(FeedbackBoardPlugin::make())');
        }

        $command->line('  See the README for destinations, AI triage and the runner.');
        $command->newLine();
    }
```

Spatie publishes without `--force` and reuses an already-published migration's filename, so re-runs are safe (spec §7.2). The confirmation defaults to "no", so `--no-interaction` never migrates.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/InstallCommandTest.php`

Expected: 2 passed.

- [ ] **Step 5: Check the non-interactive path by hand, then clean up**

Run:
```bash
php vendor/bin/testbench feedback-board:install --no-interaction
rm -f vendor/orchestra/testbench-core/laravel/config/feedback-board.php vendor/orchestra/testbench-core/laravel/database/migrations/*_create_feedback_*_table.php
```

Expected:
- `Publishing config...` and `Publishing migrations...` appear.
- No migrations run.
- The five next-step lines print, including step 4, since Filament is installed in dev, with `<x-feedback-board::launcher />` printed literally.
- The output ends with `feedback-board has been installed!`. On package-tools 1.19 this line comes before the next steps; nothing depends on the order.
- The `rm` removes what the manual run published.

- [ ] **Step 6: Run the full suite**

Run: `vendor/bin/pest`

Expected: **235 passed**.

- [ ] **Step 7: Commit**

```bash
git add src/FeedbackBoardServiceProvider.php tests/Feature/InstallCommandTest.php
git commit -m "feat(install): add the feedback-board:install command

Publishes the config and the four migrations, offers to migrate (default no)
and prints the next steps: launcher, FeedbackBoard developer registration,
optional database notifications, and the Filament plugin when Filament is
installed. Re-runs never overwrite the config or duplicate migrations."
```

---

### Task 7: README

**Files:**
- Modify: `README.md`:
  - Requirements (lines 9-13)
  - Installation and publishing (15-46)
  - Usage §2 and §3 (72-107)
  - Filament Integration (109-119)
  - Configuration Reference rows (133, 138, 139)

**Interfaces:**
- Consumes:
  - The facade API (Task 4).
  - The channels default (Task 5).
  - The install command (Task 6).
  - The board URL `/admin/feedback-board-page` (spec delta 4).
- Produces: nothing for code.

Leave the launcher's "floating button … modal overlay" sentence alone; the styling spec makes it true.

- [ ] **Step 1: Requirements**

Replace the `## Requirements` list with:

```markdown
## Requirements

- PHP 8.2+ (8.3+ for Laravel 13)
- Laravel 12 or 13
- Livewire 3.8.3+ or 4.3.4+
- Filament 4 or 5 (optional, for the developer Kanban board)
```

- [ ] **Step 2: Installation**

Replace everything from `## Installation` up to, but not including, `## Usage` with:

````markdown
## Installation

```bash
composer require yousefaman/feedback-board
php artisan feedback-board:install
```

The install command publishes the config file and the migrations, offers to run the migrations, and prints the next steps. It is hidden from `php artisan list`, so type it as shown. Running it again is safe: an existing `config/feedback-board.php` is never overwritten and migrations are not published twice.

### Manual installation

To publish by hand instead:

```bash
php artisan vendor:publish --tag=feedback-board-config
php artisan vendor:publish --tag=feedback-board-migrations
php artisan migrate
```

Publish views (optional, for customisation):

```bash
php artisan vendor:publish --tag=feedback-board-views
```

Publish translation files (optional, for customisation):

```bash
php artisan vendor:publish --tag=feedback-board-translations
```

````

- [ ] **Step 3: Usage §2 and §3, plus a new §4 Notification channels**

Replace everything from `### 2. Configure the developer resolver` up to, but not including, `## Filament Integration` with:

````markdown
### 2. Tell the package who your developers are

Developers can triage, reply internally, assign and resolve reports. Register a resolver in your `AppServiceProvider::boot()`:

```php
use YousefAman\FeedbackBoard\Facades\FeedbackBoard;

public function boot(): void
{
    FeedbackBoard::resolveDevelopersUsing(fn ($user) => $user->hasRole('developer'));
}
```

Until you register a resolver, nobody is a developer.

> **Do not put a closure in `config/feedback-board.php`.** Laravel cannot cache closures, so `php artisan config:cache` (and `php artisan optimize`) fails on deploy. To configure access in the config file instead, point `authorization.developer_resolver` at a public static method:
>
> ```php
> 'developer_resolver' => [\App\Support\DeveloperCheck::class, 'handle'],
> ```
>
> A callback registered with `FeedbackBoard::resolveDevelopersUsing()` takes precedence over the config value.

To check access in your own code, use `FeedbackBoard::isDeveloper($user)`. It answers through the `feedback-board.manage` gate, so `Gate::before()` hooks (such as a super-admin rule) apply exactly as they do inside the package, and a guest (`null`) is never a developer.

### 3. Configure developer notifications

New reports, and replies from reporters, notify your developers. List their e-mail addresses in `.env`:

```dotenv
FEEDBACK_BOARD_DEVELOPER_EMAIL=dev@example.com
```

or in the config file:

```php
// config/feedback-board.php
'notifications' => [
    'developers' => ['dev@example.com', 'lead@example.com'],
],
```

Entries that are not valid e-mail addresses are skipped, with a warning in your log.

To notify user models instead (for example, to also use Filament's in-app bell), register them in `AppServiceProvider::boot()`. This takes precedence over the config e-mails:

```php
FeedbackBoard::notifyDevelopersUsing(fn () => User::where('is_admin', true)->get());
```

### 4. Notification channels

By default, notifications go out by `mail` only. To also store in-app (database) notifications:

```bash
php artisan make:notifications-table
php artisan migrate
```

Then add `'database'` to `notifications.channels` in `config/feedback-board.php`. To show the bell in a Filament panel, also call `->databaseNotifications()` on that panel. Recipients that are only e-mail addresses always receive mail only.

Notifications are sent after the report, reply or status change is saved. If sending fails (for example, the mail server is down), the failure is reported to your exception handler and the user's action is still kept.

````

- [ ] **Step 4: Filament Integration — URL fix and render hook**

Replace the sentence `This adds a Kanban-style Feedback Board page (\`/admin/feedback-board\`) to your panel where developers can triage, reply, assign, and resolve reports.` with:

````markdown
This adds a Kanban-style Feedback Board page (`/admin/feedback-board-page`) and a feedback items resource to your panel, where developers can triage, reply, assign, and resolve reports.

### Reporters who use a Filament panel

If your reporters work inside a Filament panel rather than a custom layout, render the launcher through a render hook in that panel's provider:

```php
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;

$panel->renderHook(
    PanelsRenderHook::BODY_END,
    fn (): string => Blade::render('<x-feedback-board::launcher />'),
);
```
````

- [ ] **Step 5: Configuration Reference rows**

Replace these three rows of the table:

```markdown
| `authorization.developer_resolver` | callable\|null | Returns `bool` — whether the current user is a developer |
| `notifications.developers` | array\|callable | Developer users/notifiables to notify on new reports |
| `notifications.channels` | array | Notification channels (default: `['mail', 'database']`) |
```

with:

```markdown
| `authorization.developer_resolver` | callable\|null | Public static method returning `bool`; prefer `FeedbackBoard::resolveDevelopersUsing()` (a closure here breaks `config:cache`) |
| `notifications.developers` | array\|callable | Developer e-mail addresses (invalid entries are skipped); for models prefer `FeedbackBoard::notifyDevelopersUsing()` |
| `notifications.channels` | array | Notification channels (default: `['mail']`; add `'database'` after `make:notifications-table`) |
```

- [ ] **Step 6: Check for stale text**

Run:
```bash
grep -nF -e "^11" -e "'developer_resolver' => function" -e "/admin/feedback-board\`" -e "['mail', 'database']" README.md
grep -nE "^\s+[12],$" README.md
```

Expected: no output from either command.

Then run `grep -n "feedback-board:install\|resolveDevelopersUsing\|notifyDevelopersUsing\|PanelsRenderHook" README.md`.

Expected: at least one hit each.

- [ ] **Step 7: Commit**

```bash
git add README.md
git commit -m "docs(readme): install command, FeedbackBoard API and notification channels

Requirements for Laravel 12/13 and Livewire 3/4; feedback-board:install as
the primary path; developer access and recipients via the facade with the
config:cache warning and the static-callable alternative; replace the broken
[1, 2] recipients example; document mail-only default channels and post-
commit delivery; correct the board URL and add a render-hook snippet for
reporters inside Filament panels; update the config reference rows."
```

---

### Task 8: Verification lanes (spec §9)

**Files:** none. This task only verifies; if a lane fails, report it and do not paper over it.

**Interfaces:**
- Consumes: the finished branch.
- Produces: the evidence for the merge decision.

Set `SP` to the session scratchpad directory, for example the path the harness lists as "Scratchpad directory", and `WT` to the worktree root.

- [ ] **Step 1: Repository stack (L13 / LW4 / F5)**

Run:
```bash
cd "$WT" && vendor/bin/pest 2>&1 | tail -3
composer validate --strict
composer show | grep -E "^(laravel/framework|livewire/livewire|filament/filament|pestphp/pest|orchestra/testbench|spatie/laravel-package-tools) "
```

Expected: `235 passed`, `./composer.json is valid`. Record the versions.

- [ ] **Step 2: Prefer-stable scratch lanes**

Run each lane with these arguments: `l12lw3 12.* 3.*`, `l12lw4 12.* 4.*` and `l13lw3 13.* 3.*`. For each lane:

```bash
L=l12lw3; LV='12.*'; LWV='3.*'
rm -rf "$SP/v1-$L" && mkdir -p "$SP/v1-$L"
(cd "$WT" && tar --exclude=vendor --exclude=.git --exclude=.claude --exclude=composer.lock -cf - .) | (cd "$SP/v1-$L" && tar xf -)
cd "$SP/v1-$L"
composer require "laravel/framework:$LV" "livewire/livewire:$LWV" --no-update --no-interaction
composer update --prefer-stable --prefer-dist --no-interaction --no-progress 2>&1 | tail -3
composer show | grep -E "^(laravel/framework|livewire/livewire|filament/filament|pestphp/pest) "
vendor/bin/pest 2>&1 | tail -3
```

Expected for each lane: `235 passed`. `l12lw3` and `l13lw3` resolve Filament 4, and those are the lanes where the `FilamentTestCase` provider order matters. `l12lw4` resolves Filament 5.

- [ ] **Step 3: Prefer-lowest lanes with Composer ≥ 2.9**

Run:
```bash
[ -f "$SP/composer-latest.phar" ] || curl -sSL https://getcomposer.org/download/latest-stable/composer.phar -o "$SP/composer-latest.phar"
php "$SP/composer-latest.phar" --version
```

Expected: `Composer version 2.9` or later.

Then, for `L=low3 LWV='3.*'` and `L=low4 LWV='4.*'`:

```bash
rm -rf "$SP/v1-$L" && mkdir -p "$SP/v1-$L"
(cd "$WT" && tar --exclude=vendor --exclude=.git --exclude=.claude --exclude=composer.lock -cf - .) | (cd "$SP/v1-$L" && tar xf -)
cd "$SP/v1-$L"
php "$SP/composer-latest.phar" require "laravel/framework:12.*" "livewire/livewire:$LWV" --no-update --no-interaction
COMPOSER_NO_SECURITY_BLOCKING=1 php "$SP/composer-latest.phar" update --prefer-lowest --prefer-dist --no-interaction --no-progress 2>&1 | tail -3
php "$SP/composer-latest.phar" show | grep -E "^(laravel/framework|livewire/livewire|filament/filament|pestphp/pest|orchestra/testbench|spatie/laravel-package-tools) "
vendor/bin/pest 2>&1 | tail -3
```

Expected: `235 passed` on both lanes. Livewire should resolve to the declared floors, `v3.8.3` and `v4.3.4` respectively. If it resolves higher, record which package forced it (`php "$SP/composer-latest.phar" why livewire/livewire`). Record the Composer version and every floor version shown.

- [ ] **Step 4: Report**

Summarize for the user:
- every lane: stack versions and pass count;
- the Composer version used for prefer-lowest;
- anything that failed, with output.

PHP 8.2 and 8.5 lanes and the browser-side Livewire 4 JavaScript (Kanban drag-and-drop, uploads) are not verifiable locally (spec §9.3). Say so explicitly.

Then use superpowers:finishing-a-development-branch to merge `feature/v1-release-readiness` into `master`.
