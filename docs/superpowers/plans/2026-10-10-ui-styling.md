# UI Styling Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a self-contained, RTL-aware, light/dark stylesheet for every Feedback Board surface, delivered automatically through a package route and Livewire `@assets`, together with the small markup and behavior changes the styled UI needs.

**Architecture:** One hand-written CSS file (`resources/dist/feedback-board.css`) served by `AssetController` at the extension-less path `/{route_prefix}/assets/stylesheet` with a content-hash `id` and attached by a single Blade partial (`feedback-board::partials.assets`) whose `@assets` block Livewire injects into `<head>` once per page. Every surface root carries `fb-scope`, `data-fb-theme` and `dir`; the CSS reads design tokens from `.fb-scope` and switches to dark tokens by `.dark` ancestor, `data-fb-theme`, or the OS preference. A static `Ui` helper owns the stylesheet path/version/URL, theme normalisation and safe-URL checks.

**Tech Stack:** PHP 8.2+, Laravel 12/13, Livewire 3.8.3+/4.3.4+ (bundled Alpine incl. Focus plugin), optional Filament 4/5, Pest 3/4, Orchestra Testbench 10/11. No front-end build.

**Spec:** `docs/superpowers/specs/2026-10-10-ui-styling-design.md`

## Global Constraints

- Versions unchanged: `php ^8.2`, `illuminate/contracts ^12.0 || ^13.0`, `livewire/livewire ^3.8.3 || ^4.3.4`, Filament optional `^4.0 || ^5.0`. **No new Composer or npm dependencies, no build step.**
- Every CSS selector starts with `.fb-`, `.dark .fb-` or `.fi .fb-`; the stylesheet contains no `@import`, no `url(`, no `!important`, no `@layer`; no `:has()`, CSS nesting or `color-mix()`.
- Token contract: the stylesheet never declares a public token (`--fb-accent`, `--fb-accent-hover`, `--fb-accent-contrast`, `--fb-radius`, `--fb-font`, `--fb-z`) — it reads each one with a fallback into a private `--_fb-*` alias, and every other rule uses `--_fb-*` tokens only — except `--fb-font`, which `font-family` reads directly with its fallback (a custom property cannot usefully hold `inherit`). No Filament (`--primary-*`, `--gray-*`) variables are read.
- Never set `display` in a rule that can match an element carrying `wire:loading` (Livewire hides those with a 0,2,0 attribute rule).
- Escape listeners use `.window` (`x-on:keydown.escape.window`): when a re-render removes the focused element (e.g. the submit button replaced by the sent panel), focus falls back to `<body>`, where a listener on the dialog panel would never fire. Only one package dialog can be open at a time — each backdrop covers the launcher.
- User-written text (titles, descriptions, comment bodies, the AI summary) and every free-text field carry `dir="auto"`, so English content reads correctly inside the Arabic UI and vice versa; captured technical values (URL, user agent, locale) are wrapped in `dir="ltr"`.
- Direction-sensitive CSS uses logical properties (`inset-inline-*`, `margin-inline-*`, `padding-inline`, `border-inline-*`, `text-align: start`); the only physical exceptions are `.fb-pre` (code is always LTR) and the drawer slide keyframes driven by `--_fb-slide-from`.
- The root scope class is `fb-scope`. Never introduce the class names `fb`, `fb-page`, `fb-post`, `fb-comments`, `fb-like`, `fb-video`, `fb-group`, `fb-quote`, `fb-save`, `fb-send`, `fb-follow` (Facebook SDK collisions).
- All dynamic output escaped with `{{ }}`; no `{!! !!}` anywhere in package views.
- Preserve verbatim: the `wire:poll.5s` attributes on the triage and runner blocks (inside-tag `@if … @endif`), the drag data contract (`dataTransfer` key `id`, `$wire.moveTo(id, status)`), every component name, every public Livewire method name and every `wire:submit` / `wire:model` / `wire:change` target.
- Every new translation key exists in both `resources/lang/en/feedback-board.php` and `resources/lang/ar/feedback-board.php`.
- No closures in `config/feedback-board.php` (`config:cache`).
- Code style: `declare(strict_types=1);` in new PHP files, 4-space indent, existing namespace layout. There is no linter in this repo: run `php -l` on every changed PHP file and the full suite `vendor/bin/pest` before each commit.
- Commit messages: conventional (`feat(ui): …`, `test(ui): …`, `docs: …`), written as the work itself — **no `Co-Authored-By` or any tool attribution**.

## Review Focus

1. **Host CSS frameworks** (Tailwind preflight + `@tailwindcss/forms`, Bootstrap reboot) restyling package controls — package classes must win over element selectors and tie-break after them; checked by specificity rules in Task 1 and the browser pass.
2. **Arabic / RTL** — launcher in the bottom-left corner, drawer from the left and sliding in from the left, URLs, user agents and diffs kept LTR inside RTL; pinned by `dir` assertions (Tasks 3–6) and the browser pass.
3. **Dark mode in three contexts** — Filament's `.dark`, standalone pages under a dark OS, and a forced `light` inside a `.dark` app; pinned by the dark-parity guard (Task 1) and theme attribute tests (Tasks 3–6).
4. **A hostile `meta.url`** (`javascript:`, whitespace-prefixed, mixed-case schemes) never becoming an `href`; pinned in Task 1 (`Ui::safeUrl`) and Task 5 (rendered detail).
5. **Two surfaces on one page** (launcher + "My reports" form) — exactly one stylesheet link and no duplicate element ids; pinned in Task 1 (double include) and Task 6 (reports page).

---

### Task 1: Stylesheet delivery, `Ui` helper and CSS foundation

**Files:**
- Create: `resources/dist/feedback-board.css`
- Create: `resources/views/partials/assets.blade.php`
- Create: `src/Support/Ui.php`
- Create: `src/Http/Controllers/AssetController.php`
- Modify: `routes/web.php`
- Modify: `config/feedback-board.php` (new `ui` block after `route_middleware`)
- Modify: `tests/TestCase.php` (flush Livewire state in `setUp()`)
- Test: `tests/Unit/UiTest.php`, `tests/Unit/StylesheetTest.php`, `tests/Feature/AssetsTest.php`

**Interfaces:**
- Produces (used by every later task):
  - `YousefAman\FeedbackBoard\Support\Ui` — `stylesheetPath(): string`, `stylesheetVersion(): string` (12 lowercase hex chars), `stylesheetUrl(): string` (absolute, like Livewire's own script URL), `theme(): string` (`class|system|light|dark`), `colorScheme(): string` (`light`, `dark` or `light dark`), `safeUrl(mixed $url): ?string`.
  - Blade partial `feedback-board::partials.assets` — renders nothing in place; registers the stylesheet `<link>` with Livewire `@assets`.
  - Route `feedback-board.assets.css` → `GET /{route_prefix}/assets/stylesheet` — no file extension on purpose: common nginx configs answer `*.css` paths from disk and 404 before PHP runs.
  - Token contract: the stylesheet **never declares** the public tokens (`--fb-accent`, `--fb-accent-hover`, `--fb-accent-contrast`, `--fb-radius`, `--fb-font`, `--fb-z`); it reads each one with a fallback into a private `--_fb-*` alias on `.fb-scope`, and every rule uses only `--_fb-*` tokens (the one exception is `--fb-font`, read directly by `font-family` with its fallback). A host value set anywhere (`:root`, `.fb-scope`, any `@layer`) therefore always wins over the package, whatever the stylesheet order.
  - CSS foundation classes: `fb-scope`, `fb-sr-only`, `fb-muted`, `fb-link`, `fb-actions`, `fb-btn`, `fb-btn--primary`, `fb-btn--sm`, `fb-icon-btn`, `fb-field`, `fb-label`, `fb-input`, `fb-select`, `fb-textarea`, `fb-file`, `fb-hint`, `fb-error`, `fb-badge` + `--gray|--info|--warning|--success|--danger`, `fb-chip`, `fb-dot` + the same five colors, `fb-notice` + `--warning|--danger`, `fb-panel`, `fb-panel__title`, `fb-section`, `fb-section-title`, `fb-prose`, `fb-pre`, `fb-details`, `fb-empty`.
  - Root attribute contract every later surface uses verbatim:
    `class="fb-scope …" data-fb-theme="{{ \YousefAman\FeedbackBoard\Support\Ui::theme() }}" dir="{{ __('feedback-board::feedback-board.dir') }}"` with `@include('feedback-board::partials.assets')` as the root's first child.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/UiTest.php`:

```php
<?php

use YousefAman\FeedbackBoard\Support\Ui;

it('normalises the configured theme', function (mixed $value, string $expected) {
    config()->set('feedback-board.ui.theme', $value);

    expect(Ui::theme())->toBe($expected);
})->with([
    ['class', 'class'],
    ['system', 'system'],
    ['light', 'light'],
    ['dark', 'dark'],
    ['blue', 'class'],
    ['DARK', 'class'],
    [null, 'class'],
    [1, 'class'],
]);

it('defaults the theme to class', function () {
    expect(config('feedback-board.ui.theme'))->toBe('class')
        ->and(Ui::theme())->toBe('class');
});

it('accepts only http and https URLs as link targets', function (mixed $url, ?string $expected) {
    expect(Ui::safeUrl($url))->toBe($expected);
})->with([
    ['https://app.test/orders?id=1', 'https://app.test/orders?id=1'],
    ['HTTP://APP.TEST/', 'HTTP://APP.TEST/'],
    ['javascript:alert(1)', null],
    ['JaVaScRiPt:alert(1)', null],
    [' javascript:alert(1)', null],
    ["java\tscript:alert(1)", null],
    ['data:text/html,<script>alert(1)</script>', null],
    ['//evil.test/x', null],
    ['/relative/path', null],
    ['', null],
    [null, null],
    [['https://x.test'], null],
]);

it('versions the stylesheet by content and links it absolutely', function () {
    $version = Ui::stylesheetVersion();

    expect($version)->toMatch('/^[0-9a-f]{12}$/')
        ->and($version)->toBe(substr(md5_file(Ui::stylesheetPath()), 0, 12))
        ->and(Ui::stylesheetUrl())->toBe('http://localhost/feedback-board/assets/stylesheet?id='.$version)
        ->and(is_file(Ui::stylesheetPath()))->toBeTrue();
});

it('keeps a sub-directory base path in the stylesheet URL', function () {
    url()->forceRootUrl('http://host.test/sub');

    expect(Ui::stylesheetUrl())->toBe('http://host.test/sub/feedback-board/assets/stylesheet?id='.Ui::stylesheetVersion());
});

it('maps the theme to the page color-scheme', function (string $theme, string $scheme) {
    config()->set('feedback-board.ui.theme', $theme);

    expect(Ui::colorScheme())->toBe($scheme);
})->with([['class', 'light dark'], ['system', 'light dark'], ['light', 'light'], ['dark', 'dark']]);
```

`tests/Unit/StylesheetTest.php`:

```php
<?php

use YousefAman\FeedbackBoard\Support\Ui;

/**
 * Minimal reader for the package stylesheet: every style rule with the at-rule prelude it sits in.
 * Descends into @media/@supports; skips @keyframes (its from/to blocks are not selectors).
 *
 * @return list<array{selector: string, body: string, at: ?string}>
 */
function fbCssRules(string $css): array
{
    $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
    $rules = [];

    $walk = function (string $chunk, ?string $at) use (&$walk, &$rules): void {
        $offset = 0;
        $length = strlen($chunk);

        while (($open = strpos($chunk, '{', $offset)) !== false) {
            $prelude = trim(substr($chunk, $offset, $open - $offset));
            $depth = 1;
            $cursor = $open + 1;

            while ($cursor < $length && $depth > 0) {
                $depth += match ($chunk[$cursor]) { '{' => 1, '}' => -1, default => 0 };
                $cursor++;
            }

            $body = substr($chunk, $open + 1, $cursor - $open - 2);

            if (str_starts_with($prelude, '@media') || str_starts_with($prelude, '@supports')) {
                $walk($body, $prelude);
            } elseif (! str_starts_with($prelude, '@')) {
                $rules[] = ['selector' => $prelude, 'body' => trim($body), 'at' => $at];
            }

            $offset = $cursor;
        }
    };

    $walk($css, null);

    return $rules;
}

/** Split a selector list on top-level commas (commas inside :where(...) stay put). */
function fbSplitSelectors(string $list): array
{
    $parts = [];
    $depth = 0;
    $buffer = '';

    foreach (str_split($list) as $char) {
        $depth += match ($char) { '(' => 1, ')' => -1, default => 0 };

        if ($char === ',' && $depth === 0) {
            $parts[] = trim($buffer);
            $buffer = '';

            continue;
        }

        $buffer .= $char;
    }

    $parts[] = trim($buffer);

    return $parts;
}

/** @return array<string, string> private custom property => value */
function fbDeclarations(string $body): array
{
    preg_match_all('/(--_fb-[a-z0-9-]+)\s*:\s*([^;]+);/', $body, $m);

    return array_combine($m[1], array_map(fn ($v) => preg_replace('/\s+/', ' ', trim($v)), $m[2]));
}

function fbStylesheet(): string
{
    return (string) file_get_contents(Ui::stylesheetPath());
}

it('scopes every selector to a package class', function () {
    $unscoped = [];

    foreach (fbCssRules(fbStylesheet()) as $rule) {
        foreach (fbSplitSelectors($rule['selector']) as $selector) {
            $selector = (string) preg_replace('/\s+/', ' ', $selector);

            if (! preg_match('/^(\.dark |\.fi )?\.fb-/', $selector)) {
                $unscoped[] = $selector;
            }
        }
    }

    expect($unscoped)->toBe([]);
});

it('is self-contained and override-friendly', function () {
    $css = (string) preg_replace('#/\*.*?\*/#s', '', fbStylesheet());

    expect($css)->not->toContain('@import')
        ->and($css)->not->toContain('url(')
        ->and($css)->not->toContain('!important')
        ->and($css)->not->toContain('@layer')
        ->and($css)->not->toContain(':has(')
        ->and($css)->not->toContain('color-mix(');
});

it('declares every private --_fb- token it reads', function () {
    $css = fbStylesheet();
    preg_match_all('/var\(\s*(--_fb-[a-z0-9-]+)/', $css, $used);
    preg_match_all('/(--_fb-[a-z0-9-]+)\s*:/', $css, $declared);

    expect(array_values(array_diff(array_unique($used[1]), $declared[1])))->toBe([]);
});

it('never declares a public --fb- token and reads each one only with a fallback', function () {
    $css = (string) preg_replace('#/\*.*?\*/#s', '', fbStylesheet());
    preg_match_all('/(?<![\w-])(--fb-[a-z0-9-]+)\s*:/', $css, $declared);
    preg_match_all('/var\(\s*(--fb-[a-z0-9-]+)\s*([,)])/', $css, $reads);

    expect($declared[1])->toBe([])
        ->and(array_values(array_unique($reads[1])))->toEqualCanonicalizing(['--fb-accent', '--fb-accent-hover', '--fb-accent-contrast', '--fb-radius', '--fb-font', '--fb-z'])
        ->and(array_unique($reads[2]))->toBe([',']);
});

it('uses no class name that collides with the Facebook SDK', function () {
    preg_match_all('/\.(fb-[a-z0-9_-]*[a-z0-9])/', fbStylesheet(), $m);

    expect(array_intersect(array_unique($m[1]), ['fb-page', 'fb-post', 'fb-comments', 'fb-like', 'fb-video', 'fb-group', 'fb-quote', 'fb-save', 'fb-send', 'fb-follow']))->toBe([]);
});

it('declares the same dark tokens for the class/forced and the system/standalone selectors', function () {
    $rules = collect(fbCssRules(fbStylesheet()));

    $outside = $rules->first(fn ($r) => $r['at'] === null && str_contains($r['selector'], '.fb-scope[data-fb-theme="dark"]'));
    $inside = $rules->first(fn ($r) => str_contains((string) $r['at'], 'prefers-color-scheme: dark')
        && str_contains($r['selector'], '.fb-scope[data-fb-theme="system"]'));

    expect($outside)->not->toBeNull()
        ->and($inside)->not->toBeNull()
        ->and(fbSplitSelectors($outside['selector']))->toContain('.dark .fb-scope[data-fb-theme="class"]')
        ->and(fbSplitSelectors($inside['selector']))->toContain('.fb-standalone[data-fb-theme="class"]')
        ->and(fbSplitSelectors($inside['selector']))->toContain('.fb-standalone [data-fb-theme="class"]')
        ->and(fbDeclarations($inside['body']))->toBe(fbDeclarations($outside['body']))
        ->and($outside['body'])->toContain('color-scheme: dark')
        ->and($inside['body'])->toContain('color-scheme: dark');
});

it('only overrides tokens in dark mode that the light theme declares', function () {
    $rules = collect(fbCssRules(fbStylesheet()));
    $light = $rules->first(fn ($r) => $r['at'] === null && $r['selector'] === '.fb-scope');
    $dark = $rules->first(fn ($r) => $r['at'] === null && str_contains($r['selector'], '.fb-scope[data-fb-theme="dark"]'));

    expect(array_diff(array_keys(fbDeclarations($dark['body'])), array_keys(fbDeclarations($light['body']))))->toBe([]);
});
```

`tests/Feature/AssetsTest.php`:

```php
<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use YousefAman\FeedbackBoard\Support\Ui;

it('serves the stylesheet to guests with long-lived caching for the current version', function () {
    $response = $this->get(Ui::stylesheetUrl());

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/css; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->getContent())->toBe(file_get_contents(Ui::stylesheetPath()))
        ->and($response->headers->get('Cache-Control'))->toContain('public')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=31536000')
        ->and($response->headers->get('Cache-Control'))->toContain('immutable');
});

it('does not pin a stale, missing or malformed version', function (string $query) {
    $response = $this->get('/feedback-board/assets/stylesheet'.$query);

    $response->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-cache')
        ->and($response->headers->get('Cache-Control'))->not->toContain('immutable');
})->with(['', '?id=000000000000', '?id[]=x']);

it('registers the stylesheet route without session or auth middleware', function () {
    $route = app('router')->getRoutes()->getByName('feedback-board.assets.css');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('feedback-board/assets/stylesheet')
        ->and($route->gatherMiddleware())->toBe([]);
});

it('renders nothing in place from the assets partial', function () {
    expect(trim(view('feedback-board::partials.assets')->render()))->toBe('');
});

it('injects the stylesheet into the head of a full HTML page exactly once', function () {
    Route::get('/fb-test-page', fn () => Blade::render(
        '<!doctype html><html><head><title>t</title></head><body>'
        .'@include(\'feedback-board::partials.assets\')<main>x</main>@include(\'feedback-board::partials.assets\')'
        .'</body></html>'
    ));

    $html = $this->get('/fb-test-page')->assertOk()->getContent();
    $link = '<link rel="stylesheet" href="'.Ui::stylesheetUrl().'"';

    expect(substr_count($html, $link))->toBe(1)
        ->and(strpos($html, $link))->toBeLessThan(strpos($html, '</head>'));
});

it('adds no stylesheet to a page without a package surface', function () {
    Route::get('/fb-test-plain', fn () => Blade::render('<!doctype html><html><head></head><body>plain</body></html>'));

    expect($this->get('/fb-test-plain')->assertOk()->getContent())->not->toContain('assets/stylesheet');
});

it('carries the Vite CSP nonce on the stylesheet link when one is set', function () {
    Vite::useCspNonce('fb-nonce-123');
    Route::get('/fb-test-nonce', fn () => Blade::render(
        '<!doctype html><html><head></head><body>@include(\'feedback-board::partials.assets\')</body></html>'
    ));

    expect($this->get('/fb-test-nonce')->getContent())->toContain('nonce="fb-nonce-123"');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/UiTest.php tests/Unit/StylesheetTest.php tests/Feature/AssetsTest.php`
Expected: FAIL — `Class "YousefAman\FeedbackBoard\Support\Ui" not found` and a missing `feedback-board::partials.assets` view / route. Only the negative control "adds no stylesheet to a page without a package surface" passes already.

- [ ] **Step 3: Isolate Livewire's static asset bookkeeping between tests**

Livewire records `@assets` keys in static properties that only `flushState()` resets (and `Livewire::test()` calls it only after rendering), so without this, whether a page "already" has the stylesheet — and even the compile-time asset key after a `view:clear` — depends on test order. In `tests/TestCase.php`, add inside the class, before `getPackageProviders()`:

```php
    protected function setUp(): void
    {
        parent::setUp();

        // Livewire keeps @assets bookkeeping in statics that only flushState() resets.
        app('livewire')->flushState();
    }
```

(`tests/Filament/FilamentTestCase.php` extends this class, so it inherits the reset.)

- [ ] **Step 4: Add the config block**

In `config/feedback-board.php`, insert directly after the `'route_middleware' => ['web', 'auth'],` line:

```php

    'ui' => [
        // Dark-mode strategy for the package's own screens:
        //   'class'  — dark when an ancestor element has the class "dark" (Filament panels, Laravel's
        //              Livewire/Flux starter kits, Tailwind's class strategy). The package's standalone
        //              pages have no host to set that class, so there they follow the operating system.
        //   'system' — follow the operating system (prefers-color-scheme).
        //   'light' / 'dark' — always light / always dark.
        'theme' => env('FEEDBACK_BOARD_THEME', 'class'),
    ],
```

- [ ] **Step 5: Create the `Ui` helper**

`src/Support/Ui.php`:

```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Support;

final class Ui
{
    /** @var list<string> */
    public const THEMES = ['class', 'system', 'light', 'dark'];

    public static function stylesheetPath(): string
    {
        return dirname(__DIR__, 2).'/resources/dist/feedback-board.css';
    }

    // Not memoized: long-lived workers (Octane) can outlive a package upgrade, and hashing
    // the ~20 KB file costs microseconds.
    public static function stylesheetVersion(): string
    {
        return substr((string) md5_file(self::stylesheetPath()), 0, 12);
    }

    // Absolute, like Livewire's own script URL: a root-relative route() drops a sub-directory base path.
    public static function stylesheetUrl(): string
    {
        return route('feedback-board.assets.css', ['id' => self::stylesheetVersion()]);
    }

    public static function theme(): string
    {
        $theme = config('feedback-board.ui.theme', 'class');

        return in_array($theme, self::THEMES, true) ? $theme : 'class';
    }

    // For the standalone pages' <meta name="color-scheme"> (viewport scrollbars and canvas).
    public static function colorScheme(): string
    {
        return match (self::theme()) {
            'light' => 'light',
            'dark' => 'dark',
            default => 'light dark',
        };
    }

    // Allow-list: only http(s) URLs may become an href (meta.url comes from a client-controlled header).
    public static function safeUrl(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true) ? $url : null;
    }
}
```

- [ ] **Step 6: Create the controller and route**

`src/Http/Controllers/AssetController.php`:

```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use YousefAman\FeedbackBoard\Support\Ui;

class AssetController
{
    public function css(Request $request): Response
    {
        $id = $request->query('id');
        $current = is_string($id) && hash_equals(Ui::stylesheetVersion(), $id);

        return new Response((string) file_get_contents(Ui::stylesheetPath()), 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            // An outdated or missing id must not pin stale CSS in caches for a year.
            'Cache-Control' => $current ? 'public, max-age=31536000, immutable' : 'no-cache',
        ]);
    }
}
```

In `routes/web.php`, add `use YousefAman\FeedbackBoard\Http\Controllers\AssetController;` to the imports (keep them alphabetical: `AssetController` before `AttachmentController`) and append at the end of the file:

```php

// Public, static and cookie-less: deliberately outside route_middleware (no session, no auth).
// No ".css" extension: common nginx configs serve *.css from disk and 404 before PHP runs.
Route::prefix(config('feedback-board.route_prefix', 'feedback-board'))
    ->name('feedback-board.')
    ->get('assets/stylesheet', [AssetController::class, 'css'])->name('assets.css');
```

Also delete the stale first comment line `// Feedback Board routes — filled in Task 4` at the top of `routes/web.php`.

- [ ] **Step 7: Create the assets partial**

`resources/views/partials/assets.blade.php` (exactly this; the `@assets` block buffers its content, so the partial prints nothing where it is included):

```blade
@assets
<link rel="stylesheet" href="{{ \YousefAman\FeedbackBoard\Support\Ui::stylesheetUrl() }}"@if ($fbNonce = \Illuminate\Support\Facades\Vite::cspNonce()) nonce="{{ $fbNonce }}"@endif>
@endassets
```

- [ ] **Step 8: Create the stylesheet foundation**

`resources/dist/feedback-board.css` — exactly:

```css
/*
 * Feedback Board — self-contained stylesheet (no build step).
 * Every selector is scoped to a .fb-* class; host markup is never styled.
 *
 * Public tokens — set them anywhere in your own CSS (:root, .fb-scope, any layer):
 *   --fb-accent, --fb-accent-hover, --fb-accent-contrast, --fb-radius, --fb-font, --fb-z
 * The package never declares them itself; it only reads them, with fallbacks, through the
 * private --_fb-* aliases below, so a host value always wins regardless of stylesheet order.
 * --_fb-* tokens are internal and may change between releases.
 */

/* ---------- Tokens: light (default) ---------- */

.fb-scope {
    --_fb-accent: var(--fb-accent, #4f46e5);
    --_fb-accent-hover: var(--fb-accent-hover, #4338ca);
    --_fb-accent-contrast: var(--fb-accent-contrast, #fff);
    --_fb-radius: var(--fb-radius, 0.75rem);
    --_fb-z: var(--fb-z, 1000);

    --_fb-radius-sm: 0.5rem;
    --_fb-bg: #fafafa;
    --_fb-surface: #fff;
    --_fb-surface-muted: #f4f4f5;
    --_fb-border: #e4e4e7;
    --_fb-border-strong: #d4d4d8;
    --_fb-text: #09090b;
    --_fb-text-muted: #52525b;
    --_fb-link: #4f46e5;
    --_fb-ring: #6366f1;
    --_fb-backdrop: rgb(9 9 11 / 0.5);
    --_fb-shadow: 0 1px 2px rgb(0 0 0 / 0.05), 0 1px 3px rgb(0 0 0 / 0.08);
    --_fb-shadow-lg: 0 20px 25px -5px rgb(0 0 0 / 0.12), 0 8px 10px -6px rgb(0 0 0 / 0.1);
    --_fb-gray-bg: #f4f4f5;
    --_fb-gray-fg: #3f3f46;
    --_fb-info-bg: #e0f2fe;
    --_fb-info-fg: #075985;
    --_fb-warning-bg: #fef3c7;
    --_fb-warning-fg: #92400e;
    --_fb-success-bg: #dcfce7;
    --_fb-success-fg: #166534;
    --_fb-danger-bg: #fee2e2;
    --_fb-danger-fg: #991b1b;
    --_fb-internal-bg: #fffbeb;
    --_fb-internal-border: #f59e0b;

    color-scheme: light;
    color: var(--_fb-text);
    font-family: var(--fb-font, inherit);
    font-size: 0.875rem;
    line-height: 1.5;
    text-align: start;
}

/* Inside a Filament panel: launcher (35) above the top bar (30) and below Filament modals (40);
   package overlays (45) above the sidebar and below Filament notifications (50). */
.fi .fb-scope {
    --_fb-z: var(--fb-z, 35);
}

/* ---------- Tokens: dark ---------- */

.dark .fb-scope[data-fb-theme="class"],
.fb-scope[data-fb-theme="dark"] {
    --_fb-bg: #09090b;
    --_fb-surface: #18181b;
    --_fb-surface-muted: #27272a;
    --_fb-border: rgb(255 255 255 / 0.1);
    --_fb-border-strong: rgb(255 255 255 / 0.2);
    --_fb-text: #fafafa;
    --_fb-text-muted: #a1a1aa;
    --_fb-link: #a5b4fc;
    --_fb-ring: #818cf8;
    --_fb-backdrop: rgb(0 0 0 / 0.65);
    --_fb-shadow: 0 1px 2px rgb(0 0 0 / 0.4);
    --_fb-shadow-lg: 0 20px 25px -5px rgb(0 0 0 / 0.5), 0 8px 10px -6px rgb(0 0 0 / 0.4);
    --_fb-gray-bg: rgb(255 255 255 / 0.08);
    --_fb-gray-fg: #d4d4d8;
    --_fb-info-bg: rgb(14 165 233 / 0.15);
    --_fb-info-fg: #7dd3fc;
    --_fb-warning-bg: rgb(245 158 11 / 0.15);
    --_fb-warning-fg: #fcd34d;
    --_fb-success-bg: rgb(34 197 94 / 0.15);
    --_fb-success-fg: #86efac;
    --_fb-danger-bg: rgb(239 68 68 / 0.15);
    --_fb-danger-fg: #fca5a5;
    --_fb-internal-bg: rgb(245 158 11 / 0.08);
    --_fb-internal-border: #d97706;
    color-scheme: dark;
}

@media (prefers-color-scheme: dark) {
    .fb-scope[data-fb-theme="system"],
    .fb-standalone[data-fb-theme="class"],
    .fb-standalone [data-fb-theme="class"] {
        --_fb-bg: #09090b;
        --_fb-surface: #18181b;
        --_fb-surface-muted: #27272a;
        --_fb-border: rgb(255 255 255 / 0.1);
        --_fb-border-strong: rgb(255 255 255 / 0.2);
        --_fb-text: #fafafa;
        --_fb-text-muted: #a1a1aa;
        --_fb-link: #a5b4fc;
        --_fb-ring: #818cf8;
        --_fb-backdrop: rgb(0 0 0 / 0.65);
        --_fb-shadow: 0 1px 2px rgb(0 0 0 / 0.4);
        --_fb-shadow-lg: 0 20px 25px -5px rgb(0 0 0 / 0.5), 0 8px 10px -6px rgb(0 0 0 / 0.4);
        --_fb-gray-bg: rgb(255 255 255 / 0.08);
        --_fb-gray-fg: #d4d4d8;
        --_fb-info-bg: rgb(14 165 233 / 0.15);
        --_fb-info-fg: #7dd3fc;
        --_fb-warning-bg: rgb(245 158 11 / 0.15);
        --_fb-warning-fg: #fcd34d;
        --_fb-success-bg: rgb(34 197 94 / 0.15);
        --_fb-success-fg: #86efac;
        --_fb-danger-bg: rgb(239 68 68 / 0.15);
        --_fb-danger-fg: #fca5a5;
        --_fb-internal-bg: rgb(245 158 11 / 0.08);
        --_fb-internal-border: #d97706;
        color-scheme: dark;
    }
}

/* ---------- Scoped normalisation (zero-specificity element part via :where) ---------- */

.fb-scope,
.fb-scope *,
.fb-scope *::before,
.fb-scope *::after {
    box-sizing: border-box;
}

.fb-scope :where(button, input, select, textarea) {
    margin: 0;
    font: inherit;
    color: inherit;
    letter-spacing: normal;
}

.fb-scope :where(h1, h2, h3, h4, p, ul, ol, dl, dd, figure, pre, blockquote) {
    margin: 0;
}

.fb-scope :where(ul, ol) {
    padding: 0;
    list-style: none;
}

.fb-scope :where(a) {
    color: var(--_fb-link);
}

.fb-scope :where(svg) {
    flex: none;
}

.fb-scope :focus-visible {
    outline: 2px solid var(--_fb-ring);
    outline-offset: 2px;
}

.fb-scope [x-cloak] {
    display: none;
}

/* ---------- Utilities ---------- */

.fb-sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

.fb-muted {
    color: var(--_fb-text-muted);
}

.fb-link {
    color: var(--_fb-link);
    overflow-wrap: anywhere;
}

.fb-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
}

/* ---------- Buttons ---------- */

.fb-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.375rem;
    min-height: 2.25rem;
    padding: 0.375rem 0.875rem;
    border: 1px solid var(--_fb-border-strong);
    border-radius: var(--_fb-radius-sm);
    background: var(--_fb-surface);
    color: var(--_fb-text);
    font-size: 0.875rem;
    font-weight: 600;
    line-height: 1.25;
    text-decoration: none;
    white-space: nowrap;
    cursor: pointer;
    transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease;
}

.fb-btn:hover {
    background: var(--_fb-surface-muted);
}

.fb-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.fb-btn--primary {
    border-color: var(--_fb-accent);
    background: var(--_fb-accent);
    color: var(--_fb-accent-contrast);
}

.fb-btn--primary:hover {
    border-color: var(--_fb-accent-hover);
    background: var(--_fb-accent-hover);
}

.fb-btn--sm {
    min-height: 1.875rem;
    padding: 0.25rem 0.625rem;
    font-size: 0.8125rem;
}

.fb-icon-btn {
    display: inline-grid;
    place-items: center;
    inline-size: 2.25rem;
    block-size: 2.25rem;
    padding: 0;
    border: 0;
    border-radius: var(--_fb-radius-sm);
    background: transparent;
    color: var(--_fb-text-muted);
    cursor: pointer;
    transition: background-color 0.15s ease, color 0.15s ease;
}

.fb-icon-btn:hover {
    background: var(--_fb-surface-muted);
    color: var(--_fb-text);
}

.fb-icon-btn svg {
    width: 1.25rem;
    height: 1.25rem;
}

/* ---------- Fields ---------- */

.fb-field {
    display: grid;
    gap: 0.375rem;
    min-width: 0;
}

.fb-label {
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--_fb-text);
}

.fb-input,
.fb-select,
.fb-textarea {
    display: block;
    width: 100%;
    min-height: 2.5rem;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--_fb-border-strong);
    border-radius: var(--_fb-radius-sm);
    background: var(--_fb-surface);
    background-image: none;
    color: var(--_fb-text);
    font-size: 0.875rem;
    line-height: 1.4;
    box-shadow: none;
}

.fb-select {
    appearance: auto;
    padding-inline-end: 0.5rem;
}

.fb-textarea {
    min-height: 6rem;
    resize: vertical;
}

.fb-input::placeholder,
.fb-textarea::placeholder {
    color: var(--_fb-text-muted);
    opacity: 1;
}

/* dir="auto" fields fall back to left-to-right while empty; keep an empty field (and its
   placeholder) in the interface direction until the user types. */
.fb-scope[dir="rtl"] .fb-input:placeholder-shown,
.fb-scope[dir="rtl"] .fb-textarea:placeholder-shown {
    direction: rtl;
}

.fb-input:focus,
.fb-select:focus,
.fb-textarea:focus {
    border-color: var(--_fb-ring);
    outline: 2px solid var(--_fb-ring);
    outline-offset: 0;
    box-shadow: none;
}

.fb-file {
    display: block;
    width: 100%;
    color: var(--_fb-text-muted);
    font-size: 0.8125rem;
}

.fb-file::file-selector-button {
    margin-inline-end: 0.75rem;
    padding: 0.375rem 0.75rem;
    border: 1px solid var(--_fb-border-strong);
    border-radius: var(--_fb-radius-sm);
    background: var(--_fb-surface-muted);
    color: var(--_fb-text);
    font: inherit;
    font-weight: 600;
    cursor: pointer;
}

.fb-hint {
    font-size: 0.75rem;
    color: var(--_fb-text-muted);
}

.fb-error {
    font-size: 0.75rem;
    font-weight: 500;
    color: var(--_fb-danger-fg);
}

@media (max-width: 40rem) {
    .fb-input,
    .fb-select,
    .fb-textarea {
        font-size: 1rem;
    }
}

/* ---------- Badges, chips, dots, notices ---------- */

.fb-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.125rem 0.5rem;
    border-radius: 999px;
    background: var(--_fb-gray-bg);
    color: var(--_fb-gray-fg);
    font-size: 0.75rem;
    font-weight: 600;
    line-height: 1.25;
    white-space: nowrap;
}

.fb-badge--gray {
    background: var(--_fb-gray-bg);
    color: var(--_fb-gray-fg);
}

.fb-badge--info {
    background: var(--_fb-info-bg);
    color: var(--_fb-info-fg);
}

.fb-badge--warning {
    background: var(--_fb-warning-bg);
    color: var(--_fb-warning-fg);
}

.fb-badge--success {
    background: var(--_fb-success-bg);
    color: var(--_fb-success-fg);
}

.fb-badge--danger {
    background: var(--_fb-danger-bg);
    color: var(--_fb-danger-fg);
}

.fb-chip {
    display: inline-flex;
    align-items: center;
    padding: 0.0625rem 0.5rem;
    border: 1px solid var(--_fb-border);
    border-radius: 999px;
    color: var(--_fb-text-muted);
    font-size: 0.75rem;
    line-height: 1.25rem;
    white-space: nowrap;
}

.fb-dot {
    display: inline-block;
    inline-size: 0.5rem;
    block-size: 0.5rem;
    border-radius: 999px;
    background: #a1a1aa;
    flex: none;
}

.fb-dot--gray {
    background: #a1a1aa;
}

.fb-dot--info {
    background: #0ea5e9;
}

.fb-dot--warning {
    background: #f59e0b;
}

.fb-dot--success {
    background: #22c55e;
}

.fb-dot--danger {
    background: #ef4444;
}

.fb-notice {
    padding: 0.625rem 0.75rem;
    border-radius: var(--_fb-radius-sm);
    background: var(--_fb-gray-bg);
    color: var(--_fb-gray-fg);
    font-size: 0.8125rem;
}

.fb-notice--warning {
    background: var(--_fb-warning-bg);
    color: var(--_fb-warning-fg);
}

.fb-notice--danger {
    background: var(--_fb-danger-bg);
    color: var(--_fb-danger-fg);
}

/* ---------- Containers and text ---------- */

.fb-panel {
    padding: 1rem;
    border: 1px solid var(--_fb-border);
    border-radius: var(--_fb-radius);
    background: var(--_fb-surface);
    box-shadow: var(--_fb-shadow);
}

.fb-panel__title {
    margin-block-end: 1rem;
    font-size: 1rem;
    font-weight: 600;
}

.fb-section {
    display: grid;
    gap: 0.5rem;
}

.fb-section-title {
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--_fb-text-muted);
}

.fb-prose {
    white-space: pre-line;
    overflow-wrap: anywhere;
}

.fb-pre {
    max-height: 20rem;
    overflow: auto;
    padding: 0.75rem;
    border-radius: var(--_fb-radius-sm);
    background: var(--_fb-surface-muted);
    color: var(--_fb-text);
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
    font-size: 0.75rem;
    line-height: 1.5;
    white-space: pre;
    direction: ltr;
    text-align: left;
}

.fb-details summary {
    cursor: pointer;
    font-weight: 600;
}

.fb-details[open] summary {
    margin-block-end: 0.5rem;
}

.fb-empty {
    padding: 1.5rem 1rem;
    border: 1px dashed var(--_fb-border-strong);
    border-radius: var(--_fb-radius);
    color: var(--_fb-text-muted);
    text-align: center;
}
```

- [ ] **Step 9: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/UiTest.php tests/Unit/StylesheetTest.php tests/Feature/AssetsTest.php`
Expected: PASS (all tests in the three files).

- [ ] **Step 10: Run the full suite and lint**

Run: `php -l src/Support/Ui.php && php -l src/Http/Controllers/AssetController.php && php -l routes/web.php && php -l config/feedback-board.php && php -l tests/TestCase.php && vendor/bin/pest`
Expected: no syntax errors; full suite PASS (no regressions, including `tests/Smoke/ConfigSerializableTest.php`).

- [ ] **Step 11: Commit**

```bash
git add resources/dist/feedback-board.css resources/views/partials/assets.blade.php src/Support/Ui.php src/Http/Controllers/AssetController.php routes/web.php config/feedback-board.php tests/TestCase.php tests/Unit/UiTest.php tests/Unit/StylesheetTest.php tests/Feature/AssetsTest.php
git commit -m "feat(ui): serve a self-contained stylesheet and inject it with Livewire @assets"
```

### Task 2: Enum labels/colors, activity summaries and translations

**Files:**
- Modify: `src/Enums/FeedbackPriority.php`, `src/Enums/ActivityAction.php`, `src/Enums/AuthorRole.php`
- Modify: `src/Models/FeedbackActivity.php` (new `summary()`)
- Modify: `resources/lang/en/feedback-board.php`, `resources/lang/ar/feedback-board.php`
- Test: `tests/Unit/EnumsTest.php`, `tests/Feature/LocalizationTest.php`, `tests/Unit/DataLayerTest.php`

**Interfaces:**
- Produces: `FeedbackPriority::color(): string` (`low`→`gray`, `medium`→`info`, `high`→`warning`, `urgent`→`danger`); `ActivityAction::label(): string` (key `activity.<value>`); `AuthorRole::label(): string` (key `role.<value>`); `FeedbackActivity::summary(): string` (status changes read "Status changed: In progress"; an assignment to nobody reads "Unassigned"; everything else is the action label); the translation keys listed in Step 3, used verbatim by Tasks 3–6.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/EnumsTest.php` (and change its first `use` line to `use YousefAman\FeedbackBoard\Enums\{ActivityAction, AuthorRole, FeedbackPriority, FeedbackStatus};`):

```php
it('maps priorities to badge colors', function () {
    expect(FeedbackPriority::Low->color())->toBe('gray')
        ->and(FeedbackPriority::Medium->color())->toBe('info')
        ->and(FeedbackPriority::High->color())->toBe('warning')
        ->and(FeedbackPriority::Urgent->color())->toBe('danger');
});

it('labels activity actions and author roles in English and Arabic', function () {
    expect(ActivityAction::StatusChanged->label())->toBe('Status changed')
        ->and(AuthorRole::Developer->label())->toBe('Support team');

    app()->setLocale('ar');

    expect(ActivityAction::StatusChanged->label())->toBe('تغيّرت الحالة')
        ->and(AuthorRole::Reporter->label())->toBe('المُبلِّغ');
});

it('translates every activity action and author role', function () {
    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);

        foreach ([...ActivityAction::cases(), ...AuthorRole::cases()] as $case) {
            expect($case->label())->not->toStartWith('feedback-board::');
        }
    }
});
```

Append to `tests/Feature/LocalizationTest.php`:

```php
it('defines the same translation keys in English and Arabic', function () {
    $flatten = function (array $lines, string $prefix = '') use (&$flatten): array {
        $keys = [];

        foreach ($lines as $key => $value) {
            $keys = is_array($value)
                ? [...$keys, ...$flatten($value, $prefix.$key.'.')]
                : [...$keys, $prefix.$key];
        }

        return $keys;
    };

    $en = $flatten(require __DIR__.'/../../resources/lang/en/feedback-board.php');
    $ar = $flatten(require __DIR__.'/../../resources/lang/ar/feedback-board.php');
    sort($en);
    sort($ar);

    expect($ar)->toBe($en)
        ->and($en)->toContain('close', 'form.attachments_hint', 'board.empty_column', 'role.ai', 'activity.assigned');
});
```

In `tests/Unit/DataLayerTest.php`, change the two grouped imports to `use YousefAman\FeedbackBoard\Models\{FeedbackActivity, FeedbackItem, FeedbackComment};` and `use YousefAman\FeedbackBoard\Enums\{ActivityAction, FeedbackStatus, FeedbackType, AuthorRole};`, then append:

```php
it('summarises activity entries for the item timeline', function () {
    $item = FeedbackItem::factory()->create();
    $make = fn (array $attributes) => FeedbackActivity::create(['feedback_item_id' => $item->id, ...$attributes]);

    expect($make(['action' => ActivityAction::StatusChanged, 'from_status' => FeedbackStatus::New, 'to_status' => FeedbackStatus::InProgress])->summary())
        ->toBe('Status changed: In progress')
        ->and($make(['action' => ActivityAction::Assigned, 'properties' => ['assignee_id' => 7]])->summary())->toBe('Assigned')
        ->and($make(['action' => ActivityAction::Assigned, 'properties' => ['assignee_id' => null]])->summary())->toBe('Unassigned')
        ->and($make(['action' => ActivityAction::Assigned])->summary())->toBe('Assigned')
        ->and($make(['action' => ActivityAction::Replied])->summary())->toBe('Replied');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/EnumsTest.php tests/Feature/LocalizationTest.php`
Also run `vendor/bin/pest tests/Unit/DataLayerTest.php`.
Expected: FAIL — `Call to undefined method …FeedbackPriority::color()`, `…ActivityAction::label()`, `…FeedbackActivity::summary()`, and the parity test fails on the missing `close` key (the parity assertion itself passes today; the `toContain` part fails).

- [ ] **Step 3: Add the translations**

`resources/lang/en/feedback-board.php` — make these edits (keep every existing key and value):
1. After `'dir' => 'ltr',` add `'close' => 'Close',`.
2. Replace the `'form' => [...]` line with:

```php
    'form' => [
        'title' => 'Title', 'description' => 'Describe the issue', 'submit' => 'Send',
        'type' => 'Type', 'attachments' => 'Attachments',
        'attachments_hint' => 'Allowed: :types · up to :size MB each',
        'uploading' => 'Uploading…',
        'sent_title' => 'Thanks — your report was sent',
        'sent_body' => "We'll keep you posted on its progress.",
        'view_reports' => 'View my reports', 'send_another' => 'Send another report',
    ],
```

3. In `'board' => [...]`, after `'assign_me' => 'Assign to me', 'unassign' => 'Unassign',` add:

```php
        'new_report' => 'New report', 'empty_reports' => "You haven't sent any reports yet.",
        'empty_column' => 'No items', 'status' => 'Status', 'priority' => 'Priority',
        'no_priority' => 'No priority', 'assignee' => 'Assignee', 'unassigned' => 'Unassigned',
        'context' => 'Context', 'page_url' => 'Page', 'browser' => 'Browser', 'locale' => 'Language',
        'internal' => 'Internal',
```

4. After the `'board'` array, add:

```php
    'role' => ['reporter' => 'Reporter', 'developer' => 'Support team', 'ai' => 'AI assistant'],
    'activity' => ['created' => 'Created', 'status_changed' => 'Status changed', 'replied' => 'Replied', 'assigned' => 'Assigned', 'unassigned' => 'Unassigned'],
```

`resources/lang/ar/feedback-board.php` — the same structure:
1. After `'dir' => 'rtl',` add `'close' => 'إغلاق',`.
2. Replace the `'form' => [...]` line with:

```php
    'form' => [
        'title' => 'العنوان', 'description' => 'صف المشكلة', 'submit' => 'إرسال',
        'type' => 'النوع', 'attachments' => 'المرفقات',
        'attachments_hint' => 'المسموح: :types · حتى :size ميغابايت للملف',
        'uploading' => 'جارٍ الرفع…',
        'sent_title' => 'شكرًا لك — تم إرسال بلاغك',
        'sent_body' => 'سنوافيك بتطورات بلاغك.',
        'view_reports' => 'عرض بلاغاتي', 'send_another' => 'إرسال بلاغ آخر',
    ],
```

3. In `'board' => [...]`, after `'assign_me' => 'إسناد لي', 'unassign' => 'إلغاء الإسناد',` add:

```php
        'new_report' => 'بلاغ جديد', 'empty_reports' => 'لم ترسل أي بلاغات بعد.',
        'empty_column' => 'لا توجد عناصر', 'status' => 'الحالة', 'priority' => 'الأولوية',
        'no_priority' => 'بلا أولوية', 'assignee' => 'المسؤول', 'unassigned' => 'غير مُسنَد',
        'context' => 'السياق', 'page_url' => 'الصفحة', 'browser' => 'المتصفح', 'locale' => 'اللغة',
        'internal' => 'داخلي',
```

4. After the `'board'` array, add:

```php
    'role' => ['reporter' => 'المُبلِّغ', 'developer' => 'فريق الدعم', 'ai' => 'المساعد الذكي'],
    'activity' => ['created' => 'أُنشئ', 'status_changed' => 'تغيّرت الحالة', 'replied' => 'رُدّ عليه', 'assigned' => 'أُسند', 'unassigned' => 'أُلغي الإسناد'],
```

- [ ] **Step 4: Add the enum methods**

`src/Enums/FeedbackPriority.php` — after `label()`:

```php
    public function color(): string
    {
        return match ($this) {
            self::Low => 'gray',
            self::Medium => 'info',
            self::High => 'warning',
            self::Urgent => 'danger',
        };
    }
```

`src/Enums/ActivityAction.php` — inside the enum, after the cases:

```php

    public function label(): string
    {
        return __('feedback-board::feedback-board.activity.'.$this->value);
    }
```

`src/Enums/AuthorRole.php` — inside the enum, after the cases:

```php

    public function label(): string
    {
        return __('feedback-board::feedback-board.role.'.$this->value);
    }
```

`src/Models/FeedbackActivity.php` — add after the relation methods (before `booted()`):

```php
    /** One-line text for the item timeline. */
    public function summary(): string
    {
        $properties = (array) $this->properties;

        return match (true) {
            $this->action === ActivityAction::StatusChanged && $this->to_status !== null
                => $this->action->label().': '.$this->to_status->label(),
            // AssignFeedback records an unassignment as Assigned with assignee_id = null.
            $this->action === ActivityAction::Assigned
                && array_key_exists('assignee_id', $properties) && $properties['assignee_id'] === null
                => __('feedback-board::feedback-board.activity.unassigned'),
            default => $this->action->label(),
        };
    }
```

(`ActivityAction` is already imported in this model.)

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/EnumsTest.php tests/Feature/LocalizationTest.php tests/Unit/DataLayerTest.php`
Expected: PASS.

- [ ] **Step 6: Full suite and lint**

Run: `php -l src/Enums/FeedbackPriority.php && php -l src/Enums/ActivityAction.php && php -l src/Enums/AuthorRole.php && php -l src/Models/FeedbackActivity.php && php -l resources/lang/en/feedback-board.php && php -l resources/lang/ar/feedback-board.php && vendor/bin/pest`
Expected: no syntax errors; full suite PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Enums/FeedbackPriority.php src/Enums/ActivityAction.php src/Enums/AuthorRole.php src/Models/FeedbackActivity.php resources/lang/en/feedback-board.php resources/lang/ar/feedback-board.php tests/Unit/EnumsTest.php tests/Feature/LocalizationTest.php tests/Unit/DataLayerTest.php
git commit -m "feat(ui): add priority colors, activity summaries, role labels and UI translations"
```

### Task 3: Launcher, modal and submission form

**Files:**
- Modify: `resources/views/components/launcher.blade.php` (full rewrite)
- Modify: `resources/views/livewire/feedback-form.blade.php` (full rewrite)
- Modify: `src/Livewire/FeedbackForm.php`
- Modify: `src/Services/AttachmentStorage.php` (new `limits()`)
- Modify: `resources/dist/feedback-board.css` (append the Task 3 section)
- Test: `tests/Feature/LivewireRegistrationTest.php`, `tests/Feature/FeedbackFormTest.php`

**Interfaces:**
- Consumes: Task 1 root attribute contract, `Ui::theme()`, assets partial, foundation classes; Task 2 keys `close`, `form.type`, `form.attachments`, `form.attachments_hint`, `form.uploading`, `form.sent_title`, `form.sent_body`, `form.view_reports`, `form.send_another`.
- Produces: `FeedbackForm::$sent` (public bool; reset when the launcher is reopened via the browser event `fb-launcher-opened`); `AttachmentStorage::limits(): array{types: string, size: string}` (size = the smaller of `attachments.max_kb` and PHP's `upload_max_filesize`), `AttachmentStorage::iniSizeToKb(string): ?int`, `AttachmentStorage::megabytes(int $kb): string`; classes `fb-launcher`, `fb-launcher__icon`, `fb-launcher__label`, `fb-modal`, `fb-modal__panel`, `fb-modal__header`, `fb-modal__title`, `fb-modal__body`, `fb-form`, `fb-form__fields`, `fb-form__actions`, `fb-form__success`, `fb-form__success-icon`, `fb-form__success-title`, `fb-form__success-body`, `fb-form__success-actions`. Element ids `fb-launcher-title` (one launcher per page).

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/LivewireRegistrationTest.php`, add `use Illuminate\Support\Facades\Route;` and `use YousefAman\FeedbackBoard\Support\Ui;` to the imports, then append:

```php
it('adds the stylesheet to the head of a host page that only renders the launcher', function () {
    $this->actingAs(TestUser::create(['name' => 'Client', 'email' => 'c@x.com']));
    Route::get('/fb-host-page', fn () => Blade::render(
        '<!doctype html><html><head><title>Host</title></head><body><main>App</main><x-feedback-board::launcher /></body></html>'
    ));

    $html = $this->get('/fb-host-page')->assertOk()->getContent();
    $link = '<link rel="stylesheet" href="'.Ui::stylesheetUrl().'"';

    expect(substr_count($html, $link))->toBe(1)
        ->and(strpos($html, $link))->toBeLessThan(strpos($html, '</head>'));
});

it('renders the launcher as a themed, direction-aware dialog trigger', function () {
    $this->actingAs(TestUser::create(['name' => 'Client', 'email' => 'c@x.com']));
    config()->set('feedback-board.ui.theme', 'dark');
    app()->setLocale('ar');

    $html = Blade::render('<x-feedback-board::launcher />');

    expect($html)->toContain('class="fb-scope"')
        ->and($html)->toContain('data-fb-theme="dark"')
        ->and($html)->toContain('dir="rtl"')
        ->and($html)->toContain('aria-haspopup="dialog"')
        ->and($html)->toContain('role="dialog"')
        ->and($html)->toContain('aria-labelledby="fb-launcher-title"')
        ->and($html)->toContain('id="fb-launcher-title"')
        ->and($html)->toContain('x-on:keydown.escape.window="open = false"')
        ->and($html)->toContain('x-trap.noscroll="open"')
        ->and($html)->not->toContain('x-transition')
        ->and($html)->toContain("\$dispatch('fb-launcher-opened')")
        ->and($html)->toContain("x-on:fb-launcher-opened.window=\"\$wire.sent && \$wire.set('sent', false)\"")
        ->and($html)->toContain('wire:model="title" maxlength="255" dir="auto"')
        ->and($html)->toContain('aria-label="إغلاق"');
});
```

In `tests/Feature/FeedbackFormTest.php`, append:

```php
it('confirms a successful submission and can start a new report', function () {
    Storage::fake('local');
    $user = TestUser::create(['name' => 'Lena', 'email' => 'l@x.com']);

    $component = Livewire::actingAs($user)->test(FeedbackForm::class)
        ->assertSet('sent', false)
        ->set('title', 'Broken link')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('sent', true)
        ->assertSee(__('feedback-board::feedback-board.form.sent_title'))
        ->assertSee(__('feedback-board::feedback-board.form.send_another'))
        ->assertSeeHtml('href="'.route('feedback-board.page').'"')
        ->assertDontSeeHtml('wire:submit="submit"');

    $component->set('sent', false)
        ->assertSeeHtml('wire:submit="submit"')
        ->assertDontSee(__('feedback-board::feedback-board.form.sent_title'));
});

it('stays on the form when validation fails', function () {
    $user = TestUser::create(['name' => 'L', 'email' => 'l@x.com']);

    Livewire::actingAs($user)->test(FeedbackForm::class)
        ->set('sent', true)
        ->set('title', '')
        ->call('submit')
        ->assertHasErrors('title')
        ->assertSet('sent', false)
        ->assertSeeHtml('class="fb-error"');
});

it('labels every field and describes the attachment limits from config', function () {
    config()->set('feedback-board.attachments.allowed_mimes', ['png', 'pdf']);
    config()->set('feedback-board.attachments.max_kb', 512); // below any realistic upload_max_filesize
    $user = TestUser::create(['name' => 'L', 'email' => 'l@x.com']);

    Livewire::actingAs($user)->test(FeedbackForm::class)
        ->assertSee(__('feedback-board::feedback-board.form.title'))
        ->assertSee(__('feedback-board::feedback-board.form.type'))
        ->assertSee(__('feedback-board::feedback-board.form.attachments'))
        ->assertSee('Allowed: PNG, PDF · up to 0.5 MB each')
        ->assertSeeHtml('class="fb-scope fb-form"')
        ->assertSeeHtml('data-fb-theme="class"');
});

it('shows attachment validation errors', function () {
    Storage::fake('local');
    $user = TestUser::create(['name' => 'L', 'email' => 'l@x.com']);

    Livewire::actingAs($user)->test(FeedbackForm::class)
        ->set('title', 'With a bad file')
        ->set('files', [UploadedFile::fake()->create('virus.exe', 10)])
        ->call('submit')
        ->assertHasErrors('files.0')
        ->assertSeeHtml('class="fb-error"');
});

it('formats a size limit in megabytes without a trailing zero', function (int $kb, string $size) {
    expect(\YousefAman\FeedbackBoard\Services\AttachmentStorage::megabytes($kb))->toBe($size);
})->with([[10240, '10'], [512, '0.5'], [1536, '1.5'], [1000, '1'], [102400, '100']]);

it('reads PHP ini size values in kilobytes', function (string $value, ?int $kb) {
    expect(\YousefAman\FeedbackBoard\Services\AttachmentStorage::iniSizeToKb($value))->toBe($kb);
})->with([['2M', 2048], ['512K', 512], ['1G', 1048576], ['2097152', 2048], ['8m', 8192], ['0', null], ['', null], ['abc', null]]);

it('never promises more than PHP accepts', function () {
    config()->set('feedback-board.attachments.max_kb', 10_000_000);
    $storage = app(\YousefAman\FeedbackBoard\Services\AttachmentStorage::class);
    $php = \YousefAman\FeedbackBoard\Services\AttachmentStorage::iniSizeToKb((string) ini_get('upload_max_filesize'));

    expect($storage->limits()['size'])->toBe(\YousefAman\FeedbackBoard\Services\AttachmentStorage::megabytes($php ?? 10_000_000));
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/LivewireRegistrationTest.php tests/Feature/FeedbackFormTest.php`
Expected: FAIL — the launcher test fails on `class="fb-scope"`; "confirms a successful submission" fails at `assertSet('sent', true)` (`Failed asserting that null matches expected true` — the absent property reads as null), "stays on the form" fails with `Property [$sent] not found`, the label/hint tests fail on missing markup, and the size tests on the undefined `AttachmentStorage::megabytes()` / `iniSizeToKb()`. The host-page test fails because the launcher does not include the assets partial yet.

- [ ] **Step 3: Add `AttachmentStorage::limits()`**

In `src/Services/AttachmentStorage.php`, after `allowedRules()`:

```php
    /**
     * Human-readable upload limits for the form hint. The size never exceeds PHP's own
     * upload_max_filesize, which rejects larger files before validation runs.
     *
     * @return array{types: string, size: string}
     */
    public function limits(): array
    {
        $mimes = (array) config('feedback-board.attachments.allowed_mimes', []);
        $kb = (int) config('feedback-board.attachments.max_kb', 10240);
        $php = self::iniSizeToKb((string) ini_get('upload_max_filesize'));

        return [
            'types' => implode(', ', array_map(fn ($mime) => strtoupper((string) $mime), $mimes)),
            'size' => self::megabytes($php === null ? $kb : min($kb, $php)),
        ];
    }

    /** "2M" → 2048, "512K" → 512, "1G" → 1048576, bytes → KB; null when unparsable or not positive. */
    public static function iniSizeToKb(string $value): ?int
    {
        if (! preg_match('/^\s*(\d+)\s*([kmg]?)\s*$/i', $value, $m)) {
            return null;
        }

        $kb = match (strtolower($m[2])) {
            'g' => (int) $m[1] * 1024 * 1024,
            'm' => (int) $m[1] * 1024,
            'k' => (int) $m[1],
            default => intdiv((int) $m[1], 1024),
        };

        return $kb > 0 ? $kb : null;
    }

    /** 10240 → "10", 512 → "0.5", 1536 → "1.5". */
    public static function megabytes(int $kb): string
    {
        $mb = number_format($kb / 1024, 1, '.', '');

        return str_ends_with($mb, '.0') ? substr($mb, 0, -2) : $mb;
    }
```

- [ ] **Step 4: Update `FeedbackForm`**

In `src/Livewire/FeedbackForm.php`:
1. Add the property after `public array $files = [];`:

```php
    public bool $sent = false;
```

2. In `submit()`, insert `$this->sent = false;` on the line directly after `$this->authorize('create', FeedbackItem::class);`, and replace the two final lines

```php
        $this->reset('title', 'description', 'files');
        $this->dispatch('feedback-submitted', id: $item->id);
```

with

```php
        $this->reset('title', 'description', 'files');
        $this->sent = true;
        $this->dispatch('feedback-submitted', id: $item->id);
```

3. Replace `render()` with:

```php
    public function render(): \Illuminate\Contracts\View\View
    {
        $limits = app(AttachmentStorage::class)->limits();

        return view('feedback-board::livewire.feedback-form', [
            'attachmentTypes' => $limits['types'],
            'attachmentSize' => $limits['size'],
        ]);
    }
```

- [ ] **Step 5: Rewrite the launcher view**

`resources/views/components/launcher.blade.php` — exactly:

```blade
<div class="fb-scope" data-fb-theme="{{ \YousefAman\FeedbackBoard\Support\Ui::theme() }}" dir="{{ __('feedback-board::feedback-board.dir') }}"
     x-data="{ open: false }" x-on:keydown.escape.window="open = false">
    @include('feedback-board::partials.assets')
    <button type="button" class="fb-launcher" x-on:click="open = true; $dispatch('fb-launcher-opened')" aria-haspopup="dialog" x-bind:aria-expanded="open">
        <svg class="fb-launcher__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        <span class="fb-launcher__label">{{ __('feedback-board::feedback-board.launcher') }}</span>
    </button>
    <div class="fb-modal" x-show="open" x-cloak x-on:click.self="open = false">
        <div class="fb-modal__panel" role="dialog" aria-modal="true" aria-labelledby="fb-launcher-title" x-trap.noscroll="open">
            <div class="fb-modal__header">
                <h2 id="fb-launcher-title" class="fb-modal__title">{{ __('feedback-board::feedback-board.launcher') }}</h2>
                <button type="button" class="fb-icon-btn" x-on:click="open = false" aria-label="{{ __('feedback-board::feedback-board.close') }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="fb-modal__body">
                @livewire('feedback-board::feedback-form')
            </div>
        </div>
    </div>
</div>
```

- [ ] **Step 6: Rewrite the form view**

`resources/views/livewire/feedback-form.blade.php` — exactly:

```blade
<div class="fb-scope fb-form" data-fb-theme="{{ \YousefAman\FeedbackBoard\Support\Ui::theme() }}" dir="{{ __('feedback-board::feedback-board.dir') }}"
     x-data x-on:fb-launcher-opened.window="$wire.sent && $wire.set('sent', false)">
    @include('feedback-board::partials.assets')
    @if ($sent)
        <div class="fb-form__success" role="status">
            <svg class="fb-form__success-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m8 12 3 3 5-6"/></svg>
            <p class="fb-form__success-title">{{ __('feedback-board::feedback-board.form.sent_title') }}</p>
            <p class="fb-form__success-body">{{ __('feedback-board::feedback-board.form.sent_body') }}</p>
            <div class="fb-form__success-actions">
                <a class="fb-btn" href="{{ route('feedback-board.page') }}">{{ __('feedback-board::feedback-board.form.view_reports') }}</a>
                <button type="button" class="fb-btn fb-btn--primary" wire:click="$set('sent', false)">{{ __('feedback-board::feedback-board.form.send_another') }}</button>
            </div>
        </div>
    @else
        <form wire:submit="submit" class="fb-form__fields">
            <label class="fb-field">
                <span class="fb-label">{{ __('feedback-board::feedback-board.form.title') }}</span>
                <input type="text" class="fb-input" wire:model="title" maxlength="255" dir="auto" />
                @error('title') <span class="fb-error">{{ $message }}</span> @enderror
            </label>

            <label class="fb-field">
                <span class="fb-label">{{ __('feedback-board::feedback-board.form.type') }}</span>
                <select class="fb-select" wire:model="type">
                    <option value="bug">{{ __('feedback-board::feedback-board.type.bug') }}</option>
                    <option value="feature">{{ __('feedback-board::feedback-board.type.feature') }}</option>
                    <option value="question">{{ __('feedback-board::feedback-board.type.question') }}</option>
                    <option value="other">{{ __('feedback-board::feedback-board.type.other') }}</option>
                </select>
                @error('type') <span class="fb-error">{{ $message }}</span> @enderror
            </label>

            <label class="fb-field">
                <span class="fb-label">{{ __('feedback-board::feedback-board.form.description') }}</span>
                <textarea class="fb-textarea" wire:model="description" rows="5" dir="auto"></textarea>
                @error('description') <span class="fb-error">{{ $message }}</span> @enderror
            </label>

            <label class="fb-field">
                <span class="fb-label">{{ __('feedback-board::feedback-board.form.attachments') }}</span>
                <input type="file" class="fb-file" wire:model="files" multiple />
                <span class="fb-hint">{{ __('feedback-board::feedback-board.form.attachments_hint', ['types' => $attachmentTypes, 'size' => $attachmentSize]) }}</span>
                <span class="fb-hint" wire:loading wire:target="files">{{ __('feedback-board::feedback-board.form.uploading') }}</span>
                @if ($errors->has('files') || $errors->has('files.*'))
                    <span class="fb-error">{{ $errors->first('files') ?: $errors->first('files.*') }}</span>
                @endif
            </label>

            <div class="fb-form__actions">
                <button type="submit" class="fb-btn fb-btn--primary" wire:loading.attr="disabled" wire:target="submit,files">{{ __('feedback-board::feedback-board.form.submit') }}</button>
            </div>
        </form>
    @endif
</div>
```

- [ ] **Step 7: Append the Task 3 CSS**

Append to the end of `resources/dist/feedback-board.css` (one blank line before it):

```css
/* ---------- Launcher and modal ---------- */

.fb-launcher {
    position: fixed;
    inset-block-end: calc(1.25rem + env(safe-area-inset-bottom, 0px));
    inset-inline-end: 1.25rem;
    z-index: var(--_fb-z);
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.125rem;
    border: 0;
    border-radius: 999px;
    background: var(--_fb-accent);
    color: var(--_fb-accent-contrast);
    font-size: 0.875rem;
    font-weight: 600;
    line-height: 1.25;
    box-shadow: var(--_fb-shadow-lg);
    cursor: pointer;
    transition: background-color 0.15s ease, transform 0.15s ease;
}

.fb-launcher:hover {
    background: var(--_fb-accent-hover);
    transform: translateY(-1px);
}

.fb-launcher__icon {
    width: 1.25rem;
    height: 1.25rem;
}

.fb-modal {
    position: fixed;
    inset: 0;
    z-index: calc(var(--_fb-z) + 10);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    background: var(--_fb-backdrop);
    animation: fb-fade-in 0.15s ease-out;
}

/* CSS (not x-transition, whose inline styles reduced motion cannot override) restarts on every x-show. */
@keyframes fb-fade-in {
    from {
        opacity: 0;
    }
}

.fb-modal__panel {
    display: flex;
    flex-direction: column;
    inline-size: min(32rem, 100%);
    max-block-size: calc(100vh - 2rem);
    overflow: auto;
    overscroll-behavior: contain;
    border-radius: var(--_fb-radius);
    background: var(--_fb-surface);
    color: var(--_fb-text);
    box-shadow: var(--_fb-shadow-lg);
}

.fb-modal__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding-block: 0.875rem;
    padding-inline: 1.25rem 0.75rem;
    border-block-end: 1px solid var(--_fb-border);
}

.fb-modal__title {
    font-size: 1rem;
    font-weight: 600;
}

.fb-modal__body {
    padding: 1.25rem;
}

@media (max-width: 40rem) {
    .fb-launcher {
        inset-block-end: calc(1rem + env(safe-area-inset-bottom, 0px));
        inset-inline-end: 1rem;
        justify-content: center;
        inline-size: 3.25rem;
        block-size: 3.25rem;
        padding: 0;
    }

    .fb-launcher__label {
        position: absolute;
        width: 1px;
        height: 1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
    }

    .fb-modal {
        align-items: flex-end;
        padding: 0;
    }

    .fb-modal__panel {
        inline-size: 100%;
        max-block-size: 92vh;
        border-end-start-radius: 0;
        border-end-end-radius: 0;
    }
}

/* ---------- Submission form ---------- */

.fb-form,
.fb-form__fields {
    display: grid;
    gap: 1rem;
}

.fb-form__actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
}

.fb-form__success {
    display: grid;
    justify-items: center;
    gap: 0.5rem;
    padding: 1.5rem 0.5rem;
    text-align: center;
}

.fb-form__success-icon {
    width: 2.5rem;
    height: 2.5rem;
    color: var(--_fb-success-fg);
}

.fb-form__success-title {
    font-size: 1rem;
    font-weight: 600;
}

.fb-form__success-body {
    color: var(--_fb-text-muted);
}

.fb-form__success-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 0.5rem;
    margin-block-start: 0.5rem;
}
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/LivewireRegistrationTest.php tests/Feature/FeedbackFormTest.php tests/Unit/StylesheetTest.php`
Expected: PASS (the stylesheet guards stay green with the new section).

- [ ] **Step 9: Full suite and lint**

Run: `php -l src/Livewire/FeedbackForm.php && php -l src/Services/AttachmentStorage.php && vendor/bin/pest`
Expected: no syntax errors; full suite PASS.

- [ ] **Step 10: Commit**

```bash
git add resources/views/components/launcher.blade.php resources/views/livewire/feedback-form.blade.php src/Livewire/FeedbackForm.php src/Services/AttachmentStorage.php resources/dist/feedback-board.css tests/Feature/LivewireRegistrationTest.php tests/Feature/FeedbackFormTest.php
git commit -m "feat(ui): style the launcher, modal and submission form with a sent confirmation"
```

### Task 4: "My reports", the reporter item view and the drawer

**Files:**
- Modify: `resources/views/livewire/my-reports-board.blade.php` (full rewrite)
- Modify: `resources/views/livewire/feedback-item-modal.blade.php` (full rewrite)
- Modify: `src/Livewire/MyReportsBoard.php` (new `close()`)
- Modify: `resources/dist/feedback-board.css` (append the Task 4 section)
- Test: `tests/Feature/MyReportsTest.php`

**Interfaces:**
- Consumes: Task 1 contract and foundation classes; Task 2 `AuthorRole::label()`, keys `close`, `board.new_report`, `board.empty_reports`, `board.internal`, `board.thread`, `board.reply`.
- Produces: `MyReportsBoard::close(): void`; the drawer markup pattern (`fb-drawer`, `fb-drawer__backdrop`, `fb-drawer__panel`, `fb-drawer__close`, `aria-labelledby="fb-drawer-title"`) that Task 5 repeats for the Kanban; shared item classes `fb-item`, `fb-detail`, `fb-detail__header`, `fb-detail__title` (carries `id="fb-drawer-title"`), `fb-detail__meta`, `fb-thread`, `fb-comment`, `fb-comment--reporter|developer|ai|internal`, `fb-comment__head`, `fb-comment__author`, `fb-comment__time`, `fb-composer`, `fb-composer__panel`, `fb-composer__panel--internal`, `fb-composer__actions`; list classes `fb-reports`, `fb-reports__title`, `fb-reports__layout`, `fb-list`, `fb-list__item`, `fb-list__title`, `fb-list__time`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/MyReportsTest.php` (add `use YousefAman\FeedbackBoard\Models\FeedbackComment;` to the imports):

```php
it('closes the report drawer', function () {
    $me = TestUser::create(['name' => 'Me', 'email' => 'me@x.com']);
    $item = FeedbackItem::factory()->for($me, 'reporter')->create(['title' => 'Mine']);

    Livewire::actingAs($me)->test(MyReportsBoard::class)
        ->call('open', $item->id)
        ->assertSeeHtml('class="fb-drawer"')
        ->assertSeeHtml('aria-labelledby="fb-drawer-title"')
        ->assertSeeHtml('x-on:keydown.escape.window="$wire.close()"')
        ->assertSeeHtml('class="fb-drawer__body"')
        ->call('close')
        ->assertSet('selectedId', null)
        ->assertDontSeeHtml('class="fb-drawer"')
        ->assertDontSeeLivewire('feedback-board::feedback-item-modal');
});

it('renders reports as keyboard-reachable buttons with a status badge', function () {
    $me = TestUser::create(['name' => 'Me', 'email' => 'me@x.com']);
    FeedbackItem::factory()->for($me, 'reporter')->create(['title' => 'Mine']);

    Livewire::actingAs($me)->test(MyReportsBoard::class)
        ->assertSeeHtml('class="fb-scope fb-reports"')
        ->assertSeeHtml('<button type="button" class="fb-list__item"')
        ->assertSeeHtml('<span class="fb-list__title" dir="auto">Mine</span>')
        ->assertSeeHtml('fb-badge fb-badge--gray')
        ->assertDontSeeHtml('style="cursor:pointer"');
});

it('shows an empty state when the reporter has no reports', function () {
    $me = TestUser::create(['name' => 'Me', 'email' => 'me@x.com']);

    Livewire::actingAs($me)->test(MyReportsBoard::class)
        ->assertSee(__('feedback-board::feedback-board.board.empty_reports'));
});

it('labels thread comments by role for the reporter and hides internal notes', function () {
    $me = TestUser::create(['name' => 'Me', 'email' => 'me@x.com']);
    $item = FeedbackItem::factory()->for($me, 'reporter')->create(['description' => "line one\nline two"]);
    FeedbackComment::factory()->for($item, 'item')->create(['body' => 'We fixed it', 'author_role' => AuthorRole::Developer, 'is_internal' => false]);
    FeedbackComment::factory()->for($item, 'item')->create(['body' => 'secret root cause', 'author_role' => AuthorRole::Developer, 'is_internal' => true]);

    Livewire::actingAs($me)->test(FeedbackItemModal::class, ['item' => $item])
        ->assertSeeHtml('class="fb-scope fb-item"')
        ->assertSeeHtml('id="fb-drawer-title"')
        ->assertSeeHtml('<p class="fb-prose" dir="auto">')
        ->assertSee('We fixed it')
        ->assertSee(AuthorRole::Developer->label())
        ->assertSeeHtml('fb-comment fb-comment--developer')
        ->assertDontSee('secret root cause')
        ->assertDontSee(__('feedback-board::feedback-board.board.internal'));
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/MyReportsTest.php`
Expected: FAIL — on missing markup (`class="fb-drawer"` is asserted before `close` is called, so the missing method itself is never reached), `fb-reports`, `fb-list__item`, `fb-item` and the empty-state text.

- [ ] **Step 3: Add `close()`**

In `src/Livewire/MyReportsBoard.php`, after `open()`:

```php
    public function close(): void { $this->selectedId = null; }
```

- [ ] **Step 4: Rewrite the views**

`resources/views/livewire/my-reports-board.blade.php` — exactly:

```blade
<div class="fb-scope fb-reports" data-fb-theme="{{ \YousefAman\FeedbackBoard\Support\Ui::theme() }}" dir="{{ __('feedback-board::feedback-board.dir') }}">
    @include('feedback-board::partials.assets')
    <h2 class="fb-reports__title">{{ __('feedback-board::feedback-board.board.my_reports') }}</h2>

    <div class="fb-reports__layout">
        <section class="fb-panel">
            <h3 class="fb-panel__title">{{ __('feedback-board::feedback-board.board.new_report') }}</h3>
            @livewire('feedback-board::feedback-form')
        </section>

        <section>
            @if ($items->isEmpty())
                <p class="fb-empty">{{ __('feedback-board::feedback-board.board.empty_reports') }}</p>
            @else
                <ul class="fb-list">
                    @foreach ($items as $item)
                        <li wire:key="item-{{ $item->id }}">
                            <button type="button" class="fb-list__item" wire:click="open({{ $item->id }})">
                                <span class="fb-list__title" dir="auto">{{ $item->title }}</span>
                                <span class="fb-badge fb-badge--{{ $item->status->color() }}">{{ $item->status->label() }}</span>
                                @if ($item->created_at)
                                    <time class="fb-list__time" datetime="{{ $item->created_at->toIso8601String() }}">{{ $item->created_at->diffForHumans() }}</time>
                                @endif
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    @if ($selected)
        <div class="fb-drawer" x-data x-on:keydown.escape.window="$wire.close()">
            <div class="fb-drawer__backdrop" wire:click="close"></div>
            <div class="fb-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="fb-drawer-title" x-trap.noscroll="true">
                <div class="fb-drawer__bar">
                    <button type="button" class="fb-icon-btn" wire:click="close" aria-label="{{ __('feedback-board::feedback-board.close') }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="fb-drawer__body">
                    @livewire('feedback-board::feedback-item-modal', ['item' => $selected], key('modal-'.$selected->id))
                </div>
            </div>
        </div>
    @endif
</div>
```

`resources/views/livewire/feedback-item-modal.blade.php` — exactly:

```blade
<div class="fb-scope fb-item" data-fb-theme="{{ \YousefAman\FeedbackBoard\Support\Ui::theme() }}" dir="{{ __('feedback-board::feedback-board.dir') }}">
    @include('feedback-board::partials.assets')
    <header class="fb-detail__header">
        <h2 id="fb-drawer-title" class="fb-detail__title" dir="auto">{{ $item->title }}</h2>
        <div class="fb-detail__meta">
            <span class="fb-badge fb-badge--{{ $item->status->color() }}">{{ $item->status->label() }}</span>
            @if ($item->created_at)
                <time datetime="{{ $item->created_at->toIso8601String() }}">{{ $item->created_at->diffForHumans() }}</time>
            @endif
        </div>
    </header>

    @if (filled($item->description))
        <p class="fb-prose" dir="auto">{{ $item->description }}</p>
    @endif

    @if ($comments->isNotEmpty())
        <section class="fb-section">
            <h3 class="fb-section-title">{{ __('feedback-board::feedback-board.board.thread') }}</h3>
            <div class="fb-thread">
                @foreach ($comments as $c)
                    <article wire:key="c-{{ $c->id }}" @class(['fb-comment', 'fb-comment--'.$c->author_role->value, 'fb-comment--internal' => $c->is_internal])>
                        <div class="fb-comment__head">
                            <span class="fb-comment__author">{{ $c->author_role->label() }}</span>
                            @if ($c->is_internal)
                                <span class="fb-badge fb-badge--warning">{{ __('feedback-board::feedback-board.board.internal') }}</span>
                            @endif
                            @if ($c->created_at)
                                <time class="fb-comment__time" datetime="{{ $c->created_at->toIso8601String() }}">{{ $c->created_at->diffForHumans() }}</time>
                            @endif
                        </div>
                        <p class="fb-prose" dir="auto">{{ $c->body }}</p>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    <form wire:submit="reply" class="fb-composer__panel">
        <label class="fb-field">
            <span class="fb-sr-only">{{ __('feedback-board::feedback-board.board.reply') }}</span>
            <textarea class="fb-textarea" wire:model="body" rows="3" dir="auto" placeholder="{{ __('feedback-board::feedback-board.board.reply') }}"></textarea>
            @error('body') <span class="fb-error">{{ $message }}</span> @enderror
        </label>
        <div class="fb-composer__actions">
            <button type="submit" class="fb-btn fb-btn--primary">{{ __('feedback-board::feedback-board.board.reply') }}</button>
        </div>
    </form>
</div>
```

- [ ] **Step 5: Append the Task 4 CSS**

Append to the end of `resources/dist/feedback-board.css` (one blank line before it):

```css
/* ---------- My reports ---------- */

.fb-reports {
    display: grid;
    gap: 1.25rem;
}

/* Under a forced or system theme the package surface can differ from the host page, so the
   outermost surfaces paint their own background. */
.fb-reports:not([data-fb-theme="class"]) {
    padding: 1rem;
    border-radius: var(--_fb-radius);
    background: var(--_fb-bg);
}

.fb-reports__title {
    font-size: 1.25rem;
    font-weight: 700;
}

.fb-reports__layout {
    display: grid;
    align-items: start;
    gap: 1.25rem;
}

@media (min-width: 64rem) {
    .fb-reports__layout {
        grid-template-columns: minmax(0, 24rem) minmax(0, 1fr);
    }
}

.fb-list {
    display: grid;
    gap: 0.5rem;
}

.fb-list__item {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: center;
    gap: 0.25rem 0.75rem;
    inline-size: 100%;
    padding: 0.75rem 1rem;
    border: 1px solid var(--_fb-border);
    border-radius: var(--_fb-radius);
    background: var(--_fb-surface);
    color: var(--_fb-text);
    text-align: start;
    box-shadow: var(--_fb-shadow);
    cursor: pointer;
    transition: border-color 0.15s ease;
}

.fb-list__item:hover {
    border-color: var(--_fb-border-strong);
}

.fb-list__title {
    font-weight: 600;
    overflow-wrap: anywhere;
}

.fb-list__time {
    grid-column: 1 / -1;
    font-size: 0.75rem;
    color: var(--_fb-text-muted);
}

/* ---------- Drawer ---------- */

.fb-drawer {
    position: fixed;
    inset: 0;
    z-index: calc(var(--_fb-z) + 10);
    display: flex;
    justify-content: flex-end;
}

.fb-drawer__backdrop {
    position: absolute;
    inset: 0;
    background: var(--_fb-backdrop);
    animation: fb-fade-in 0.2s ease-out;
}

.fb-drawer__panel {
    position: relative;
    display: flex;
    flex-direction: column;
    inline-size: min(40rem, 100%);
    block-size: 100%;
    background: var(--_fb-surface);
    color: var(--_fb-text);
    box-shadow: var(--_fb-shadow-lg);
    animation: fb-slide-in 0.2s ease-out;
}

.fb-scope[dir="rtl"] .fb-drawer__panel {
    --_fb-slide-from: -100%;
}

/* The close button sits in a bar outside the scrolling body, so it never scrolls away. */
.fb-drawer__bar {
    display: flex;
    justify-content: flex-end;
    padding: 0.75rem 0.75rem 0;
}

.fb-drawer__body {
    flex: 1;
    min-block-size: 0;
    overflow-y: auto;
    overscroll-behavior: contain;
    padding: 0.25rem 1.25rem 1.5rem;
}

@keyframes fb-slide-in {
    from {
        transform: translateX(var(--_fb-slide-from, 100%));
    }
}

/* ---------- Item header, thread, composer (shared by reporter and developer views) ---------- */

.fb-item,
.fb-detail {
    display: grid;
    gap: 1.25rem;
}

.fb-detail__header {
    display: grid;
    gap: 0.5rem;
}

.fb-detail__title {
    font-size: 1.25rem;
    font-weight: 700;
    line-height: 1.3;
    overflow-wrap: anywhere;
}

.fb-detail__meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
    color: var(--_fb-text-muted);
    font-size: 0.8125rem;
}

.fb-thread {
    display: grid;
    gap: 0.75rem;
}

.fb-comment {
    display: grid;
    gap: 0.375rem;
    padding: 0.75rem 0.875rem;
    border: 1px solid var(--_fb-border);
    border-inline-start: 3px solid var(--_fb-border-strong);
    border-radius: var(--_fb-radius-sm);
    background: var(--_fb-surface);
}

.fb-comment--reporter {
    border-inline-start-color: var(--_fb-accent);
}

.fb-comment--developer {
    border-inline-start-color: var(--_fb-border-strong);
}

.fb-comment--ai {
    border-inline-start-color: #0ea5e9;
}

.fb-comment--internal {
    border-inline-start-color: var(--_fb-internal-border);
    background: var(--_fb-internal-bg);
}

.fb-comment__head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
    color: var(--_fb-text-muted);
    font-size: 0.75rem;
}

.fb-comment__author {
    color: var(--_fb-text);
    font-weight: 600;
}

.fb-comment__time {
    margin-inline-start: auto;
}

.fb-composer {
    display: grid;
    gap: 0.75rem;
}

.fb-composer__panel {
    display: grid;
    gap: 0.5rem;
}

.fb-composer__panel--internal .fb-textarea {
    border-color: var(--_fb-internal-border);
    background: var(--_fb-internal-bg);
}

.fb-composer__actions {
    display: flex;
    justify-content: flex-end;
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/MyReportsTest.php tests/Unit/StylesheetTest.php`
Expected: PASS.

- [ ] **Step 7: Full suite and lint**

Run: `php -l src/Livewire/MyReportsBoard.php && vendor/bin/pest`
Expected: no syntax errors; full suite PASS.

- [ ] **Step 8: Commit**

```bash
git add resources/views/livewire/my-reports-board.blade.php resources/views/livewire/feedback-item-modal.blade.php src/Livewire/MyReportsBoard.php resources/dist/feedback-board.css tests/Feature/MyReportsTest.php
git commit -m "feat(ui): style My reports with a closable report drawer and role-labelled thread"
```

### Task 5: Kanban board and developer detail

**Files:**
- Modify: `resources/views/livewire/feedback-kanban.blade.php` (full rewrite)
- Modify: `resources/views/livewire/feedback-detail.blade.php` (full rewrite)
- Modify: `src/Livewire/FeedbackKanban.php` (new `close()`)
- Modify: `src/Livewire/FeedbackDetail.php` (`render()` passes `reporterName`, `assigneeName`, `context`)
- Modify: `resources/dist/feedback-board.css` (append the Task 5 section)
- Test: `tests/Feature/KanbanTest.php`, `tests/Feature/FeedbackDetailTest.php`

**Interfaces:**
- Consumes: Task 1 `Ui::safeUrl()` and contract; Task 2 `FeedbackPriority::color()`, `ActivityAction::label()`, `AuthorRole::label()`, keys `board.empty_column|status|priority|no_priority|assignee|unassigned|context|page_url|browser|locale|internal`; Task 4 drawer pattern and shared item/thread/composer classes.
- Produces: `FeedbackKanban::close(): void`; view data `reporterName: ?string`, `assigneeName: ?string`, `context: list<array{label: string, value: string, href: ?string}>`; classes `fb-kanban`, `fb-kanban__columns`, `fb-col`, `fb-col--over`, `fb-col__header`, `fb-col__title`, `fb-col__count`, `fb-col__body`, `fb-col__empty`, `fb-card`, `fb-card--dragging`, `fb-card__title`, `fb-card__meta`, `fb-card__time`, `fb-detail__controls`, `fb-detail__assignee`, `fb-context`, `fb-attachments`, `fb-triage`, `fb-runner`, `fb-tabs`, `fb-tab`, `fb-activity`, `fb-activity__item`, `fb-activity__time`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/KanbanTest.php` (add `use YousefAman\FeedbackBoard\Enums\FeedbackPriority;` to the imports):

```php
it('closes the detail drawer', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $item = FeedbackItem::factory()->create(['title' => 'Card X']);

    Livewire::actingAs($dev)->test(FeedbackKanban::class)
        ->call('open', $item->id)
        ->assertSeeHtml('class="fb-drawer"')
        ->call('close')
        ->assertSet('selectedId', null)
        ->assertDontSeeHtml('class="fb-drawer"')
        ->assertDontSeeLivewire('feedback-board::feedback-detail');
});

it('renders columns with counts, empty placeholders and keyboard-operable cards', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    FeedbackItem::factory()->create(['title' => 'Urgent card', 'status' => FeedbackStatus::New, 'priority' => FeedbackPriority::Urgent]);

    Livewire::actingAs($dev)->test(FeedbackKanban::class)
        ->assertSeeHtml('class="fb-scope fb-kanban"')
        ->assertSeeHtml('class="fb-col__count">1</span>')
        ->assertSeeHtml('fb-badge fb-badge--danger')
        ->assertSeeHtml('fb-dot fb-dot--gray')
        ->assertSeeHtml('role="button" tabindex="0"')
        ->assertSeeHtml("\$event.dataTransfer.setData('id'")
        ->assertSeeHtml('$event.dataTransfer.getData(\'id\') && $wire.moveTo($event.dataTransfer.getData(\'id\'), \'in_progress\')')
        ->assertSeeHtml('$event.dataTransfer.types.includes(\'id\') && over++')
        ->assertSeeHtml('x-on:keydown.space.prevent=')
        ->assertSee(__('feedback-board::feedback-board.board.empty_column'));
});
```

Append to `tests/Feature/FeedbackDetailTest.php` (it already imports `AuthorRole`, `FeedbackPriority` and `FeedbackStatus`):

```php
it('labels triage suggestions with translated enum labels', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $item = FeedbackItem::factory()->create(['priority' => FeedbackPriority::Low]);
    $item->ai_state = ['triage' => ['status' => 'completed', 'summary' => 'Looks urgent', 'suggested_type' => 'feature', 'suggested_priority' => 'urgent', 'model' => 'fake']];
    $item->save();

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->assertSee(__('feedback-board::feedback-board.triage.apply_priority').': Urgent')
        ->assertSee(__('feedback-board::feedback-board.triage.apply_type').': Feature request')
        ->assertSeeHtml('<span dir="auto">Looks urgent</span>');
});

it('shows a disabled no-priority placeholder only while the item has no priority', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $item = FeedbackItem::factory()->create(['priority' => null]);

    $component = Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->assertSeeHtml('<option value="" disabled selected>');

    $component->call('setPriority', FeedbackPriority::High->value)
        ->assertDontSeeHtml('<option value="" disabled selected>');
});

it('links the captured page URL only when it is http(s)', function (string $url, bool $linked) {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $item = FeedbackItem::factory()->create(['meta' => ['url' => $url, 'user_agent' => 'Mozilla/5.0 Test', 'locale' => 'ar']]);

    $component = Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->assertSee(__('feedback-board::feedback-board.board.context'))
        ->assertSee('Mozilla/5.0 Test')
        ->assertSee($url)
        ->assertSeeHtml('<span dir="ltr">Mozilla/5.0 Test</span>');

    $linked
        ? $component->assertSeeHtml('href="'.e($url).'"')
        : $component->assertDontSeeHtml('href="'.e($url).'"')->assertDontSeeHtml('href="javascript:');
})->with([
    ['https://app.test/orders/7', true],
    ['javascript:alert(1)', false],
    [' javascript:alert(1)', false],
]);

it('omits the context section when nothing was captured', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $item = FeedbackItem::factory()->create(['meta' => []]);

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->assertDontSee(__('feedback-board::feedback-board.board.context'));
});

it('shows reporter, assignee, role-labelled thread and translated activity', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $item = FeedbackItem::factory()->create(['reporter_name' => 'Lena Client', 'status' => FeedbackStatus::New]);

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->assertSee('Lena Client')
        ->assertSee(__('feedback-board::feedback-board.board.unassigned'))
        ->set('note', 'root cause: cache')->call('internalNote')
        ->call('assignToMe')
        ->call('changeStatus', FeedbackStatus::InProgress->value)
        ->assertSee('Dev')
        ->assertSee(AuthorRole::Developer->label())
        ->assertSeeHtml('fb-comment--internal')
        ->assertSee(__('feedback-board::feedback-board.board.internal'))
        ->assertSee('Status changed: In progress')
        ->assertSeeHtml('class="fb-scope fb-detail"')
        ->assertSeeHtml('id="fb-drawer-title"')
        ->assertSeeHtml('x-bind:aria-pressed="tab === \'note\'"');
});

it('records an unassignment as such in the Arabic timeline', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $item = FeedbackItem::factory()->create();
    app()->setLocale('ar');

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->call('assignToMe')
        ->call('unassign')
        ->assertSee('أُلغي الإسناد')
        ->assertSeeHtml('dir="rtl"');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/KanbanTest.php tests/Feature/FeedbackDetailTest.php`
Expected: FAIL — on missing markup: the drawer test fails at `class="fb-drawer"` (asserted before `close` is called), and `fb-kanban`, `fb-col__count`, the placeholder option, the context section, translated triage labels, `Lena Client` and `Status changed: In progress` are absent. "omits the context section when nothing was captured" already passes — it is the negative control.

- [ ] **Step 3: Add `FeedbackKanban::close()`**

In `src/Livewire/FeedbackKanban.php`, after `open()`:

```php
    public function close(): void { $this->selectedId = null; }
```

- [ ] **Step 4: Pass the detail view data**

In `src/Livewire/FeedbackDetail.php`, add `use YousefAman\FeedbackBoard\Support\Ui;` to the imports and replace `render()` with:

```php
    public function render()
    {
        return view('feedback-board::livewire.feedback-detail', [
            'comments' => $this->item->comments()->oldest()->get(), // developer sees internal too
            'activities' => $this->item->activities, // latest-first (relation default)
            'attachments' => $this->item->attachments,
            'statuses' => FeedbackStatus::cases(),
            'priorities' => FeedbackPriority::cases(),
            'reporterName' => $this->item->reporter_name ?: ($this->item->reporter_email ?: null),
            'assigneeName' => $this->assigneeName(),
            'context' => $this->context(),
        ]);
    }

    private function assigneeName(): ?string
    {
        if (! $this->item->assignee_id) {
            return null;
        }

        $name = data_get($this->item->assignee, 'name') ?: data_get($this->item->assignee, 'email');

        return is_string($name) && $name !== '' ? $name : '#'.$this->item->assignee_id;
    }

    /**
     * Captured request context (page URL, browser, locale). The URL comes from the Referer header
     * of the reporter's request, so it only becomes a link when it is http(s).
     *
     * @return list<array{label: string, value: string, href: ?string}>
     */
    private function context(): array
    {
        $meta = is_array($this->item->meta) ? $this->item->meta : [];
        $rows = [];

        foreach (['url' => 'page_url', 'user_agent' => 'browser', 'locale' => 'locale'] as $key => $label) {
            $value = $meta[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $rows[] = [
                'label' => __('feedback-board::feedback-board.board.'.$label),
                'value' => $value,
                'href' => $key === 'url' ? Ui::safeUrl($value) : null,
            ];
        }

        return $rows;
    }
```

- [ ] **Step 5: Rewrite the views**

`resources/views/livewire/feedback-kanban.blade.php` — exactly:

```blade
<div class="fb-scope fb-kanban" data-fb-theme="{{ \YousefAman\FeedbackBoard\Support\Ui::theme() }}" dir="{{ __('feedback-board::feedback-board.dir') }}"
     x-data x-on:dragover.prevent="">
    @include('feedback-board::partials.assets')
    <div class="fb-kanban__columns">
        @foreach ($columns as $status => $items)
            @php($columnStatus = \YousefAman\FeedbackBoard\Enums\FeedbackStatus::from($status))
            <section class="fb-col" data-status="{{ $status }}" wire:key="col-{{ $status }}"
                     x-data="{ over: 0 }"
                     x-on:dragenter="$event.dataTransfer.types.includes('id') && over++"
                     x-on:dragleave="$event.dataTransfer.types.includes('id') && (over = Math.max(0, over - 1))"
                     x-on:drop.prevent="over = 0; $event.dataTransfer.getData('id') && $wire.moveTo($event.dataTransfer.getData('id'), '{{ $status }}')"
                     x-bind:class="{ 'fb-col--over': over > 0 }">
                <header class="fb-col__header">
                    <span class="fb-dot fb-dot--{{ $columnStatus->color() }}" aria-hidden="true"></span>
                    <h3 class="fb-col__title">{{ $columnStatus->label() }}</h3>
                    <span class="fb-col__count">{{ count($items) }}</span>
                </header>
                <div class="fb-col__body">
                    @forelse ($items as $item)
                        <div class="fb-card" wire:key="k-{{ $item->id }}"
                             role="button" tabindex="0"
                             wire:click="open({{ $item->id }})"
                             x-on:keydown.enter.prevent="$wire.open({{ $item->id }})"
                             x-on:keydown.space.prevent="$wire.open({{ $item->id }})"
                             draggable="true"
                             x-data="{ dragging: false }"
                             x-on:dragstart="dragging = true; $event.dataTransfer.effectAllowed = 'move'; $event.dataTransfer.setData('id', '{{ $item->id }}')"
                             x-on:dragend="dragging = false"
                             x-bind:class="{ 'fb-card--dragging': dragging }">
                            <p class="fb-card__title" dir="auto">{{ $item->title }}</p>
                            <div class="fb-card__meta">
                                @if ($item->type)
                                    <span class="fb-chip">{{ $item->type->label() }}</span>
                                @endif
                                @if ($item->priority)
                                    <span class="fb-badge fb-badge--{{ $item->priority->color() }}">{{ $item->priority->label() }}</span>
                                @endif
                                @if ($item->created_at)
                                    <time class="fb-card__time" datetime="{{ $item->created_at->toIso8601String() }}">{{ $item->created_at->diffForHumans() }}</time>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="fb-col__empty">{{ __('feedback-board::feedback-board.board.empty_column') }}</p>
                    @endforelse
                </div>
            </section>
        @endforeach
    </div>

    @if ($selected)
        <div class="fb-drawer" x-data x-on:keydown.escape.window="$wire.close()">
            <div class="fb-drawer__backdrop" wire:click="close"></div>
            <div class="fb-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="fb-drawer-title" x-trap.noscroll="true">
                <div class="fb-drawer__bar">
                    <button type="button" class="fb-icon-btn" wire:click="close" aria-label="{{ __('feedback-board::feedback-board.close') }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="fb-drawer__body">
                    @livewire('feedback-board::feedback-detail', ['item' => $selected], key('detail-'.$selected->id))
                </div>
            </div>
        </div>
    @endif
</div>
```

`resources/views/livewire/feedback-detail.blade.php` — exactly:

```blade
{{-- resources/views/livewire/feedback-detail.blade.php --}}
<div class="fb-scope fb-detail" data-fb-theme="{{ \YousefAman\FeedbackBoard\Support\Ui::theme() }}" dir="{{ __('feedback-board::feedback-board.dir') }}">
    @include('feedback-board::partials.assets')
    <header class="fb-detail__header">
        <h2 id="fb-drawer-title" class="fb-detail__title" dir="auto">{{ $item->title }}</h2>
        <div class="fb-detail__meta">
            @if ($item->type)
                <span class="fb-chip">{{ $item->type->label() }}</span>
            @endif
            @if ($reporterName)
                <span>{{ $reporterName }}</span>
            @endif
            @if ($item->created_at)
                <time datetime="{{ $item->created_at->toIso8601String() }}">{{ $item->created_at->diffForHumans() }}</time>
            @endif
        </div>
    </header>

    @if (filled($item->description))
        <p class="fb-prose" dir="auto">{{ $item->description }}</p>
    @endif

    <div class="fb-detail__controls">
        <label class="fb-field">
            <span class="fb-label">{{ __('feedback-board::feedback-board.board.status') }}</span>
            <select class="fb-select" wire:change="changeStatus($event.target.value)">
                @foreach ($statuses as $s)
                    <option value="{{ $s->value }}" @selected($item->status === $s)>{{ $s->label() }}</option>
                @endforeach
            </select>
        </label>
        <label class="fb-field">
            <span class="fb-label">{{ __('feedback-board::feedback-board.board.priority') }}</span>
            <select class="fb-select" wire:change="setPriority($event.target.value)">
                <option value="" disabled @selected($item->priority === null)>{{ __('feedback-board::feedback-board.board.no_priority') }}</option>
                @foreach ($priorities as $p)
                    <option value="{{ $p->value }}" @selected($item->priority === $p)>{{ $p->label() }}</option>
                @endforeach
            </select>
        </label>
        <div class="fb-field">
            <span class="fb-label">{{ __('feedback-board::feedback-board.board.assignee') }}</span>
            <div class="fb-detail__assignee">
                <span>{{ $assigneeName ?? __('feedback-board::feedback-board.board.unassigned') }}</span>
                @if ($item->assignee_id)
                    <button type="button" class="fb-btn fb-btn--sm" wire:click="unassign">{{ __('feedback-board::feedback-board.board.unassign') }}</button>
                @else
                    <button type="button" class="fb-btn fb-btn--sm" wire:click="assignToMe">{{ __('feedback-board::feedback-board.board.assign_me') }}</button>
                @endif
            </div>
        </div>
    </div>

    @if ($context !== [])
        <section class="fb-section">
            <h3 class="fb-section-title">{{ __('feedback-board::feedback-board.board.context') }}</h3>
            <dl class="fb-context">
                @foreach ($context as $row)
                    <dt>{{ $row['label'] }}</dt>
                    <dd>
                        @if ($row['href'] !== null)
                            <a class="fb-link" href="{{ $row['href'] }}" target="_blank" rel="noopener noreferrer" dir="ltr">{{ $row['value'] }}</a>
                        @else
                            <span dir="ltr">{{ $row['value'] }}</span>
                        @endif
                    </dd>
                @endforeach
            </dl>
        </section>
    @endif

    @if ($attachments->isNotEmpty())
        <section class="fb-section">
            <h3 class="fb-section-title">{{ __('feedback-board::feedback-board.board.attachments') }}</h3>
            <ul class="fb-attachments">
                @foreach ($attachments as $att)
                    <li>
                        <a href="{{ route('feedback-board.attachments.show', $att) }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                            <span>{{ $att->original_name }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @php($triage = $item->ai_state['triage'] ?? null)
    @if ($triage)
        <div class="fb-panel fb-triage" wire:key="triage-{{ $item->id }}"
             @if(($triage['status'] ?? null) === 'pending') wire:poll.5s @endif>
            @if (($triage['status'] ?? null) === 'pending')
                <p class="fb-muted">{{ __('feedback-board::feedback-board.triage.analyzing') }}</p>
            @elseif (($triage['status'] ?? null) === 'failed')
                <p class="fb-notice fb-notice--danger">{{ __('feedback-board::feedback-board.triage.failed') }}</p>
                <div class="fb-actions">
                    <button type="button" class="fb-btn fb-btn--sm" wire:click="requestTriage">{{ __('feedback-board::feedback-board.triage.retriage') }}</button>
                </div>
            @elseif (($triage['status'] ?? null) === 'completed')
                <p><strong>{{ __('feedback-board::feedback-board.triage.summary') }}:</strong> <span dir="auto">{{ $triage['summary'] }}</span></p>
                <div class="fb-actions">
                    @if (($triage['suggested_type'] ?? null) && $triage['suggested_type'] !== ($item->type?->value))
                        <button type="button" class="fb-btn fb-btn--sm" wire:click="applyTriage('type')">
                            {{ __('feedback-board::feedback-board.triage.apply_type') }}: {{ \YousefAman\FeedbackBoard\Enums\FeedbackType::tryFrom((string) $triage['suggested_type'])?->label() ?? $triage['suggested_type'] }}
                        </button>
                    @endif

                    @if (($triage['suggested_priority'] ?? null) && $triage['suggested_priority'] !== ($item->priority?->value))
                        <button type="button" class="fb-btn fb-btn--sm" wire:click="applyTriage('priority')">
                            {{ __('feedback-board::feedback-board.triage.apply_priority') }}: {{ \YousefAman\FeedbackBoard\Enums\FeedbackPriority::tryFrom((string) $triage['suggested_priority'])?->label() ?? $triage['suggested_priority'] }}
                        </button>
                    @endif

                    <button type="button" class="fb-btn fb-btn--sm" wire:click="requestTriage">{{ __('feedback-board::feedback-board.triage.retriage') }}</button>
                </div>
            @endif
        </div>
    @endif

    @php($runner = $item->ai_state['runner'] ?? null)
    @php($runnerConfigured = (bool) \YousefAman\FeedbackBoard\Runner\Support\RunnerGate::configured())
    @if ($runnerConfigured)
        <div class="fb-panel fb-runner" wire:key="runner-{{ $item->id }}"
             @if(in_array(($runner['status'] ?? null), ['dispatched','running'], true)) wire:poll.5s @endif>
            @if (! config('feedback-board.ingest.enabled'))
                <p class="fb-notice fb-notice--warning">{{ __('feedback-board::feedback-board.runner.ingest_disabled') }}</p>
            @elseif (($runner['status'] ?? null) === null)
                <div class="fb-actions">
                    <button type="button" class="fb-btn fb-btn--sm" wire:click="dispatchToRunner">{{ __('feedback-board::feedback-board.runner.send') }}</button>
                </div>
            @else
                @if (in_array($runner['status'], ['dispatched','running'], true))
                    <p class="fb-muted">{{ __('feedback-board::feedback-board.runner.'.$runner['status']) }} — {{ $runner['dispatched_at'] ?? '' }}</p>
                @elseif ($runner['status'] === 'failed')
                    <p class="fb-notice fb-notice--danger">{{ __('feedback-board::feedback-board.runner.failed') }}: {{ $runner['error_code'] ?? '' }}</p>
                @elseif ($runner['status'] === 'completed')
                    <p>{{ __('feedback-board::feedback-board.runner.completed') }}</p>
                @endif

                @if (($runner['diff'] ?? null) !== null)
                    <details class="fb-details"><summary>{{ __('feedback-board::feedback-board.runner.diff') }}</summary><pre class="fb-pre">{{ $runner['diff'] }}</pre></details>
                @endif
                @if (($runner['pr_url'] ?? null) !== null)
                    <a class="fb-link" href="{{ $runner['pr_url'] }}" target="_blank" rel="noopener noreferrer" dir="ltr">{{ $runner['pr_url'] }}</a>
                @endif

                @if (! in_array($runner['status'], ['dispatched','running'], true))
                    <div class="fb-actions">
                        <button type="button" class="fb-btn fb-btn--sm" wire:click="dispatchToRunner">{{ __('feedback-board::feedback-board.runner.resend') }}</button>
                    </div>
                @endif
            @endif
        </div>
    @endif

    @if ($comments->isNotEmpty())
        <section class="fb-section">
            <h3 class="fb-section-title">{{ __('feedback-board::feedback-board.board.thread') }}</h3>
            <div class="fb-thread">
                @foreach ($comments as $c)
                    <article wire:key="dc-{{ $c->id }}" @class(['fb-comment', 'fb-comment--'.$c->author_role->value, 'fb-comment--internal' => $c->is_internal])>
                        <div class="fb-comment__head">
                            <span class="fb-comment__author">{{ $c->author_role->label() }}</span>
                            @if ($c->is_internal)
                                <span class="fb-badge fb-badge--warning">{{ __('feedback-board::feedback-board.board.internal') }}</span>
                            @endif
                            @if ($c->created_at)
                                <time class="fb-comment__time" datetime="{{ $c->created_at->toIso8601String() }}">{{ $c->created_at->diffForHumans() }}</time>
                            @endif
                        </div>
                        <p class="fb-prose" dir="auto">{{ $c->body }}</p>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    <div class="fb-composer" x-data="{ tab: 'reply' }">
        <div class="fb-tabs">
            <button type="button" class="fb-tab" x-on:click="tab = 'reply'" x-bind:aria-pressed="tab === 'reply'">{{ __('feedback-board::feedback-board.board.reply') }}</button>
            <button type="button" class="fb-tab" x-on:click="tab = 'note'" x-bind:aria-pressed="tab === 'note'">{{ __('feedback-board::feedback-board.board.internal_note') }}</button>
        </div>

        <form wire:submit="reply" class="fb-composer__panel" x-show="tab === 'reply'">
            <label class="fb-field">
                <span class="fb-sr-only">{{ __('feedback-board::feedback-board.board.reply') }}</span>
                <textarea class="fb-textarea" wire:model="body" rows="4" dir="auto" placeholder="{{ __('feedback-board::feedback-board.board.reply') }}"></textarea>
                @error('body') <span class="fb-error">{{ $message }}</span> @enderror
            </label>
            <div class="fb-composer__actions">
                <button type="submit" class="fb-btn fb-btn--primary">{{ __('feedback-board::feedback-board.board.reply') }}</button>
            </div>
        </form>

        <form wire:submit="internalNote" class="fb-composer__panel fb-composer__panel--internal" x-show="tab === 'note'">
            <label class="fb-field">
                <span class="fb-sr-only">{{ __('feedback-board::feedback-board.board.internal_note') }}</span>
                <textarea class="fb-textarea" wire:model="note" rows="4" dir="auto" placeholder="{{ __('feedback-board::feedback-board.board.internal_note') }}"></textarea>
                @error('note') <span class="fb-error">{{ $message }}</span> @enderror
            </label>
            <div class="fb-composer__actions">
                <button type="submit" class="fb-btn">{{ __('feedback-board::feedback-board.board.internal_note') }}</button>
            </div>
        </form>
    </div>

    @if ($activities->isNotEmpty())
        <section class="fb-section">
            <h3 class="fb-section-title">{{ __('feedback-board::feedback-board.board.activity') }}</h3>
            <ul class="fb-activity">
                @foreach ($activities as $a)
                    <li class="fb-activity__item" wire:key="act-{{ $a->id }}">
                        <span>{{ $a->summary() }}</span>
                        @if ($a->created_at)
                            <time class="fb-activity__time" datetime="{{ $a->created_at->toIso8601String() }}">{{ $a->created_at->diffForHumans() }}</time>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
```

- [ ] **Step 6: Append the Task 5 CSS**

Append to the end of `resources/dist/feedback-board.css` (one blank line before it):

```css
/* ---------- Kanban ---------- */

/* Lets the column scroller shrink inside a flex or grid host instead of widening the page. */
.fb-kanban {
    min-inline-size: 0;
}

.fb-kanban:not([data-fb-theme="class"]) {
    padding: 1rem;
    border-radius: var(--_fb-radius);
    background: var(--_fb-bg);
}

.fb-kanban__columns {
    display: grid;
    grid-auto-flow: column;
    grid-auto-columns: minmax(16rem, 1fr);
    align-items: start;
    gap: 1rem;
    overflow-x: auto;
    padding-block-end: 0.5rem;
    scroll-snap-type: x proximity;
}

.fb-col {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    min-block-size: 12rem;
    padding: 0.75rem;
    border: 1px solid var(--_fb-border);
    border-radius: var(--_fb-radius);
    background: var(--_fb-bg);
    scroll-snap-align: start;
    transition: border-color 0.15s ease, background-color 0.15s ease;
}

.fb-col--over {
    border-color: var(--_fb-accent);
    border-style: dashed;
    background: var(--_fb-surface-muted);
}

.fb-col__header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding-inline: 0.25rem;
}

.fb-col__title {
    flex: 1;
    min-width: 0;
    font-size: 0.875rem;
    font-weight: 600;
}

.fb-col__count {
    min-inline-size: 1.5rem;
    padding: 0 0.375rem;
    border-radius: 999px;
    background: var(--_fb-surface-muted);
    color: var(--_fb-text-muted);
    font-size: 0.75rem;
    font-weight: 600;
    text-align: center;
}

.fb-col__body {
    display: grid;
    gap: 0.5rem;
}

.fb-col__empty {
    padding: 1rem;
    border: 1px dashed var(--_fb-border-strong);
    border-radius: var(--_fb-radius-sm);
    color: var(--_fb-text-muted);
    font-size: 0.8125rem;
    text-align: center;
}

.fb-card {
    display: grid;
    gap: 0.5rem;
    padding: 0.75rem;
    border: 1px solid var(--_fb-border);
    border-radius: var(--_fb-radius-sm);
    background: var(--_fb-surface);
    box-shadow: var(--_fb-shadow);
    cursor: grab;
    transition: border-color 0.15s ease, opacity 0.15s ease;
}

.fb-card:hover {
    border-color: var(--_fb-border-strong);
}

.fb-card:active {
    cursor: grabbing;
}

.fb-card--dragging {
    opacity: 0.5;
}

.fb-card__title {
    display: -webkit-box;
    overflow: hidden;
    font-size: 0.875rem;
    font-weight: 600;
    line-height: 1.35;
    overflow-wrap: anywhere;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 3;
}

.fb-card__meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.375rem;
    color: var(--_fb-text-muted);
    font-size: 0.75rem;
}

.fb-card__time {
    margin-inline-start: auto;
}

/* ---------- Developer detail ---------- */

.fb-detail__controls {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 0.75rem;
    padding: 0.75rem;
    border: 1px solid var(--_fb-border);
    border-radius: var(--_fb-radius);
    background: var(--_fb-bg);
}

.fb-detail__controls > .fb-field {
    flex: 1 1 10rem;
}

.fb-detail__assignee {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
    min-height: 2.5rem;
}

.fb-context {
    display: grid;
    grid-template-columns: max-content minmax(0, 1fr);
    gap: 0.375rem 1rem;
    font-size: 0.8125rem;
}

.fb-context dt {
    color: var(--_fb-text-muted);
}

.fb-context dd {
    overflow-wrap: anywhere;
}

.fb-attachments {
    display: grid;
    gap: 0.375rem;
}

.fb-attachments a {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    color: var(--_fb-link);
    text-decoration: none;
    overflow-wrap: anywhere;
}

.fb-attachments a:hover {
    text-decoration: underline;
}

.fb-attachments svg {
    width: 1rem;
    height: 1rem;
}

.fb-triage,
.fb-runner {
    display: grid;
    gap: 0.625rem;
}

.fb-tabs {
    display: flex;
    gap: 0.25rem;
    border-block-end: 1px solid var(--_fb-border);
}

.fb-tab {
    margin-block-end: -1px;
    padding: 0.5rem 0.75rem;
    border: 0;
    border-block-end: 2px solid transparent;
    background: transparent;
    color: var(--_fb-text-muted);
    font-size: 0.8125rem;
    font-weight: 600;
    cursor: pointer;
}

.fb-tab:hover {
    color: var(--_fb-text);
}

.fb-tab[aria-pressed="true"] {
    border-block-end-color: var(--_fb-accent);
    color: var(--_fb-text);
}

.fb-activity {
    display: grid;
    gap: 0.5rem;
    font-size: 0.8125rem;
}

.fb-activity__item {
    position: relative;
    display: flex;
    flex-wrap: wrap;
    gap: 0.25rem 0.75rem;
    padding-inline-start: 0.875rem;
}

.fb-activity__item::before {
    content: "";
    position: absolute;
    inset-block-start: 0.5rem;
    inset-inline-start: 0;
    inline-size: 0.375rem;
    block-size: 0.375rem;
    border-radius: 999px;
    background: var(--_fb-border-strong);
}

.fb-activity__time {
    margin-inline-start: auto;
    color: var(--_fb-text-muted);
}
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/KanbanTest.php tests/Feature/FeedbackDetailTest.php tests/Feature/Triage tests/Feature/Runner tests/Unit/StylesheetTest.php`
Expected: PASS — including the existing triage/runner assertions on `wire:poll.5s`, escaped summaries and PR links.

- [ ] **Step 8: Full suite and lint**

Run: `php -l src/Livewire/FeedbackKanban.php && php -l src/Livewire/FeedbackDetail.php && vendor/bin/pest`
Expected: no syntax errors; full suite PASS.

- [ ] **Step 9: Commit**

```bash
git add resources/views/livewire/feedback-kanban.blade.php resources/views/livewire/feedback-detail.blade.php src/Livewire/FeedbackKanban.php src/Livewire/FeedbackDetail.php resources/dist/feedback-board.css tests/Feature/KanbanTest.php tests/Feature/FeedbackDetailTest.php
git commit -m "feat(ui): style the Kanban board and developer detail drawer with captured context"
```

### Task 6: Standalone pages, Filament views and page title

**Files:**
- Modify: `resources/views/pages/board.blade.php`, `resources/views/pages/feedback.blade.php` (full rewrites)
- Modify: `resources/views/filament/runner-result.blade.php`, `resources/views/filament/triage-suggestion.blade.php` (full rewrites)
- Modify: `src/Filament/Pages/FeedbackBoardPage.php`
- Modify: `resources/dist/feedback-board.css` (append the Task 6 section — it must stay last)
- Test: `tests/Feature/StandalonePagesTest.php` (new), `tests/Filament/PanelSmokeTest.php`

**Interfaces:**
- Consumes: Task 1 contract, `Ui::stylesheetUrl()`; Tasks 4–5 surfaces (rendered inside the pages); Task 2 key `board.title`.
- Produces: classes `fb-standalone`, `fb-standalone__main`, `fb-standalone__main--narrow`, `fb-standalone__title`, `fb-runner-result`, `fb-triage-suggestion`; `FeedbackBoardPage::getTitle()` and `::getNavigationLabel()` returning `board.title`; full-width board content in Filament (`$maxContentWidth = Width::Full` — six 16 rem columns never fit Filament's default 7xl content width).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/StandalonePagesTest.php`:

```php
<?php

use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Support\Ui;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(fn () => config()->set('feedback-board.authorization.developer_resolver', fn ($u) => $u->name === 'Dev'));

function fbStylesheetLink(): string
{
    return '<link rel="stylesheet" href="'.Ui::stylesheetUrl().'"';
}

it('serves the reports page as a responsive standalone document with the stylesheet in its head', function () {
    $me = TestUser::create(['name' => 'Me', 'email' => 'me@x.com']);
    FeedbackItem::factory()->for($me, 'reporter')->create(['title' => 'Mine']);

    $html = $this->actingAs($me)->get(route('feedback-board.page'))->assertOk()->getContent();

    expect(substr_count($html, fbStylesheetLink()))->toBe(1)
        ->and(strpos($html, fbStylesheetLink()))->toBeLessThan(strpos($html, '</head>'))
        ->and($html)->toContain('<meta name="viewport" content="width=device-width, initial-scale=1">')
        ->and($html)->toContain('<meta name="color-scheme" content="light dark">')
        ->and($html)->toContain('<html lang="en" dir="ltr">')
        ->and($html)->toContain('class="fb-scope fb-standalone" data-fb-theme="class"')
        ->and($html)->toContain('fb-standalone__main--narrow')
        ->and($html)->toContain('Mine');
});

it('serves the board page with a heading and the stylesheet in its head', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    FeedbackItem::factory()->create(['title' => 'Board card']);

    $html = $this->actingAs($dev)->get(route('feedback-board.board.page'))->assertOk()->getContent();

    expect(substr_count($html, fbStylesheetLink()))->toBe(1)
        ->and(strpos($html, fbStylesheetLink()))->toBeLessThan(strpos($html, '</head>'))
        ->and($html)->toContain('<h1 class="fb-standalone__title">'.__('feedback-board::feedback-board.board.title').'</h1>')
        ->and($html)->toContain('Board card');
});

it('pins the page color-scheme when the theme is forced', function () {
    $me = TestUser::create(['name' => 'Me', 'email' => 'me@x.com']);
    config()->set('feedback-board.ui.theme', 'dark');

    $html = $this->actingAs($me)->get(route('feedback-board.page'))->assertOk()->getContent();

    expect($html)->toContain('<meta name="color-scheme" content="dark">')
        ->and($html)->toContain('data-fb-theme="dark"');
});

it('renders no duplicate element ids on the reports page with a drawer open', function () {
    $me = TestUser::create(['name' => 'Me', 'email' => 'me@x.com']);
    FeedbackItem::factory()->for($me, 'reporter')->count(2)->create();

    $html = \Livewire\Livewire::actingAs($me)
        ->test(\YousefAman\FeedbackBoard\Livewire\MyReportsBoard::class)
        ->call('open', FeedbackItem::first()->id)
        ->html();

    preg_match_all('/\sid="([^"]+)"/', $html, $ids);

    expect($ids[1])->toContain('fb-drawer-title')
        ->and(array_count_values($ids[1]))->each->toBe(1);
});

it('renders the standalone pages right-to-left in Arabic', function () {
    $me = TestUser::create(['name' => 'Me', 'email' => 'me@x.com']);
    app()->setLocale('ar');

    $html = $this->actingAs($me)->get(route('feedback-board.page'))->assertOk()->getContent();

    expect($html)->toContain('<html lang="ar" dir="rtl">')
        ->and($html)->toContain('dir="rtl"');
});
```

In `tests/Filament/PanelSmokeTest.php`, append:

```php
it('styles the board page and titles it with the translated board title', function () {
    $html = $this->actingAs(FilamentTestUser::create(['name' => 'Dev']))
        ->get(FeedbackBoardPage::getUrl())
        ->assertOk()
        ->getContent();

    $link = '<link rel="stylesheet" href="'.\YousefAman\FeedbackBoard\Support\Ui::stylesheetUrl().'"';

    expect(substr_count($html, $link))->toBe(1)
        ->and(strpos($html, $link))->toBeLessThan(strpos($html, '</head>'))
        ->and($html)->toContain('class="fb-scope fb-kanban"')
        ->and(FeedbackBoardPage::getNavigationLabel())->toBe(__('feedback-board::feedback-board.board.title'))
        ->and((new FeedbackBoardPage)->getTitle())->toBe(__('feedback-board::feedback-board.board.title'))
        ->and((new FeedbackBoardPage)->getMaxContentWidth())->toBe(\Filament\Support\Enums\Width::Full);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/StandalonePagesTest.php tests/Filament/PanelSmokeTest.php`
Expected: FAIL — the pages lack the viewport meta, `lang`, `fb-standalone` body and `<h1>`; the Filament test fails on the title (`Feedback Board Page` ≠ `Feedback board`) and the content width (`null` ≠ `Width::Full`). The stylesheet-link assertions already pass (the surfaces inject it since Tasks 3–5).

- [ ] **Step 3: Rewrite the pages**

`resources/views/pages/board.blade.php` — exactly:

```blade
{{-- resources/views/pages/board.blade.php --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ __('feedback-board::feedback-board.dir') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="{{ \YousefAman\FeedbackBoard\Support\Ui::colorScheme() }}">
    <title>{{ __('feedback-board::feedback-board.board.title') }}</title>
    @livewireStyles
</head>
<body class="fb-scope fb-standalone" data-fb-theme="{{ \YousefAman\FeedbackBoard\Support\Ui::theme() }}">
    @include('feedback-board::partials.assets')
    <main class="fb-standalone__main">
        <h1 class="fb-standalone__title">{{ __('feedback-board::feedback-board.board.title') }}</h1>
        @livewire('feedback-board::feedback-kanban')
    </main>
    @livewireScripts
</body>
</html>
```

`resources/views/pages/feedback.blade.php` — exactly:

```blade
{{-- resources/views/pages/feedback.blade.php --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ __('feedback-board::feedback-board.dir') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="{{ \YousefAman\FeedbackBoard\Support\Ui::colorScheme() }}">
    <title>{{ __('feedback-board::feedback-board.board.my_reports') }}</title>
    @livewireStyles
</head>
<body class="fb-scope fb-standalone" data-fb-theme="{{ \YousefAman\FeedbackBoard\Support\Ui::theme() }}">
    @include('feedback-board::partials.assets')
    <main class="fb-standalone__main fb-standalone__main--narrow">
        @livewire('feedback-board::my-reports-board')
    </main>
    @livewireScripts
</body>
</html>
```

- [ ] **Step 4: Rewrite the Filament action-modal views**

`resources/views/filament/runner-result.blade.php` — exactly:

```blade
<div class="fb-scope fb-runner-result" data-fb-theme="{{ \YousefAman\FeedbackBoard\Support\Ui::theme() }}" dir="{{ __('feedback-board::feedback-board.dir') }}">
    @include('feedback-board::partials.assets')
    <p><strong>{{ __('feedback-board::feedback-board.runner.status') }}:</strong> {{ $runner['status'] ?? '' }}</p>
    @if (($runner['error_code'] ?? null) !== null)
        <p class="fb-notice fb-notice--danger">{{ __('feedback-board::feedback-board.runner.failed') }}: {{ $runner['error_code'] }}</p>
    @endif
    @if (($runner['diff'] ?? null) !== null)
        <pre class="fb-pre">{{ $runner['diff'] }}</pre>
    @endif
    @if (($runner['pr_url'] ?? null) !== null)
        <a class="fb-link" href="{{ $runner['pr_url'] }}" target="_blank" rel="noopener noreferrer" dir="ltr">{{ $runner['pr_url'] }}</a>
    @endif
</div>
```

`resources/views/filament/triage-suggestion.blade.php` — exactly:

```blade
<div class="fb-scope fb-triage-suggestion" data-fb-theme="{{ \YousefAman\FeedbackBoard\Support\Ui::theme() }}" dir="{{ __('feedback-board::feedback-board.dir') }}">
    @include('feedback-board::partials.assets')
    @if (($triage['summary'] ?? null) !== null)
        <p><strong>{{ __('feedback-board::feedback-board.triage.summary') }}:</strong> {{ $triage['summary'] }}</p>
    @endif
    @if (($triage['suggested_type'] ?? null) !== null)
        <p>{{ __('feedback-board::feedback-board.triage.suggested_type') }}: {{ $triage['suggested_type'] }}</p>
    @endif
    @if (($triage['suggested_priority'] ?? null) !== null)
        <p>{{ __('feedback-board::feedback-board.triage.suggested_priority') }}: {{ $triage['suggested_priority'] }}</p>
    @endif
</div>
```

- [ ] **Step 5: Translate the Filament page title and widen the page**

In `src/Filament/Pages/FeedbackBoardPage.php`, add `use Filament\Support\Enums\Width;` to the imports, delete the line `protected static ?string $navigationLabel = 'Feedback board';` (and the blank line after it) and add, after the `$view` property:

```php

    // Six 16rem status columns never fit Filament's default 7xl content width.
    protected Width|string|null $maxContentWidth = Width::Full;

    public static function getNavigationLabel(): string
    {
        return __('feedback-board::feedback-board.board.title');
    }

    public function getTitle(): string
    {
        return __('feedback-board::feedback-board.board.title');
    }
```

- [ ] **Step 6: Append the Task 6 CSS**

Append to the end of `resources/dist/feedback-board.css` (one blank line before it). The reduced-motion block must remain the last rule in the file:

```css
/* ---------- Standalone pages ---------- */

.fb-standalone {
    min-block-size: 100vh;
    margin: 0;
    background: var(--_fb-bg);
    font-family: var(--fb-font, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans", "Noto Sans Arabic", Tahoma, Arial, sans-serif);
}

.fb-standalone__main {
    margin-inline: auto;
    padding: 1.5rem 1rem;
}

.fb-standalone__main--narrow {
    max-inline-size: 72rem;
}

.fb-standalone__title {
    margin-block-end: 1.25rem;
    font-size: 1.5rem;
    font-weight: 700;
}

@media (min-width: 40rem) {
    .fb-standalone__main {
        padding: 2rem;
    }
}

/* ---------- Filament action-modal views ---------- */

.fb-runner-result,
.fb-triage-suggestion {
    display: grid;
    gap: 0.5rem;
}

/* ---------- Reduced motion (keep last) ---------- */

@media (prefers-reduced-motion: reduce) {
    .fb-scope *,
    .fb-scope *::before,
    .fb-scope *::after {
        animation: none;
        transition: none;
    }

    .fb-launcher:hover {
        transform: none;
    }
}
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/StandalonePagesTest.php tests/Filament tests/Feature/ViewCacheTest.php tests/Unit/StylesheetTest.php`
Expected: PASS.

- [ ] **Step 8: Full suite and lint**

Run: `php -l src/Filament/Pages/FeedbackBoardPage.php && vendor/bin/pest`
Expected: no syntax errors; full suite PASS.

- [ ] **Step 9: Commit**

```bash
git add resources/views/pages/board.blade.php resources/views/pages/feedback.blade.php resources/views/filament/runner-result.blade.php resources/views/filament/triage-suggestion.blade.php src/Filament/Pages/FeedbackBoardPage.php resources/dist/feedback-board.css tests/Feature/StandalonePagesTest.php tests/Filament/PanelSmokeTest.php
git commit -m "feat(ui): make the standalone pages responsive and translate the Filament board title"
```

### Task 7: Class-coverage guard and documentation

**Files:**
- Test: `tests/Unit/StylesheetCoverageTest.php` (new)
- Modify: `README.md` (§1 launcher paragraph, new "Styling & theming" section, configuration table row)

**Interfaces:**
- Consumes: every class introduced in Tasks 1–6; `Ui::stylesheetPath()`; `FeedbackStatus::color()`, `FeedbackPriority::color()`, `AuthorRole` values.
- Produces: nothing consumed later.

- [ ] **Step 1: Write the coverage guard**

`tests/Unit/StylesheetCoverageTest.php`:

```php
<?php

use YousefAman\FeedbackBoard\Enums\{AuthorRole, FeedbackPriority, FeedbackStatus};
use YousefAman\FeedbackBoard\Support\Ui;

/** @return list<string> every .fb-* class name that has at least one rule */
function fbStyledClasses(): array
{
    $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(Ui::stylesheetPath()));
    preg_match_all('/\.(fb-[a-z0-9_-]*[a-z0-9])/', $css, $m);

    return array_values(array_unique($m[1]));
}

it('styles every static fb- class used in the package views', function () {
    $views = [
        ...glob(__DIR__.'/../../resources/views/*/*.blade.php'),
        ...glob(__DIR__.'/../../resources/views-filament/*/*.blade.php'),
    ];
    // Element ids and the launcher's browser event share the prefix but are not classes.
    $notClasses = ['fb-launcher-title', 'fb-drawer-title', 'fb-launcher-opened'];
    $used = [];

    foreach ($views as $view) {
        preg_match_all('/(?<![\w-])fb-[a-z0-9_-]*[a-z0-9]/', (string) file_get_contents($view), $m);
        $used = [...$used, ...$m[0]];
    }

    $unstyled = array_values(array_diff(array_unique($used), fbStyledClasses(), $notClasses));
    sort($unstyled);
    $facebookSdk = ['fb-page', 'fb-post', 'fb-comments', 'fb-like', 'fb-video', 'fb-group', 'fb-quote', 'fb-save', 'fb-send', 'fb-follow'];

    expect($views)->not->toBeEmpty()
        ->and($unstyled)->toBe([])
        ->and(array_values(array_intersect(array_unique($used), $facebookSdk)))->toBe([]);
});

it('styles every dynamic badge, dot and comment variant the enums can produce', function () {
    $colors = array_unique([
        ...array_map(fn ($s) => $s->color(), FeedbackStatus::cases()),
        ...array_map(fn ($p) => $p->color(), FeedbackPriority::cases()),
    ]);
    $expected = [
        ...array_map(fn ($c) => 'fb-badge--'.$c, $colors),
        ...array_map(fn ($c) => 'fb-dot--'.$c, array_map(fn ($s) => $s->color(), FeedbackStatus::cases())),
        ...array_map(fn ($r) => 'fb-comment--'.$r->value, AuthorRole::cases()),
        'fb-comment--internal',
    ];

    expect(array_values(array_diff(array_unique($expected), fbStyledClasses())))->toBe([]);
});
```

- [ ] **Step 2: Run the guard**

Run: `vendor/bin/pest tests/Unit/StylesheetCoverageTest.php`
Expected: PASS (Tasks 1–6 styled every class). If it fails, the failure lists the unstyled class names — they are typos or leftovers in a view (e.g. an old `fb-prio`, `fb-detail-pane`, `fb-runner-warn`); fix the view, never add an empty rule.

- [ ] **Step 3: Update the README**

1. In **Usage → 1. Add the launcher to your layout**, replace the paragraph that starts with "The launcher renders a floating button" with:

```markdown
The launcher renders a floating button in the bottom corner of the page (bottom-right in left-to-right locales, bottom-left in right-to-left ones) that opens the feedback form in a modal dialog. Its styles load automatically — there is nothing to build, publish or link (see [Styling & theming](#styling--theming)).
```

2. Insert a new section directly before `## Configuration Reference`:

````markdown
## Styling & theming

Every package screen — the launcher, the form, "My reports", the Kanban board and the item drawers — ships with its own stylesheet. You do not build, publish or link anything: the first package component on a page adds a single cached `<link rel="stylesheet">` to the page `<head>` (through Livewire's `@assets`), served by the package at `/feedback-board/assets/stylesheet` (under your `route_prefix`; the path has no `.css` extension so web-server rules for static files never intercept it). The styles only target the package's own `fb-*` classes and never touch your markup.

**Dark mode.** Set `FEEDBACK_BOARD_THEME` (config `feedback-board.ui.theme`):

| Value | Dark when |
|---|---|
| `class` (default) | an ancestor element has the class `dark` — Filament panels, Laravel's Livewire starter kits and Tailwind's class strategy. The package's own standalone pages follow the operating system instead. |
| `system` | the operating system prefers dark (`prefers-color-scheme`) |
| `light` / `dark` | never / always |

**Brand colors and shape.** Override these custom properties in your own CSS:

| Property | Default | Used for |
|---|---|---|
| `--fb-accent` | `#4f46e5` | primary buttons, the launcher, the reporter-comment accent |
| `--fb-accent-hover` | `#4338ca` | primary button hover |
| `--fb-accent-contrast` | `#fff` | text on the accent color |
| `--fb-radius` | `0.75rem` | panels, dialogs and drawers |
| `--fb-font` | your page font | font family |
| `--fb-z` | `1000` (`35` inside a Filament panel) | stacking order of the launcher; the dialog and drawers sit 10 above it |

```css
:root {
    --fb-accent: #0d9488;
    --fb-accent-hover: #0f766e;
}
```

The package never sets these properties itself (it only reads them with fallbacks), so your values win wherever you declare them — on `:root`, on `.fb-scope`, inside a dark-mode selector or a CSS `@layer`.

Inside a Filament panel the board follows the panel's dark mode and uses a neutral zinc palette that matches Filament's default grays. To use the panel's primary color for the accent, set `--fb-accent: var(--primary-600); --fb-accent-hover: var(--primary-700);` in the panel's theme — and check the contrast of white text on it: Filament's default amber needs `--fb-accent-contrast: #000`.

**Right-to-left.** Layout direction follows the locale's `dir` (`ar` is right-to-left); the launcher, drawers and spacing mirror automatically.

**Content Security Policy.** The stylesheet is a same-origin file, so `style-src 'self'` is enough; when you use Laravel's Vite CSP nonce (`Vite::useCspNonce()`), the `<link>` carries it too.

**Responses Livewire does not post-process.** The `<link>` is added to full HTML documents (a `200` `text/html` response containing `</html>`) and to Livewire updates. If you return a package component inside anything else — for example an HTML fragment fetched by your own JavaScript — link the stylesheet yourself:

```blade
<link rel="stylesheet" href="{{ \YousefAman\FeedbackBoard\Support\Ui::stylesheetUrl() }}">
```
````

3. In the **Configuration Reference** table, add after the `route_middleware` row:

```markdown
| `ui.theme` | string | Dark-mode strategy: `class` (default), `system`, `light` or `dark` — see [Styling & theming](#styling--theming) |
```

- [ ] **Step 4: Full suite**

Run: `vendor/bin/pest`
Expected: full suite PASS.

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/StylesheetCoverageTest.php README.md
git commit -m "docs(ui): document styling and theming; guard that every view class is styled"
```

---

## After Task 7: browser verification (controller, not a subagent task)

The PHP suite cannot see layout, so the controller renders every surface in Chromium (Playwright) from a throwaway Laravel app in the scratchpad that requires the package through a Composer `path` repository pointing at the worktree, with Filament 5 installed, a panel registering `FeedbackBoardPlugin`, the launcher in a plain layout and in the panel via `PanelsRenderHook::BODY_END`, and seeded items (all statuses, priorities, long unbroken titles, comments of every role including an internal note, meta with a URL and user agent).

Capture, at 1280 px and 390 px, in `en` (LTR) and `ar` (RTL):
1. Host page: launcher closed and open; the form's sent state.
2. `/feedback-board/feedback`: "My reports" with the drawer open.
3. `/feedback-board/feedback/board`: board, drag-over highlight, drawer with the reply and internal-note tabs (submit an internal note, confirm the tab state survives the re-render).
4. Filament board page in light and in dark (`.dark`), with the drawer open.
5. Standalone board under an emulated dark color scheme.

Check: no host style bleeding into package controls; launcher in the inline-end corner; drawer on the inline-end edge sliding from it; LTR content (URLs, user agents, diffs) readable in RTL; text contrast; nothing overflowing horizontally at 390 px except the board's own column scroller; drag a card to another column and confirm the status change persists. Fix defects found (in the worktree, with tests where the defect is testable) before the merge.
