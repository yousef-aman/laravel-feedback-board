![Feedback Board: in-app bug reports with screenshots, a developer Kanban board and two-way replies, for Laravel and Filament](https://raw.githubusercontent.com/yousef-aman/laravel-feedback-board/main/.github/banner.png)

# Feedback Board

A self-hosted, embeddable feedback widget for Laravel applications. Clients submit bug reports and feature requests inside their own session — your data stays on your infrastructure, processed by your own runner.

![Demo: a user reports a bug with a pasted screenshot, a developer drags the card on the Kanban board and replies, and the user reads the reply in My reports](https://raw.githubusercontent.com/yousef-aman/laravel-feedback-board/main/.github/demo.gif)

## Positioning

Feedback Board is self-hosted by design. Reports are captured inside the authenticated client's session (no third-party script, no external SaaS), stored in your own database, and processed through your own notification and AI pipelines. You own the data, the hosting, and the runner. Optional destination drivers (webhook, Slack, GitHub Issues), AI triage and an AI runner contract plug into the same pipeline. All of them are off until you enable them, and each one you enable sends data to the service it names (AI triage sends only a report's title and description to the model provider you choose — see [AI Triage](#ai-triage-level-a)). A multi-tenant developer hub is planned — see the [Roadmap](#roadmap).

## Requirements

- PHP 8.2+ (8.3+ for Laravel 13)
- Laravel 12 or 13
- Livewire 3.8.3+ or 4.3.4+
- Filament 4 or 5 (optional, for the developer Kanban board)

## Installation

```bash
composer require yousef-aman/laravel-feedback-board
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

## Usage

### 1. Add the launcher to your layout

Add the `<x-feedback-board::launcher/>` Blade component to your authenticated layout (e.g. `resources/views/layouts/app.blade.php`):

```html
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ __('feedback-board::feedback-board.dir') }}">
<head>
    ...
</head>
<body>
    ...

    {{ $slot }}

    <x-feedback-board::launcher />
</body>
</html>
```

The launcher renders a floating button in the bottom corner of the page (bottom-right in left-to-right locales, bottom-left in right-to-left ones) that opens the report form in a panel sliding in from the side; reporters can add screenshots by picking, dropping or pasting them. It renders for a signed-in user who may report. A user who may not report but has earlier reports gets a "My reports" link in its place, and a guest gets nothing, so a layout that also serves a login page needs no check of its own. See [Choosing who can report](#choosing-who-can-report), and [Reporters on another guard](#reporters-on-another-guard) when reporters sign in with a guard other than the default. Its styles load automatically — there is nothing to build, publish or link (see [Styling & theming](#styling--theming)).

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

New reports, and replies from reporters, notify your developers. List their e-mail addresses in `.env`, separated by commas:

```dotenv
FEEDBACK_BOARD_DEVELOPER_EMAIL=dev@example.com,lead@example.com
```

or in the config file:

```php
// config/feedback-board.php
'notifications' => [
    'developers' => ['dev@example.com', 'lead@example.com'],
],
```

Entries that Laravel's `email` validation rule rejects are skipped, with a warning in your log. Addresses such as `dev@localhost`, for a local mail catcher like Mailpit, are accepted.

To notify user models instead (for example, to also use Filament's in-app bell), register them in `AppServiceProvider::boot()`. This takes precedence over the config e-mails:

```php
FeedbackBoard::notifyDevelopersUsing(fn () => \App\Models\User::where('is_admin', true)->get());
```

### 4. Notification channels

By default, notifications go out by `mail` only. To also store in-app (database) notifications:

```bash
php artisan make:notifications-table
php artisan migrate
```

Then add `'database'` to `notifications.channels` in `config/feedback-board.php`. To show the bell in a Filament panel, also call `->databaseNotifications()` on that panel. Recipients that are only e-mail addresses always receive mail only.

Notifications are sent, and destination deliveries (webhook, Slack, GitHub) queued, only after the report, reply or status change is saved. If sending or queueing fails (for example, the mail server is down, or a Slack outage on the `sync` queue), the failure is reported to your exception handler and the user's action is still kept.

**Who is told what.** A new report and a reporter's reply notify your developers; the reply's notification names the report (`Reporter replied: {title}`, with its number). A developer's or the AI's public reply, and the Resolved status, notify the reporter, and so does an open report moved to Closed or Declined; filing a finished report away (for example Resolved to Closed) sends nothing. Text a reporter wrote is escaped in e-mails (Markdown), the Filament bell (HTML) and Slack (`<`, `>` and `&`); the webhook payload and GitHub issues and comments carry it unchanged. In the plain-text part of an e-mail, the escaping shows as a backslash before characters such as `[` or `*`. The new-report database notification's `feedback_title` key holds the raw report title.

**Links to the report.** Each e-mail has a "View report" button, with the link written out under it, and so does each Filament bell entry. The reporter's button opens "My reports" on the report; a developer's opens the board on the report. Database payloads also carry the link as `feedback_url`.

The link goes to the first Filament panel, the default panel first, that has the plugin, no tenancy, and a guard the package recognises. For the reporter that is a panel whose guard's model is the reporter's, with "My reports" on; for a developer, a panel on a developer guard. A notified user must also be of that guard's model and, as a `FilamentUser`, allowed into the panel (`canAccessPanel()`). When no panel fits, the link goes to the standalone pages.

To build the links yourself, register a callback in `AppServiceProvider::boot()`. It gets the report, the audience (`'reporter'` or `'developer'`) and the notified model (`null` for an e-mail address). Only an absolute `http`/`https` URL is used; `null`, or anything else, leaves the button out, and a callback that throws is reported once and leaves it out:

```php
use YousefAman\FeedbackBoard\Facades\FeedbackBoard;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

FeedbackBoard::linkReportsUsing(fn (FeedbackItem $item, string $audience, ?object $recipient) => $audience === 'developer'
    ? route('admin.feedback', ['report' => $item->id])
    : route('portal.reports', ['report' => $item->id]));
```

### Choosing who can report

Every signed-in user may report until you say otherwise. Register a resolver in `AppServiceProvider::boot()`:

```php
FeedbackBoard::resolveReportersUsing(fn ($user) => $user->can_send_reports);
```

Or, in the config file, point `authorization.reporter_resolver` at a public static method (a closure there breaks `config:cache`):

```php
'reporter_resolver' => [\App\Support\ReporterCheck::class, 'handle'],
```

A user who may not report keeps their earlier reports:

| | May report | May not report, has reports | May not report, no reports |
|---|---|---|---|
| Launcher | button that opens the report form | "My reports" link | nothing |
| "My reports" (panel page and standalone page) | yes, with "New report" | yes, without "New report" | hidden, and 403 |
| Sending a report | yes | 403 | 403 |
| Opening their reports and replying, with images | yes | yes | — |

`FeedbackBoard::canReport($user)` answers through the `feedback-board.report` gate, so `Gate::before()` hooks apply, and a guest is never a reporter. A `Gate::before()` hook that returns `false` for abilities it does not know therefore hides the launcher. A resolver returns a boolean; `1` and `'1'` also allow, and `0`, `'0'` and `null` also deny. Any other value (another string such as `'N'` or `'false'`, another number, an object or an array) denies and is reported to your exception handler once, and so does a resolver that throws; it never breaks the page. A count is not a boolean: return `->exists()`, not `->count()`. The same holds for the developer resolver.

## Filament Integration

If you are using Filament, register the plugin in your Panel provider:

```php
use YousefAman\FeedbackBoard\Filament\FeedbackBoardPlugin;

$panel->plugin(FeedbackBoardPlugin::make());
```

This adds three entries to the panel's navigation, in a translated **Feedback** group:

| Entry | Who sees it | Badge |
|---|---|---|
| **My reports** (`/admin/my-feedback`) | every panel user who may report, or has reports | reports with unread replies |
| **Feedback board** (`/admin/feedback-board-page`) | developers | open reports |
| **All reports** (the feedback items resource) | developers | — |

Reporters follow their reports and reply, with images, without leaving the panel; developers triage on the Kanban and work each report in a card dialog. To keep "My reports" out of a panel, such as an admin-only one, turn it off for that panel:

```php
$panel->plugin(FeedbackBoardPlugin::make()->myReports(false));
```

**Colours.** Inside a panel the package follows the panel's primary colour, in light and dark mode, with readable text on it (white, or the colour's darkest shade for a light primary such as amber). Your own `--fb-accent` properties still win.

**Tenant panels.** On a panel with `->tenant(...)`, the package's pages live under `/{panel}/{tenant}/…`, and reports are not scoped to a tenant: developers see every report whatever the tenant, while each reporter still sees only their own. Developer access is app-wide too, so a developer resolver that grants per-tenant admins lets them read the reports of every tenant. On a page of the panel that has no tenant (the profile page, tenant registration), the launcher links the standalone "My reports" page.

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

The hook also runs on the panel's login page, where the launcher stays hidden until the user signs in. The form's "My reports" link opens the panel's "My reports" page when the panel shows it. If that panel signs users in with its own guard (`->authGuard('client')`), list the guard in `auth.guards`; without it, the package's own routes (images, the standalone pages) do not recognise those users. Until the guards are listed in `auth.guards`, there is no developer type check, so the developer resolver also receives that panel's users (for example a `Client`); a rule keyed on an id or an e-mail could match them. See [Reporters on another guard](#reporters-on-another-guard).

## Reporters on another guard

By default the package sees the user of the guard in use: inside a Filament panel, the panel's guard; elsewhere, the app's default guard. If your reporters sign in with a guard of their own, for example clients on a client panel with `->authGuard('client')` while staff use `web`, list both guards and say which one your developers use:

```php
// config/feedback-board.php
'auth' => [
    'guards' => ['web', 'client'],
    'developer_guards' => ['web'],
],
'route_middleware' => ['web', 'feedback-board.auth'],
```

- **Developers.** With more than one guard listed, `developer_guards` is required; without it nobody is a developer, and a warning is logged. Only a model of a developer guard's provider can be a developer, so a rule such as `fn ($user) => $user->id === 1` never matches a client, and your developer resolver never receives one. This holds even when your client model extends your staff model. Two exceptions: guards whose providers use the same model class cannot be told apart (same model, same person), and a `Gate::before()` hook runs first and can still grant developer access. The package reads each guard's model from its user provider's `model` setting, whatever the driver (`eloquent`, or a custom one such as `eloquent-webauthn`), as long as it names an Eloquent model class. A developer guard whose provider names none (the `database` driver, for example) has no developers, and a warning is logged. A listed client guard whose provider names none also logs a warning, because its users cannot be told apart from developers by type; the `database` driver is exempt.
- **Panels.** Inside a panel only that panel's guard counts. A panel whose guard is not listed recognises nobody, and a warning is logged. A report is therefore never filed as the staff member who happens to be signed in in the same browser.
- **Two sessions in one browser.** On the package's own pages (the standalone "My reports" page and the board), the guard in use is tried first, normally the app default, then the list in order. Images open for whichever signed-in user may see them.
- **A published config.** If your published `config/feedback-board.php` lists `'auth'` in `route_middleware`, replace it with `'feedback-board.auth'`. `'auth'` checks the default guard only and refuses clients before the package sees them. `feedback-board.auth` takes no guard parameters (`feedback-board.auth:client` does not restrict anything); `auth.guards` decides which guards count. Middleware that needs the signed-in user, such as `'verified'`, goes after `'feedback-board.auth'`.
- **Not signed in on a listed guard.** A user signed in only on a guard that is not listed is treated as a guest: no launcher, and the package's pages send them to your login page.
- **The reporter model.** Reports store their reporter as a polymorphic relation, so no migration is needed. To receive e-mail, the model needs Laravel's `Notifiable` trait and an `email` attribute. If you enforce a morph map (`Relation::enforceMorphMap()`), add the model to it.
- **`Gate::before()` hooks** run for every user of every listed guard, before the package's own checks. A hook keyed on an id or an e-mail must also check the model type, or it will match a client with the same id.

## i18n / RTL Support

The package ships with English (`en`) and Arabic (`ar`) translations. The Arabic locale sets `dir` to `rtl`. Pass the direction value to your layout's `<html>` tag as shown in the usage example above.

The package's e-mails follow the same direction: in Arabic their text runs and aligns right to left. A report title or reply inside an e-mail keeps its own direction, so an English reply in an Arabic e-mail, or the reverse, keeps its word order. They keep your app's mail layout and theme (`mail.markdown.theme`), and their greeting and sign-off come from the package's translations. They use the package's own Markdown template, `feedback-board::mail.notification`, so a customised `vendor/notifications/email.blade.php` does not change them; publish the package's views to customise that template.

Supported locales: `en`, `ar`. Add more by publishing translations (`php artisan vendor:publish --tag=feedback-board-translations`) and creating your own locale directory under `lang/vendor/feedback-board/`, for example `lang/vendor/feedback-board/fr/feedback-board.php`. Do not create `resources/lang`: once that directory exists, Laravel loads all of your app's translations from it instead of `lang/`.

## Styling & theming

Every package screen — the launcher, the form, "My reports", the Kanban board, the slide-overs and the card dialog — ships with its own stylesheet. You do not build, publish or link anything: the first package component on a page adds a single cached `<link rel="stylesheet">` to the page `<head>` (through Livewire's `@assets`), served by the package at `/feedback-board/assets/stylesheet` (under your `route_prefix`; the path has no `.css` extension so web-server rules for static files never intercept it). The styles only target the package's own `fb-*` classes and never touch your markup.

**Dark mode.** Set `FEEDBACK_BOARD_THEME` (config `feedback-board.ui.theme`):

| Value | Dark when |
|---|---|
| `class` (default) | an ancestor element has the class `dark` — Filament panels, Laravel's Livewire starter kits and Tailwind's class strategy. The package's own standalone pages have no host to set that class, so they stay light; use `system` to follow the operating system there. |
| `system` | the operating system prefers dark (`prefers-color-scheme`) |
| `light` / `dark` | never / always |

**Brand colors and shape.** Override these custom properties in your own CSS:

| Property | Default | Used for |
|---|---|---|
| `--fb-accent` | `#4f46e5` | primary buttons, the launcher, links (light theme), focus rings, the reporter-comment accent |
| `--fb-accent-hover` | `#4338ca` | primary button hover |
| `--fb-accent-contrast` | `#fff` | text on the accent color |
| `--fb-radius` | `0.75rem` | panels, dialogs and slide-overs |
| `--fb-font` | your page font | font family |
| `--fb-z` | `1000` (`35` inside a Filament panel) | stacking order of the launcher; dialogs and slide-overs sit 10 above it, the image preview 20 above it |

```css
:root {
    --fb-accent: #0d9488;
    --fb-accent-hover: #0f766e;
}
```

The package never sets these properties itself (it only reads them with fallbacks), so your values win wherever you declare them — on `:root`, on `.fb-scope`, inside a dark-mode selector or a CSS `@layer`.

Inside a Filament panel the package follows the panel's dark mode and primary colour on its own (see [Filament Integration](#filament-integration)); the properties above still override it.

**Right-to-left.** Layout direction follows the locale's `dir` (`ar` is right-to-left); the launcher, slide-overs, dialogs and spacing mirror automatically.

**Content Security Policy.** The stylesheet is a same-origin file, so `style-src 'self'` is enough; when you use Laravel's Vite CSP nonce (`Vite::useCspNonce()`), the `<link>` carries it too.

**Responses Livewire does not post-process.** The `<link>` is added to full HTML documents (a `200` `text/html` response containing `</html>`) and to Livewire updates. If you return a package component inside anything else — for example an HTML fragment fetched by your own JavaScript — link the stylesheet yourself:

```blade
<link rel="stylesheet" href="{{ \YousefAman\FeedbackBoard\Support\Ui::stylesheetUrl() }}">
```

## Configuration Reference

Key configuration options in `config/feedback-board.php`:

| Key | Type | Description |
|-----|------|-------------|
| `project` | string | Project name stamped on every new report (`FEEDBACK_BOARD_PROJECT`, default: `'default'`) |
| `auth.guards` | array\|null | Guards whose users the package recognises (default: `null`, the guard in use) — see [Reporters on another guard](#reporters-on-another-guard) |
| `auth.developer_guards` | array\|null | Guards whose users may be developers; required when `auth.guards` lists more than one guard |
| `authorization.developer_resolver` | callable\|null | Public static method returning `bool`; prefer `FeedbackBoard::resolveDevelopersUsing()` (a closure here breaks `config:cache`) |
| `authorization.reporter_resolver` | callable\|null | Public static method returning `bool`: who may send reports (default: `null`, every signed-in user); prefer `FeedbackBoard::resolveReportersUsing()` |
| `attachments.disk` | string | Storage disk for uploaded attachments (`FEEDBACK_BOARD_DISK`, default: `'local'`, private) |
| `attachments.directory` | string | Directory on that disk (default: `'feedback-board'`) |
| `attachments.max_kb` | int | Maximum upload size in kilobytes (default: `10240`); the form shows the lower of this, PHP's `upload_max_filesize` and Livewire's upload limit |
| `attachments.allowed_mimes` | array | Allowed file extensions (default: images, `pdf`, `txt`, `log`) |
| `attachments.max_files` | int | Most files per report and per comment (default: `10`) |
| `route_prefix` | string | URL prefix for package routes (default: `'feedback-board'`) |
| `route_middleware` | array | Middleware applied to package routes (default: `['web', 'feedback-board.auth']`; `feedback-board.auth` is appended when missing) |
| `ui.theme` | string | Dark-mode strategy (`FEEDBACK_BOARD_THEME`): `class` (default), `system`, `light` or `dark` — see [Styling & theming](#styling--theming) |
| `notifications.developers` | array\|callable | Developer e-mail addresses (`FEEDBACK_BOARD_DEVELOPER_EMAIL`, comma-separated; invalid entries are skipped); for models prefer `FeedbackBoard::notifyDevelopersUsing()` |
| `notifications.channels` | array | Notification channels (default: `['mail']`; add `'database'` after `make:notifications-table` and `migrate`) |
| `destinations.default` | string | Kept for contract parity; the `mail` driver is a no-op (default: `'mail'`) |
| `destinations.enabled` | array | Automatic destination drivers, e.g. `['webhook', 'slack']` — empty by default. Do not add `github` here: its first issue is created by a developer, and later comments are posted without it (see [Destinations](#destinations-outbound)) |
| `destinations.lock_store` | string\|null | Cache store for the locks around GitHub delivery, delivery-failure records and the runner's PR link (`FEEDBACK_DESTINATIONS_LOCK_STORE`; `null` = app default). With several servers, use a shared store such as `redis` or `database` |
| `destinations.webhook` / `.slack` / `.github` | array | Per-driver URL, secret or token, and event list — see [Destinations](#destinations-outbound) |
| `ingest.enabled` | bool | Enable the signed ingest endpoint (`FEEDBACK_BOARD_INGEST_ENABLED`, default: `false`) |
| `ingest.secret` | string\|null | HMAC secret for ingest signature verification (`FEEDBACK_BOARD_INGEST_SECRET`) |
| `ingest.tolerance` | int | Timestamp drift tolerance in seconds (default: `300`); rate limiting is handled by the route (`throttle:60,1`) |
| `ai.enabled` | bool | Enable AI triage (`FEEDBACK_BOARD_AI_ENABLED`, default: `false`) — see [AI Triage](#ai-triage-level-a) |
| `ai.default` | string | Triage driver: `anthropic`, `gemini`, `openai` or one added with `FeedbackBoard::extendTriage()` (`FEEDBACK_BOARD_AI_DRIVER`, default: `'anthropic'`) |
| `ai.queue` | string\|null | Queue the triage jobs are pushed to (`FEEDBACK_BOARD_AI_QUEUE`; `null` or empty = the connection's default queue); make sure a worker listens on it, e.g. `php artisan queue:work --queue=ai,default` |
| `ai.lock_store` | string\|null | Cache store for the per-item AI triage and runner lock (`FEEDBACK_BOARD_AI_LOCK_STORE`; `null` = app default). With several servers, use a shared store |
| `ai.max_input_chars` | int | Combined title and description budget sent to the model (default: `4000`) |
| `ai.anthropic.*` | array | `api_key` (`FEEDBACK_BOARD_ANTHROPIC_KEY`), `model` (`FEEDBACK_BOARD_ANTHROPIC_MODEL`, default: `'claude-haiku-4-5'`), `timeout` (`30`, seconds per attempt; the whole call, its retry included, stays within 40 seconds), `max_tokens` (`1024`) |
| `ai.gemini.*` | array | `api_key` (`FEEDBACK_BOARD_GEMINI_KEY`), `model` (`FEEDBACK_BOARD_GEMINI_MODEL`, default: `'gemini-3.5-flash-lite'`), `timeout` (`30`), `max_tokens` (`null` = Gemini's own limit; thinking tokens count toward a cap) |
| `ai.openai.*` | array | `api_key` (`FEEDBACK_BOARD_OPENAI_KEY`), `model` (`FEEDBACK_BOARD_OPENAI_MODEL`, default: `'gpt-6-luna'`), `base_url` (`FEEDBACK_BOARD_OPENAI_BASE_URL`, default: `'https://api.openai.com/v1'`), `reasoning_effort` (`FEEDBACK_BOARD_OPENAI_REASONING_EFFORT`, default: `'none'`; empty = not sent), `timeout` (`30`), `max_tokens` (`null` = the provider's own limit; sent as `max_completion_tokens`) |
| `runner.enabled` | bool | Enable the AI runner (`FEEDBACK_BOARD_RUNNER_ENABLED`, default: `false`) — see [AI Runner Contract](#ai-runner-contract-level-bc) |
| `runner.endpoint` / `runner.secret` | string\|null | Runner task-receive URL and HMAC secret (`FEEDBACK_RUNNER_ENDPOINT`, `FEEDBACK_RUNNER_SECRET`) |
| `runner.timeout` / `runner.connect_timeout` | int | Dispatch HTTP timeouts in seconds (defaults: `8`, `4`) |
| `runner.max_analysis_chars` / `runner.max_diff_chars` | int | Longest analysis and suggested diff accepted from the runner (defaults: `10000`, `20000`); a longer `runner_result` is rejected with a validation error, not truncated |
| `runner.callback_url` | string\|null | Optional ingest URL placed in the task envelope (`FEEDBACK_RUNNER_CALLBACK_URL`) |
| `runner.stale_after` | int | Seconds without an update after which an in-flight task counts as stale (default: `1800`; `0` = never). The card dialog says so and offers to re-send it, and the Filament action comes back; a late result from the replaced task gets 409 |

**Attachments and images.** Reports and comments can both carry files. Comments (from reporters and developers, internal notes included) take images only: JPEG, PNG, GIF and WebP, within `allowed_mimes`. Those images show inline and in a preview; every other file, SVG included, downloads. An internal note's images are served to developers only. Deleting a comment through its model (`$comment->delete()`) deletes its attachment rows and files. A delete that skips model events (`->delete()` on a query, `DB::table()` or raw SQL) still removes the rows through the foreign key, but leaves the files on the disk, like a deleted report. Deleting a whole report removes its rows through the database cascade, which runs no model events, so its files stay on the disk under `attachments.directory/{report id}`; delete that directory yourself.

## Destinations (outbound)

Mirror feedback events to external systems. Configure under `feedback-board.destinations`.

### Webhook (signed)
Set `FEEDBACK_WEBHOOK_URL` and `FEEDBACK_WEBHOOK_SECRET`, then add `'webhook'` to
`destinations.enabled`. Each POST carries `X-Feedback-Timestamp`, `X-Feedback-Signature`
(HMAC-SHA256 over `"{timestamp}.{rawBody}"`, bare hex — identical to the ingest contract),
`X-Feedback-Event`, and `X-Feedback-Delivery`. The payload is a curated whitelist; verify
the signature against the **raw request body** before trusting it. A reply that carries only images has an empty `body` (`""`).

### Slack
Create an Incoming Webhook, set `FEEDBACK_SLACK_WEBHOOK_URL`, add `'slack'` to
`destinations.enabled`. The webhook URL is a secret: the package never puts it in its exceptions, its error state or failed jobs. A connection error is reported as `connection_error`, and an unparsable URL as `invalid_url`. Laravel's own HTTP client events still carry the request, so a tool that records them (Telescope, Nightwatch) can store the URL.

### GitHub Issues
Set `FEEDBACK_GITHUB_TOKEN` (a **fine-grained PAT** scoped to a single repo with
`Issues: write` — least privilege; the classic `repo` scope also works but grants far
broader access, avoid it) and `FEEDBACK_GITHUB_REPO` (`owner/name`). GitHub is **not**
added to `destinations.enabled`: the first issue is created by the developer's manual
"Send to GitHub" action; later replies and status changes auto-comment on it.
Each reply comment starts with its author's role, for example `**Reporter** replied:`; no person's name is sent. Once the issue exists, the card dialog and the All reports table link it.
Set `feedback-board.destinations.github.close_on_resolved` to close the issue on resolve. Comments and closing always go to the repo where the issue was opened, even after `FEEDBACK_GITHUB_REPO` changes; if the token can no longer reach that repo, the delivery fails with `not_found_404` or `auth_401`.

GitHub idempotency, delivery-failure records and the runner's PR link are guarded by a cache
lock from `FEEDBACK_DESTINATIONS_LOCK_STORE` (unset = your app's default cache store). The lock
must be shared by every worker that can deliver: `file` only coordinates workers on one server,
so with several servers use `redis`, `memcached`, `dynamodb` or `database`. The `array` store
is process-local (no cross-worker protection).

Delivery failures are isolated per destination. On a real queue, a failed delivery is retried
after 30 seconds, 2 minutes and 5 minutes, within 15 minutes; a GitHub rate-limit wait counts as
an attempt. On the `sync` queue it is not retried. A delivery that still fails is recorded as a
sanitized code in `external_refs.{driver}_last_error` (never the raw error, URL, or token), and
the next delivery to that destination that gets through clears it. Developers see each failing
destination in the card dialog, with the reason, the event and how long ago. With Filament
installed, developers who are user models (`FeedbackBoard::notifyDevelopersUsing()`) also get a
bell entry that names the destination and the reason; it needs the `notifications` table.
Internal notes are never forwarded to any destination.

### Ingest endpoint (inbound)

Off by default (`FEEDBACK_BOARD_INGEST_ENABLED`). External systems, such as CI or your agent runner, POST JSON to `/feedback-board/ingest` (under your `route_prefix`), signed like the webhook: `X-Feedback-Timestamp` and `X-Feedback-Signature`, HMAC-SHA256 over `"{timestamp}.{rawBody}"` with `FEEDBACK_BOARD_INGEST_SECRET`. Each signature is accepted once.

| `action` | Fields | Effect |
|---|---|---|
| `comment` | `item_id`, `body` | Adds a public AI comment; the reporter is notified |
| `status` | `item_id`, `status` | Changes the report's status |
| `external_ref` | `item_id`, `ref.key`, `ref.value` | Stores `external_refs[key] = value` |
| `runner_status`, `runner_result` | see [`docs/runner-contract.md`](docs/runner-contract.md) | Runner post-back |

`ref.key` must match `^[a-z][a-z0-9_.-]{0,63}$`. `github`, `pr_url` and keys ending in `_last_error` are reserved for the package. `ref.value` is a string of at most 2048 characters.

Every answer has its real status: `200 {"ok":true}`; 403 (missing or bad signature, stale timestamp, replay); 404 (ingest disabled, unknown report); 409 (runner task mismatch); 422 (validation, with `errors`); 429 (more than 60 requests a minute); 503 (busy). The package's own answers have JSON bodies. Answers given before the route runs (maintenance mode's 503, 413 for an oversized body, 405) follow your app's error rendering: JSON when the sender asks for it with `Accept: application/json`, as `post-back.sh` does. A host rule set with `shouldRenderJsonWhen()` can still turn the body of a 404 for an unknown report, a 409, 429 or 503 into HTML; the status stays. Re-sign before retrying. String values are trimmed of leading and trailing whitespace.

## AI Triage (Level A)

Optional, in-app AI triage that suggests a **type** and **priority** and a short
**summary** for each new report. Suggestions are stored on the item; a developer
reviews them and applies the type and priority one field at a time (the summary is shown,
not applied) — the AI never changes a report's fields on its own.

**Opt-in.** Disabled by default. Enable it and choose a provider: `anthropic`, `gemini` or `openai`.

```env
FEEDBACK_BOARD_AI_ENABLED=true
FEEDBACK_BOARD_AI_DRIVER=gemini          # or anthropic, openai

FEEDBACK_BOARD_GEMINI_KEY=...            # Google AI Studio
FEEDBACK_BOARD_ANTHROPIC_KEY=sk-ant-...
FEEDBACK_BOARD_OPENAI_KEY=sk-...

# optional:
FEEDBACK_BOARD_GEMINI_MODEL=gemini-3.5-flash-lite
FEEDBACK_BOARD_ANTHROPIC_MODEL=claude-haiku-4-5
FEEDBACK_BOARD_OPENAI_MODEL=gpt-6-luna
FEEDBACK_BOARD_AI_QUEUE=
FEEDBACK_BOARD_AI_LOCK_STORE=redis
```

Only the selected provider's key is needed, and an empty model setting uses the provider's
default model. When AI is enabled but its provider is unconfigured (no API key, or an unknown
driver name), triage stays inert — nothing is dispatched and the manual button returns 422.

**Providers.**

- **`anthropic`**: the Messages API, with the triage as a forced tool call.
- **`gemini`**: the Gemini API's `generateContent`, with the triage as JSON held to a schema. The key
  is sent in a header, never in the URL.
- **`openai`**: Chat Completions, with the triage as a forced function call. `reasoning_effort` is
  sent as `none` by default, because OpenAI's reasoning models call functions in Chat Completions
  only with reasoning off. `FEEDBACK_BOARD_OPENAI_BASE_URL` points it at any API that speaks the same
  protocol, such as DeepSeek, Mistral, OpenRouter or a local Ollama. Set
  `FEEDBACK_BOARD_OPENAI_REASONING_EFFORT=` (empty) for a provider or model that rejects that
  field. A provider that needs no key, such as a local Ollama, still needs a non-empty key setting:
  any placeholder will do.

**Another provider.** Register a driver in `AppServiceProvider::boot()` and select it by name. The
factory receives the settings under `feedback-board.ai.{name}`:

```php
use YousefAman\FeedbackBoard\Facades\FeedbackBoard;

FeedbackBoard::extendTriage('mistral', fn (array $config) => new \App\Triage\MistralTriage($config));
```

The driver implements `YousefAman\FeedbackBoard\Triage\Contracts\TriageDriver`: it receives the title
and description and returns a `TriageResult`. If it also implements `ChecksConfiguration`, its
`configured()` decides whether triage runs; otherwise it counts as configured. A driver that fails
to build, or returns something other than a `TriageDriver`, turns triage off and is reported once
per request or worker. On failure, throw `TriageException` with a short code and keep keys out of
its message: `http_429`, `http_5xx` and `connection_error` are retried (three tries in all), and any
other code fails the triage at once. Any other exception fails it as `unknown`, without a retry and
without a report.

**What leaves your app.** Only the report's **title and description** are sent to
the model, jointly truncated to `ai.max_input_chars` (default 4000) to bound cost.
Reporter identity, email, attachments, and all internal fields are never sent. The
API key is read from the environment, never logged by the package, and never stored; failures
record only a short sanitized error code. Laravel's own HTTP client events still carry the request,
so a tool that records outgoing requests can store the key unless it hides the key's header
(`Authorization` for OpenAI, `x-api-key` for Anthropic, `x-goog-api-key` for Gemini); with
Telescope, add the last two through `Telescope::hideRequestHeaders()`. A summary is kept to 1,000
characters. Each provider has its own data terms; for example,
Google's terms for the Gemini API's free tier allow it to use what you send to improve its
products. Check your provider's terms before you send your users' reports to it.

**Triggering.** Triage runs automatically on new reports (after the creating
transaction commits) and can be re-run from the "Triage with AI" button in the
board's card dialog or the Filament resource.

**Time.** The call to the provider, its one retry included, ends within 40 seconds, and the triage
job has a 60-second timeout of its own, whatever `--timeout` the worker runs with. Keep the queue
connection's `retry_after` above 60 seconds (Laravel's default is 90), or a second worker may pick
the job up while it is still running.

**Lock store.** The per-item `ai_state` lock (also used by the AI runner) comes from
`FEEDBACK_BOARD_AI_LOCK_STORE` (unset = your app's default cache store). Like destinations, it
must be shared by every worker: `file` only coordinates workers on one server, so with several
servers use `redis`, `memcached`, `dynamodb` or `database`. The `array` store is
process-local (fine for a single process / tests, not for multi-worker deployments).

## AI Runner Contract (Level B/C)

An optional, manual, opt-in extension that lets you connect an external AI coding agent
(running in your own CI/CD or dev environment) to the Feedback Board.

**How it works:** A developer triggers a runner task from the Filament board. The package
dispatches a signed HTTPS POST (the "task envelope") to your configured `runner.endpoint`.
Your receiver verifies the HMAC, forwards the task to a CI job (e.g. GitHub Actions via
`repository_dispatch`), and the CI job runs your agent. Results are posted back to the
package's signed ingest endpoint and appear in the board for developer review.

**Key runner config keys:**

| Key | Env var | Description |
|-----|---------|-------------|
| `runner.enabled` | `FEEDBACK_BOARD_RUNNER_ENABLED` | Enable runner dispatch (default: `false`, opt-in) |
| `runner.endpoint` | `FEEDBACK_RUNNER_ENDPOINT` | HTTPS URL of your HMAC-verifying receiver |
| `runner.secret` | `FEEDBACK_RUNNER_SECRET` | HMAC secret for outbound signing |
| `runner.callback_url` | `FEEDBACK_RUNNER_CALLBACK_URL` | Optional explicit ingest URL placed in the task envelope |
| `ingest.enabled` | `FEEDBACK_BOARD_INGEST_ENABLED` | Must be `true` to accept runner post-back results |
| `ingest.secret` | `FEEDBACK_BOARD_INGEST_SECRET` | HMAC secret for verifying post-back |

**Important:** The runner runs in your CI/dev environment with scoped credentials — never in
the production application context. The package never auto-merges; it stores a PR link and
analysis for the developer to review. Secrets must never be written into a feedback item.

For the full protocol (envelope schema, HMAC formula, `runner_status` / `runner_result`
post-back actions, security requirements, and a worked end-to-end example) see
[`docs/runner-contract.md`](docs/runner-contract.md).

Reference implementations:
- [`docs/examples/runner/receiver.example.php`](docs/examples/runner/receiver.example.php) — minimal HMAC-verifying PHP receiver
- [`docs/examples/runner/github-actions-feedback-runner.example.yml`](docs/examples/runner/github-actions-feedback-runner.example.yml) — GitHub Actions workflow
- [`docs/examples/runner/post-back.sh`](docs/examples/runner/post-back.sh) — signed, retrying post-back sender the workflow calls

## Roadmap

Shipped in 1.0: destination drivers (signed webhook, Slack, GitHub Issues), in-app AI triage (Level A) and the AI runner contract (Level B/C).

Planned for 1.1:

- **Multi-tenant developer hub** — one dashboard that aggregates reports from several client applications.

See [CHANGELOG.md](CHANGELOG.md) for release notes.

## Security

Please report vulnerabilities privately, as the [security policy](https://github.com/yousef-aman/laravel-feedback-board/security/policy) describes, not in public issues.

## License

MIT — see [LICENSE](LICENSE).
