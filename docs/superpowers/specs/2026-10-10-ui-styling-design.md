# UI Styling — Design

**Status:** approved (the author delegated all decisions for this cycle and waived approval gates; adversarial review stands in for section approval)
**Date:** 2026-10-10
**Package:** `yousefaman/feedback-board`
**Depends on:** v1.0 release readiness (merged, `b4c1daf`)

## 1. Context, Goals, Scope

The package ships **no CSS**. Every surface — the launcher and its modal, the
submission form, "My reports", the Kanban board, the developer item detail, the
reporter item view, the two standalone pages and the Filament board page — uses
`fb-*` class names that have no styles anywhere. The launcher renders as an inline
unstyled button and the modal as a block in page flow; the Kanban columns stack
vertically; the selected item's detail appears below the board with no way to
close it. The README's description ("a floating button that opens the feedback form
in a modal overlay") is not true today.

This cycle makes the UI presentable and usable before the author dogfoods the
package in real apps.

**Goals**
1. A self-contained stylesheet that the host never builds, compiles, publishes or
   links by hand — it appears on every page that renders a package surface.
2. Correct in LTR and RTL (Arabic is a first-class locale).
3. Light and dark themes that follow the host's dark-mode convention, with a
   config override.
4. Looks native inside a Filament 4/5 panel (follows the panel's dark mode and uses a
   zinc palette matching Filament's default grays; the panel's primary colour is
   opt-in, §3.3) and decent stand-alone.
5. Does not leak styles onto host markup; host can re-brand through CSS custom
   properties without publishing anything.
6. Baseline accessibility: labelled fields, keyboard-operable board, dialogs with
   focus management, visible focus, AA contrast, reduced-motion respect.

**In scope (beyond pure CSS)** — small markup/behavior changes that styling
cannot be done without, each listed in §5: a way to close the item drawer, a
submission confirmation, field labels, keyboard access to cards, the item's
captured context (page URL, browser, locale — required by the MVP spec §6(b) but
never rendered), translated activity/role labels, a priority placeholder, and a
translated Filament page title.

**Out of scope**
- A JavaScript build step, Tailwind, or any front-end dependency beyond the Alpine
  bundled with Livewire.
- Publishing CSS to `public/`, a CDN build, or a `filament:assets` registration.
- Rich-text, markdown rendering, avatars, comment editing, real-time push.
- Touch drag-and-drop (native HTML5 drag-and-drop does not fire on touch; the
  status select in the item drawer is the touch path).
- Restyling the Filament **resource** (table/forms are Filament-native already).
- Pre-publish packaging (`LICENSE`, `CHANGELOG.md`, `.gitattributes`) — next cycle;
  note for it: `.gitattributes` must **not** export-ignore `resources/dist`.

## 2. Asset Delivery

### 2.1 Decision

One hand-written, plain CSS file, `resources/dist/feedback-board.css`, served by
the package through a route and attached to pages with Livewire's `@assets`
directive.

Alternatives considered:
- **Inline `<style>`** (Laravel Pulse style): no route, but ~15 KB per HTML page,
  not cacheable, and blocked by a strict CSP without a nonce.
- **Publish to `public/`** (Filament/Horizon style): an install step that is easy to
  forget and goes stale on every package upgrade unless re-run.
- **Route + `@assets`** (chosen): zero host setup, browser-cacheable with
  content-hash busting, same-origin so `style-src 'self'` works, updates with the
  package. Livewire itself serves `livewire.js` this way.

### 2.2 `@assets` behavior (verified in Livewire 3.8.3, 3.8.10 and 4.x source)

- `@assets … @endassets` captures its content under a compile-time key derived
  from the **compiling view's path** and a per-file counter, and records each key
  once per request.
- On a full-page HTML response (status 200, `text/html`, containing `</html>`),
  `SupportAutoInjectedAssets` injects every recorded asset before `</head>`. This
  happens **independently of `livewire.inject_assets`**, so hosts that disable
  auto-injection and use `@livewireStyles` still get the stylesheet.
- Used outside a Livewire component (plain Blade, e.g. the launcher wrapper), the
  asset is still collected (`nonLivewireAssets`) and injected the same way.
- In Livewire AJAX responses, assets travel in the component's effects and the
  client adds them to `<head>`; a repeated `<link>` to the same URL is harmless
  (served from cache).

- The bookkeeping lives in static properties reset only by `Livewire::flushState()`
  (triggered by Livewire's own test helpers and lifecycle). Package tests therefore
  call `app('livewire')->flushState()` in `TestCase::setUp()`; otherwise "the page
  already has the stylesheet" and even the compile-time key after `view:clear`
  depend on test order. (Octane: the package relies on Livewire's own per-request
  state handling, exactly like Livewire's `@assets`/`@script` — not verified here.)

Therefore the `<link>` lives in **one** partial, `feedback-board::partials.assets`,
included by every surface's root. One file → one key → injected once per page no
matter how many surfaces render.

### 2.3 Partial

`resources/views/partials/assets.blade.php`:

```blade
@assets
<link rel="stylesheet" href="{{ \YousefAman\FeedbackBoard\Support\Ui::stylesheetUrl() }}" @if ($fbNonce = \Illuminate\Support\Facades\Vite::cspNonce()) nonce="{{ $fbNonce }}" @endif>
@endassets
```

The partial outputs nothing in place (the directive buffers and discards), so it
is safe as the first child of a Livewire component's single root element.

### 2.4 Route and controller

`routes/web.php` gains, **outside** the `route_middleware` group (no session, no
cookies, no auth — the file is public and static):

```php
Route::prefix(config('feedback-board.route_prefix', 'feedback-board'))
    ->name('feedback-board.')
    ->get('assets/stylesheet', [AssetController::class, 'css'])
    ->name('assets.css');
```

The path deliberately has **no `.css` extension**: common nginx configs answer
`location ~* \.(css|js)$` from disk and 404 before PHP runs (the trap
`/livewire/livewire.js` is known for). `Content-Type` + `nosniff` identify it.

`src/Http/Controllers/AssetController.php` — `css(Request $request)` returns a plain
`Response` with the file's contents (not `response()->file()`: on Laravel 12.0
`TestResponse` cannot read a `BinaryFileResponse` body) and:
- `Content-Type: text/css; charset=UTF-8`
- `X-Content-Type-Options: nosniff`
- `Cache-Control: public, max-age=31536000, immutable` **only when** the `id` query
  value is a string equal to the current version (`hash_equals`); otherwise
  `Cache-Control: no-cache` (an outdated or missing id must not pin stale content
  for a year). A non-string `id` (e.g. `?id[]=x`) is treated as missing, never cast.

The route takes no path input, so there is no traversal surface.

### 2.5 `Ui` support class

`src/Support/Ui.php` (static helpers, like `RunnerGate`):

| Method | Returns |
|---|---|
| `stylesheetPath(): string` | absolute path of `resources/dist/feedback-board.css` |
| `stylesheetVersion(): string` | first 12 hex chars of `md5_file(stylesheetPath())` — computed per call, not memoized (Octane workers survive package upgrades; hashing ~20 KB costs microseconds) |
| `stylesheetUrl(): string` | `route('feedback-board.assets.css', ['id' => stylesheetVersion()])` — absolute, like Livewire's own script URL (a root-relative `route(..., absolute: false)` strips a sub-directory base path, verified) |
| `theme(): string` | `config('feedback-board.ui.theme')` if it is one of `class`, `system`, `light`, `dark`; otherwise `class` |
| `colorScheme(): string` | `light` / `dark` for the forced themes, otherwise `light dark` — the standalone pages' `<meta name="color-scheme">` |
| `safeUrl(mixed $url): ?string` | the string unchanged when its scheme (case-insensitive) is `http` or `https`, else `null` |

## 3. Scoping, Tokens, Theming, Direction

### 3.1 Scope root

Every surface root element carries:

```blade
class="fb-scope …" data-fb-theme="{{ \YousefAman\FeedbackBoard\Support\Ui::theme() }}" dir="{{ __('feedback-board::feedback-board.dir') }}"
```

and includes the assets partial as its first child. Surfaces nest (the detail
inside the Kanban drawer, the form inside the launcher, the reporter view inside
"My reports"); every nested root re-declares the same tokens, which is harmless.

The root class is `fb-scope`, not `fb`: a bare `fb` (and Facebook-SDK names such as
`fb-page`, `fb-post`, `fb-comments`, `fb-like`, `fb-video`, `fb-group`, `fb-quote`,
`fb-save`, `fb-send`, `fb-follow`) are plausible in host markup. No package class
may use those names.

### 3.2 Selector rules

- Every selector starts with `.fb-` (a package class), `.dark .fb-` (the
  class-strategy dark mode hook) or `.fi .fb-` (Filament's `<html class="fi">`, used
  only to lower the stacking base inside panels). No bare element, `:root`, `html`, `body` or `*`
  selectors at top level — the stylesheet never styles host markup.
- Element styling happens through package classes on the elements, plus a scoped
  normalisation (`.fb-scope button, .fb-scope input, …`) that sets
  `font: inherit; color: inherit` and `box-sizing: border-box`.
- Specificity stays low (one or two classes) so hosts can override with a single
  class or a custom property. **No `@layer`**: layered rules lose to every unlayered
  host rule, including Tailwind preflight's element resets, which would undo the
  package's button and input styles.
- No `!important`, no `@import`, no `url(…)` (icons are inline SVG in the markup).

### 3.3 Design tokens

**Public tokens** a host may set — anywhere (`:root`, `.fb-scope`, a dark-mode
selector, any `@layer`):

| Token | Default | Purpose |
|---|---|---|
| `--fb-accent` | `#4f46e5` | primary buttons, launcher, reporter-comment accent |
| `--fb-accent-hover` | `#4338ca` | primary hover |
| `--fb-accent-contrast` | `#fff` | text on accent |
| `--fb-radius` | `0.75rem` | panels, modal, drawer |
| `--fb-font` | inherit the host font | `font-family` |
| `--fb-z` | `1000`; `35` inside a Filament panel | launcher stacking; modal and drawer sit at `+10` |

**The package never declares a public token.** The injected `<link>` always comes
after every host stylesheet (verified on both Livewire majors and in Filament), so a
package declaration of `--fb-accent` on `.fb-scope` would win every tie, beat
layered host rules outright and reset outer overrides on each nested scope. Instead
`.fb-scope` reads each public token with its default into a private alias
(`--_fb-accent: var(--fb-accent, #4f46e5)`), and every rule uses only `--_fb-*`
tokens. Private tokens (background, surface, borders, text, link, ring, shadows,
status pairs, internal-note tint) are documented as internal.

**No Filament variables are read.** Filament's default primary is amber; amber-600
under white text is 3.19:1 (fails AA), and Filament picks button text colours per
color in PHP, which CSS cannot replicate. A non-Filament host may also define
`--primary-600`/`--gray-*` in the Tailwind 3 channel format, which would yield an
invalid, transparent color. The fallback palette is zinc — Filament's default gray —
so the board matches default panels; the README shows how to opt into the panel's
primary color, with the contrast caveat. In the light theme, links and focus rings
also follow `--fb-accent`; the dark theme keeps its fixed link colour (a host accent
is usually too dark for text on dark surfaces) but focus rings follow the accent.

**Stacking.** Filament: top bar 30, modals 40/50, notifications 50. With `--fb-z`
35 inside panels (`.fi .fb-scope`), the launcher sits above the top bar and below
Filament modals, and the package modal/drawer (45) sit above the sidebar and below
notifications. Outside panels the base is 1000 (launcher) / 1010 (overlays). The
drawer and modal backdrops always cover the launcher, so a second dialog cannot be
opened on top of an open one.

Contrast requirement: body text, muted text, badge text and button text meet WCAG
AA (4.5:1) against the surface they sit on, in both themes. (Muted text uses
zinc-600 in light, zinc-400 in dark.)

### 3.4 Dark mode

New config key `feedback-board.ui.theme` (env `FEEDBACK_BOARD_THEME`), default
`class`, rendered into `data-fb-theme` on every scope root:

| Value | Dark when |
|---|---|
| `class` (default) | an ancestor has class `dark` (Filament panels, Laravel's Livewire/Flux starter kits, Tailwind class strategy) |
| `system` | `prefers-color-scheme: dark` |
| `light` / `dark` | never / always |

Rationale for `class` as default: it is never wrong in class-strategy apps
(including every Filament panel), and in a light-only app it never forces a dark
widget onto a light page. The only mismatch is a media-strategy app with a dark
OS, which gets a readable light widget and can set `system`.

The package's **standalone pages** have no host to set `.dark`, so there `class`
behaves as `system`: the page `<body>` carries `fb-standalone`, and the CSS applies
the dark tokens to `.fb-standalone[data-fb-theme="class"]` and
`.fb-standalone [data-fb-theme="class"]` inside the dark media query.

Dark tokens are therefore declared under two selector lists (one outside, one
inside `@media (prefers-color-scheme: dark)`) — the token block is duplicated by
necessity; a guard test (§9.2) asserts the two blocks declare identical values.
Each theme also sets `color-scheme` (`light` / `dark`) so native controls, select
popups and scrollbars match.

Under a forced or `system` theme the package surface can differ from the host page,
so the outermost surfaces that otherwise sit directly on the host background ("My
reports" and the Kanban root) paint their own background and padding whenever
`data-fb-theme` is not `class`.

### 3.5 Direction

All spacing, borders, positioning and alignment use logical properties
(`margin-inline-start`, `padding-inline`, `inset-inline-end`, `border-inline-start`,
`text-align: start`), so the `dir` attribute alone flips the layout. The launcher
root now carries `dir` from the package locale (it previously inherited the host
page's direction): the floating button sits at the inline-end bottom corner
(bottom-right in LTR, bottom-left in RTL), and the drawers slide from the
inline-end edge. Directional icons are not used.

**Mixed-direction content.** Feedback is often English inside the Arabic UI and
vice versa. Every element holding user-written text (titles, descriptions, comment
bodies, the AI summary) and every free-text field carries `dir="auto"`, so its
direction follows its content; while a `dir="auto"` field is empty (placeholder
shown) it keeps the interface direction. Captured technical values (page URL, user
agent, locale) are wrapped in `dir="ltr"` inside the RTL layout. (Found in the
browser pass: without this, "How do I reset my password?" rendered as
"?How do I reset my password" in Arabic.)

### 3.6 Motion

Short CSS enter animations (≤ 200 ms) on the modal and drawer, and colour/border
transitions on controls; all are disabled under `prefers-reduced-motion: reduce`.
The modal fades with a CSS animation, not Alpine's `x-transition`, whose inline
styles a stylesheet cannot override without `!important`.

### 3.7 Browser support

Browsers released since 2022: Chrome/Edge ≥ 105, Firefox ≥ 110, Safari ≥ 15.4
(logical properties, `:focus-visible`, `gap`, `::file-selector-button`, `inset`).
No `:has()`, no CSS nesting, no `color-mix()`.

## 4. Surfaces

Markup changes are limited to classes, labels, ARIA attributes, Alpine bindings
and the additions in §5. All dynamic text stays escaped (`{{ }}`); no `{!! !!}`
is introduced. Existing behavior hooks are preserved verbatim: `wire:submit`,
`wire:click`, `wire:change`, `wire:model`, the literal `wire:poll.5s` attributes on
the triage and runner blocks (tests assert on that exact markup), and the
drag-and-drop data contract (`dataTransfer` key `id`, `$wire.moveTo(id, status)`).

### 4.1 Launcher and modal (`components/launcher.blade.php`)

- Root: `fb-scope`, Alpine `x-data="{ open: false }"`,
  `x-on:keydown.escape.window="open = false"`. Escape listens on `window`: when a
  re-render removes the focused element (the submit button replaced by the sent
  panel), focus falls back to `<body>` and a listener on the panel would never fire
  (found in the browser pass). Only one package dialog can be open at a time, since
  every backdrop covers the launcher. Opening dispatches the
  browser event `fb-launcher-opened`, on which a form in the sent state resets
  itself (`$wire.sent && $wire.set('sent', false)`) so a reopened launcher starts
  fresh.
- Button `fb-launcher`: fixed at the inline-end bottom corner (1.25 rem inset),
  pill shape, accent background, inline SVG speech-bubble icon (`aria-hidden`)
  plus the label; below 40 rem viewport width the label is visually hidden
  (`fb-sr-only` behavior via media query) and the button becomes a 3.25 rem
  circle. `aria-haspopup="dialog"`, `:aria-expanded="open"`.
- Modal `fb-modal`: fixed full-viewport backdrop (semi-transparent), `x-show`,
  `x-cloak` (Livewire's own styles hide `[x-cloak]`), the CSS fade (§3.6),
  click on the backdrop closes (`@click.self`).
- Panel `fb-modal__panel`: `role="dialog"`, `aria-modal="true"`,
  `aria-labelledby="fb-launcher-title"`, `x-trap.noscroll="open"` (focus trap and
  body scroll lock — the Alpine Focus plugin is bundled with Livewire 3 and 4),
  width `min(32rem, 100% - 2rem)`, max-height `100% - 2rem` with internal scroll;
  header with title `fb-modal__title` (the launcher label) and an icon close button
  `fb-icon-btn` with `aria-label` = `close`. On viewports below 40 rem the panel
  becomes a bottom sheet (full width, top corners rounded).

### 4.2 Submission form (`livewire/feedback-form.blade.php`)

- Root `fb-scope fb-form`.
- Fields use implicit labels (`<label class="fb-field"><span class="fb-label">…`)
  so two forms on one page (launcher + "My reports") never produce duplicate ids:
  Title (`fb-input`), Type (`fb-select`, labelled `form.type`), Description
  (`fb-textarea`, 5 rows), Attachments (`fb-file`, `multiple`) with a hint
  `fb-hint` built from config: `form.attachments_hint` with `:types` = the allowed
  extensions upper-cased and comma-separated, `:size` = `max_kb / 1024` rounded to
  one decimal place without a trailing `.0`.
- The size in the hint is the smaller of `attachments.max_kb` and PHP's
  `upload_max_filesize` (which rejects larger files before validation).
- After a successful submit the sent panel (`role="status"`, `tabindex="-1"`) takes
  focus via `x-init="$nextTick(() => $el.focus())"`, so focus never falls to `<body>`
  inside the modal and the confirmation is announced.
- Upload progress: `<span wire:loading wire:target="files" class="fb-hint">`
  `form.uploading`.
- Errors: `fb-error` under Title, Type and Description and, new, under Attachments
  the first `files` error (Livewire reports upload failures under `files`) or else
  the first `files.*` error.
- Never set `display` on an element that carries `wire:loading`: Livewire hides it
  with a `[wire\:loading][wire\:loading]` (0,2,0) rule that a package rule could
  otherwise override.
- Submit `fb-btn fb-btn--primary`, `wire:loading.attr="disabled"`
  `wire:target="submit,files"`.
- **Sent state** (§5.2): when `$sent` is true the fields are replaced by a success
  panel `fb-form__success` (`role="status"`, check icon, `form.sent_title`,
  `form.sent_body`, a link `form.view_reports` to `route('feedback-board.page')`,
  and a `fb-btn` button `form.send_another` with `wire:click="$set('sent', false)"`).

### 4.3 "My reports" (`livewire/my-reports-board.blade.php`)

- Root `fb-scope fb-reports`, `x-data`.
- Header `fb-reports__title` (h2, `board.my_reports`).
- Layout `fb-reports__layout`: one column; from 64 rem two columns — compose card
  (`fb-panel`, heading `board.new_report`, the form) at inline-start
  (`minmax(0, 24rem)`), the list at inline-end.
- List `fb-list`: each item is a `<button type="button" class="fb-list__item"
  wire:click="open(id)">` (replaces the clickable `<li>` and its inline
  `style="cursor:pointer"`) showing the title, the status badge
  `fb-badge fb-badge--{status color}` and a relative time
  `<time datetime="ISO-8601">diffForHumans</time>`.
- Empty state `fb-empty`: `board.empty_reports`.
- Selected item → **drawer** (§4.6) containing the reporter item view.

### 4.4 Reporter item view (`livewire/feedback-item-modal.blade.php`)

- Root `fb-scope fb-item`.
- Header: title (h2 `fb-detail__title`, with `id="fb-drawer-title"`), status badge,
  relative time; description `fb-prose` (`white-space: pre-line`, preserves the
  reporter's line breaks; `overflow-wrap: anywhere`).
- Thread `fb-thread` of comments (§4.7), then the reply composer (`fb-textarea` +
  `fb-btn--primary`), placeholder `board.reply`.

### 4.5 Kanban (`livewire/feedback-kanban.blade.php`)

- Root `fb-scope fb-kanban`, keeps `x-data x-on:dragover.prevent=""`.
- Columns container `fb-kanban__columns`: horizontal grid
  (`grid-auto-flow: column; grid-auto-columns: minmax(16rem, 1fr)`), horizontal
  scroll when narrow, `scroll-snap-type: x proximity` with columns snapping to
  start.
- Column `fb-col` (`data-status` kept), Alpine `x-data="{ over: 0 }"`,
  `dragenter`/`dragleave` count only drags whose `dataTransfer.types` include `id`
  (a dragged file or text never highlights a column), and the drop handler calls
  `$wire.moveTo(id, '<status>')` only when `getData('id')` is non-empty (an empty id
  would hit `moveTo(int $itemId)` with `''`, a TypeError Livewire shows as a
  "page expired" dialog),
  `:class="{ 'fb-col--over': over > 0 }"` (highlighted drop target).
- Column header `fb-col__header`: a status dot `fb-dot fb-dot--{status color}`,
  the label (h3 `fb-col__title`), a count `fb-col__count`.
- Column body `fb-col__body`: cards, or `fb-col__empty` with `board.empty_column`
  (dashed placeholder that still receives drops).
- Card `fb-card` (`wire:key`, `draggable="true"` kept): `role="button"`,
  `tabindex="0"`, `wire:click="open(id)"`, Enter/Space open via `$wire.open(id)`;
  Alpine `x-data="{ dragging: false }"` toggling `fb-card--dragging` (reduced
  opacity) between `dragstart` and `dragend`; `dragstart` also sets
  `effectAllowed = 'move'`. Content: title `fb-card__title` (clamped to 3 lines),
  meta row `fb-card__meta` with type chip `fb-chip`, priority badge
  `fb-badge fb-badge--{priority color}` when set, relative `<time>`.
- Selected item → **drawer** (§4.6) containing the developer detail.

### 4.6 Drawer (shared markup in the Kanban and "My reports" views)

```
div.fb-drawer  (x-data, x-on:keydown.escape.window="$wire.close()")
  div.fb-drawer__backdrop  (wire:click="close")
  div.fb-drawer__panel  role=dialog aria-modal=true aria-labelledby="fb-drawer-title" x-trap.noscroll="true"
    div.fb-drawer__bar
      button.fb-icon-btn  (wire:click="close", aria-label=close)
    div.fb-drawer__body  (the only scrolling part, so the close button never scrolls away)
      @livewire(child, key)
```

- Fixed, full height, anchored to the inline-end edge, width `min(40rem, 100%)`,
  internal scroll; `z-index: calc(var(--_fb-z) + 10)` (§3.3).
- `x-trap` releases focus and restores scrolling on cleanup when Livewire removes
  the drawer (verified: Alpine Focus registers `cleanup(releaseFocus)`), and
  returns focus to the previously focused card.
- Slide-in transition from the inline-end edge (disabled under reduced motion).

### 4.7 Developer detail (`livewire/feedback-detail.blade.php`)

Order:
1. Header: title (h2 `fb-detail__title`, `id="fb-drawer-title"`), meta line
   `fb-detail__meta`: type chip, reporter name, relative creation time.
   Reporter name = `$item->reporter_name ?: $item->reporter_email` (the snapshot the
   item stores at creation, also used by Slack, the webhook and the Filament table —
   the `reporter` morph is null for ingested items and deleted users), omitted when
   empty. Assignee = the assignee model's `name`, else `email`, else `#id`.
2. Description `fb-prose`.
3. Controls `fb-detail__controls` (wraps): labelled Status select, labelled
   Priority select with a first `<option value="" disabled>` = `board.no_priority`
   selected when the item has no priority (today the first real option is shown
   though none is set), assignee text (`board.assignee`: name, or
   `board.unassigned`) and the existing assign/unassign button (`fb-btn`).
4. Context `fb-context` (`board.context`; a `<dl>`, rendered only for present keys
   of `$item->meta`): Page (`meta.url` — a link with
   `target="_blank" rel="noopener noreferrer"` **only** when `Ui::safeUrl()` accepts
   it, otherwise plain text; the referer header is client-controlled and a
   `javascript:` URL must never become an `href`), Browser (`meta.user_agent`,
   small, wraps anywhere), Language (`meta.locale`).
5. Attachments `fb-attachments` (section omitted when empty), links unchanged.
6. Triage block `fb-panel fb-triage` and runner block `fb-panel fb-runner` —
   logic unchanged except that the apply buttons show the translated
   `FeedbackType`/`FeedbackPriority` label of the suggestion (falling back to the
   raw value) instead of the raw enum value; buttons get `fb-btn fb-btn--sm`; the "ingest disabled"
   warning becomes `fb-notice fb-notice--warning`; the diff `<pre>` gets
   `fb-pre` (monospace, horizontal scroll, max-height 20 rem).
7. Thread `fb-thread`: comments `fb-comment fb-comment--{role}` plus
   `fb-comment--internal`; head `fb-comment__head` with role label
   (`AuthorRole::label()`), an `fb-badge fb-badge--warning` `board.internal` badge
   on internal notes, relative time; body `fb-prose`. Reporter comments carry an
   inline-start accent border, developer comments a neutral one, AI an info one;
   internal notes a warning-tinted background.
8. Composer `fb-composer`: two toggle buttons (Alpine `x-data="{ tab: 'reply' }"`,
   `aria-pressed`) — Reply and Internal note — each showing its existing form
   unchanged in behavior (`wire:submit`, `wire:model`, `@error`). Toggle buttons,
   not ARIA tabs: a tablist without arrow-key focus management is worse than none.
   The internal-note panel uses the warning tint.
9. Activity `fb-activity`: each entry shows `FeedbackActivity::summary()` — the
   action label; for status changes `: ` + the new status label; for an assignment
   to nobody (`AssignFeedback` logs unassignment as `Assigned` with
   `assignee_id: null`) the `activity.unassigned` label — and a relative `<time>`.
   The text is one `{{ }}` expression: Livewire wraps `@if` blocks in morph-marker
   comments, which would split it.

### 4.8 Standalone pages (`pages/board.blade.php`, `pages/feedback.blade.php`)

`<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="…">`,
`<meta name="viewport" content="width=device-width, initial-scale=1">`,
`<meta name="color-scheme" content="{{ Ui::colorScheme() }}">`, title,
`@livewireStyles`; `<body class="fb-scope fb-standalone" data-fb-theme="…">`
including the assets partial; content inside `<main class="fb-standalone__main">`
(max-width 72 rem for the reports page, full width for the board, page padding).
The board page adds `<h1 class="fb-standalone__title">` (`board.title`); the
reports page relies on the component's own heading.

### 4.9 Filament

- Board page: no markup change beyond what the Kanban brings; dark mode follows
  Filament's `.dark` class; the palette is the zinc fallback and the accent stays indigo unless the host
  opts in (§3.3) — no Filament variables are read.
  `FeedbackBoardPage` returns the translated `board.title` from `getTitle()` and
  `getNavigationLabel()` (replacing the hard-coded English label), and sets
  `$maxContentWidth = Width::Full` (six 16 rem columns never fit the default 7xl
  content width).
- `filament/runner-result` and `filament/triage-suggestion` (rendered inside
  Filament action modals) get `fb-scope` roots with the partial and `fb-pre` on the
  diff.

## 5. Behavior Changes

1. **Close the drawer**: `FeedbackKanban::close()` and `MyReportsBoard::close()` set
   `selectedId = null`.
2. **Submission confirmation**: `FeedbackForm` gains `public bool $sent = false`;
   `submit()` sets it to `false` before validation and `true` after the item and
   attachments are stored (on validation failure it stays `false`).
3. **Labels**: `FeedbackActivity::summary()` (§4.7); `FeedbackPriority::color()` (`low`→`gray`, `medium`→`info`,
   `high`→`warning`, `urgent`→`danger`), `ActivityAction::label()` and
   `AuthorRole::label()` (translation keys `activity.*`, `role.*`), mirroring
   `FeedbackStatus::label()/color()`.
4. **Filament page title** (§4.9).

None changes authorization: `close()` only clears local selection; every write
keeps its existing guard.

## 6. Translations

New keys, in both `en` and `ar` (Arabic in parentheses):

- `close` — Close (إغلاق)
- `form.type` — Type (النوع); `form.attachments` — Attachments (المرفقات);
  `form.attachments_hint` — "Allowed: :types · up to :size MB each"
  ("المسموح: :types · حتى :size ميغابايت للملف"); `form.uploading` — Uploading…
  (جارٍ الرفع…); `form.sent_title` — Thanks — your report was sent
  (شكرًا لك — تم إرسال بلاغك); `form.sent_body` — We'll keep you posted on its
  progress. (سنوافيك بتطورات بلاغك.); `form.view_reports` — View my reports
  (عرض بلاغاتي); `form.send_another` — Send another report (إرسال بلاغ آخر)
- `board.new_report` — New report (بلاغ جديد); `board.empty_reports` — You haven't
  sent any reports yet. (لم ترسل أي بلاغات بعد.); `board.empty_column` — No items
  (لا توجد عناصر); `board.status` — Status (الحالة); `board.priority` — Priority
  (الأولوية); `board.no_priority` — No priority (بلا أولوية); `board.assignee` —
  Assignee (المسؤول); `board.unassigned` — Unassigned (غير مُسنَد);
  `board.context` — Context (السياق); `board.page_url` — Page (الصفحة);
  `board.browser` — Browser (المتصفح); `board.locale` — Language (اللغة);
  `board.internal` — Internal (داخلي)
- `role.reporter` — Reporter (المُبلِّغ); `role.developer` — Support team
  (فريق الدعم); `role.ai` — AI assistant (المساعد الذكي)
- `activity.created` — Created (أُنشئ); `activity.status_changed` — Status changed
  (تغيّرت الحالة); `activity.replied` — Replied (رُدّ عليه); `activity.assigned` —
  Assigned (أُسند); `activity.unassigned` — Unassigned (أُلغي الإسناد)

A parity test asserts `en` and `ar` define exactly the same key set.

## 7. Security

- No new write paths; `close()` and `$sent` touch only component-local state.
- `meta.url` is attacker-controllable (Referer header of the Livewire request):
  linked only through `Ui::safeUrl()`; otherwise rendered as escaped text. The
  runner `pr_url` link is already sanitized at ingest (`RunnerIngest::safePrUrl`).
- All output escaped; the CSS endpoint reads a fixed file, ignores input except a
  string comparison of `id`, and needs no session.
- Inline SVG icons are static markup in the views (no user data).

## 8. Documentation

README:
- "Add the launcher" — describe the actual floating button and modal; state that
  styles load automatically (no build step, no publish).
- New section **"Styling & theming"**: automatic stylesheet; `ui.theme` values and
  the standalone-page behavior; the public tokens table (§3.3) with an override
  example (`.fb-scope { --fb-accent: #0d9488; }` in the host CSS); RTL via the
  locale's `dir`; CSP note (`style-src 'self'` suffices; the `<link>` carries the
  Vite CSP nonce when one is set); a manual-include note for hosts that render a
  surface inside a response Livewire cannot inject into (non-200 or non-HTML
  responses): `@include('feedback-board::partials.assets')` is not enough there —
  link `Ui::stylesheetUrl()` directly.
- Configuration table: `ui.theme` row.

## 9. Testing

### 9.1 Feature tests

0. **Isolation**: `TestCase::setUp()` flushes Livewire state (§2.2); a page with no
   package surface carries no stylesheet link.
1. **Asset route**: guest `GET` returns 200, `text/css; charset=UTF-8`, `nosniff`,
   body equal to the file; `immutable` cache only with the current `id`; `no-cache`
   with a stale or missing id; `?id[]=x` returns 200 with `no-cache` (no 500); the
   route carries no `auth`/`web` middleware.
2. **Ui helpers**: theme normalization (valid values pass, `'blue'`, `null`, `1` →
   `class`); `safeUrl` accepts `http`/`https` (any case), rejects `javascript:`,
   `data:`, `//host`, relative paths, non-strings; `stylesheetUrl()` is absolute,
   keeps a sub-directory base path (`url()->forceRootUrl('http://host.test/sub')`)
   and carries the current version; `colorScheme()` mapping.
3. **Head injection**: as an authenticated developer, `GET` the standalone board
   page and the reports page → the response contains exactly one
   `<link rel="stylesheet" href="http://localhost/feedback-board/assets/stylesheet?id=…">`
   and it appears before `</head>`; a host page rendering only
   `<x-feedback-board::launcher />` inside a full HTML document (test route)
   also gets exactly one link in `<head>`.
4. **Surfaces**: each root renders `fb-scope`, `data-fb-theme` (config `dark` →
   `dark`) and `dir` (`ar` → `rtl`, including the launcher root).
5. **Behavior**: `close()` clears selection on Kanban and "My reports"; `$sent`
   flips true after a valid submit, stays false after a failed one, success panel
   text visible, `$set('sent', false)` restores the fields; priority placeholder
   selected when priority is null; context section renders `meta.url` as a link
   for `https://…` and as text (no `href="javascript:`) for `javascript:alert(1)`;
   activity shows translated label and target status; comment role labels and the
   internal badge render; empty states render.
6. **Enums**: `FeedbackPriority::color()` mapping; `label()` on `ActivityAction`
   and `AuthorRole` resolve translations in both locales.
7. **Filament**: board page renders the stylesheet link in `<head>` and the
   translated title; existing smoke tests stay green.
8. **Translations**: `en`/`ar` key parity.
9. Existing suite green (including `view:cache`, the literal `wire:poll.5s`
   assertions and Livewire 3/4 registration).

### 9.2 Stylesheet guard tests

Parsing the CSS file (comments stripped):
1. **Scoping**: every selector in every rule (split on commas, at-rule wrappers
   and `@keyframes` bodies excluded) starts with `.fb-`, `.dark .fb-` or `.fi .fb-`.
2. **Self-contained**: no `@import`, `url(`, `!important`, `@layer`.
3. **Tokens**: every `var(--_fb-…)` reference names a private token declared in
   the file; no public `--fb-…` token is ever declared, and each is read only with
   a fallback; the Facebook-SDK class names never appear (CSS and views).
4. **Dark parity**: the dark token declarations inside and outside the media
   query are identical, and dark mode overrides only tokens the light theme
   declares.
5. **Coverage**: every static `fb-…` class used in `resources/views*` has at least
   one rule; dynamic variants (`fb-badge--{color}`, `fb-dot--{color}`,
   `fb-comment--{role}`) have a rule for every value the enums can produce.

### 9.3 Visual verification (author's proxy)

After implementation, render every surface in a real browser (a throwaway Laravel
app in the scratchpad with the package via a Composer path repository, Filament
installed, seeded items) with Playwright, and screenshot: launcher closed/open,
form sent state, "My reports" with drawer, standalone board with drawer,
Filament board light and dark; each in `en`/LTR and `ar`/RTL, at desktop and
390 px widths. Defects found are fixed before merge. Browser-side drag-and-drop
is exercised once (drag a card to another column, verify the status change).

### 9.4 Pre-execution results (2026-10-10)

The plan was dry-run end to end in a scratch copy (346 tests green on Livewire
4.4.7/Filament 5.10.1, Livewire 3.8.10/Filament 4.15.1, and the floors Livewire
3.8.3/Filament 4.0.0/Laravel 12.0.1) and rendered in Chromium in `en`/`ar`,
light/dark, 1280/390 px, against Tailwind, Bootstrap and a Filament 5 panel. The
browser pass produced the Escape, drawer-bar, CSS-fade, forced-theme background and
`dir="auto"` decisions above; drag-and-drop moved a card and persisted its status.

## 10. Files

| File | Change |
|---|---|
| `resources/dist/feedback-board.css` | new — the stylesheet |
| `resources/views/partials/assets.blade.php` | new — `@assets` link |
| `src/Support/Ui.php` | new — §2.5 |
| `src/Http/Controllers/AssetController.php` | new — §2.4 |
| `routes/web.php` | asset route |
| `config/feedback-board.php` | `ui.theme` |
| `resources/views/components/launcher.blade.php` | §4.1 |
| `resources/views/livewire/*.blade.php` | §4.2–§4.7 |
| `resources/views/pages/*.blade.php` | §4.8 |
| `resources/views/filament/*.blade.php` | §4.9 |
| `src/Livewire/FeedbackKanban.php`, `MyReportsBoard.php`, `FeedbackForm.php` | §5 |
| `src/Enums/FeedbackPriority.php`, `ActivityAction.php`, `AuthorRole.php` | §5.3 |
| `src/Filament/Pages/FeedbackBoardPage.php` | §4.9 |
| `resources/lang/{en,ar}/feedback-board.php` | §6 |
| `README.md` | §8 |
| `tests/…` | §9 |
