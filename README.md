# Feedback Board

A self-hosted, embeddable feedback widget for Laravel applications. Clients submit bug reports and feature requests inside their own session — your data stays on your infrastructure, processed by your own runner.

## Positioning

Feedback Board is self-hosted by design. Reports are captured inside the authenticated client's session (no third-party script, no external SaaS), stored in your own database, and processed through your own notification and AI pipelines. You own the data, the hosting, and the runner. Optional destination drivers (webhook, Slack, GitHub Issues), AI triage and an AI runner contract plug into the same pipeline. All of them are off until you enable them, and each one you enable sends data to the service it names (AI triage sends only a report's title and description to the Anthropic API — see [AI Triage](#ai-triage-level-a)). A multi-tenant developer hub is planned — see the [Roadmap](#roadmap).

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

The launcher renders a floating button in the bottom corner of the page (bottom-right in left-to-right locales, bottom-left in right-to-left ones) that opens the report form in a panel sliding in from the side; reporters can add screenshots by picking, dropping or pasting them. Its styles load automatically — there is nothing to build, publish or link (see [Styling & theming](#styling--theming)).

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

## Filament Integration

If you are using Filament, register the plugin in your Panel provider:

```php
use YousefAman\FeedbackBoard\Filament\FeedbackBoardPlugin;

$panel->plugin(FeedbackBoardPlugin::make());
```

This adds three entries to the panel's navigation, in a translated **Feedback** group:

| Entry | Who sees it | Badge |
|---|---|---|
| **My reports** (`/admin/my-feedback`) | every signed-in panel user | reports with unread replies |
| **Feedback board** (`/admin/feedback-board-page`) | developers | open reports |
| **All reports** (the feedback items resource) | developers | — |

Reporters follow their reports and reply, with images, without leaving the panel; developers triage on the Kanban and work each report in a card dialog. To keep "My reports" out of a panel, such as an admin-only one, turn it off for that panel:

```php
$panel->plugin(FeedbackBoardPlugin::make()->myReports(false));
```

**Colours.** Inside a panel the package follows the panel's primary colour, in light and dark mode, with readable text on it (white, or the colour's darkest shade for a light primary such as amber). Your own `--fb-accent` properties still win.

**Limitation.** The package uses the application's default auth guard. Reporters who sign in to a panel with its own guard (`->authGuard(...)`) are not supported yet.

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

The form's "My reports" link opens the panel's "My reports" page when the panel shows it.

## i18n / RTL Support

The package ships with English (`en`) and Arabic (`ar`) translations. The Arabic locale sets `dir` to `rtl`. Pass the direction value to your layout's `<html>` tag as shown in the usage example above.

Supported locales: `en`, `ar`. Add more by publishing translations and creating your own locale directory under `resources/lang/vendor/feedback-board/`.

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
| `authorization.developer_resolver` | callable\|null | Public static method returning `bool`; prefer `FeedbackBoard::resolveDevelopersUsing()` (a closure here breaks `config:cache`) |
| `attachments.disk` | string | Storage disk for uploaded attachments (`FEEDBACK_BOARD_DISK`, default: `'local'`, private) |
| `attachments.directory` | string | Directory on that disk (default: `'feedback-board'`) |
| `attachments.max_kb` | int | Maximum upload size in kilobytes (default: `10240`); the form shows the lower of this, PHP's `upload_max_filesize` and Livewire's upload limit |
| `attachments.allowed_mimes` | array | Allowed file extensions (default: images, `pdf`, `txt`, `log`) |
| `attachments.max_files` | int | Most files per report and per comment (default: `10`) |
| `route_prefix` | string | URL prefix for package routes (default: `'feedback-board'`) |
| `route_middleware` | array | Middleware applied to package routes (default: `['web', 'auth']`) |
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
| `ai.default` | string | Triage driver (`FEEDBACK_BOARD_AI_DRIVER`, default: `'anthropic'`) |
| `ai.queue` | string\|null | Queue for triage jobs (`FEEDBACK_BOARD_AI_QUEUE`; `null` = default queue) |
| `ai.lock_store` | string\|null | Cache store for the per-item AI triage and runner lock (`FEEDBACK_BOARD_AI_LOCK_STORE`; `null` = app default). With several servers, use a shared store |
| `ai.max_input_chars` | int | Combined title and description budget sent to the model (default: `4000`) |
| `ai.anthropic.*` | array | `api_key` (`FEEDBACK_BOARD_ANTHROPIC_KEY`), `model` (`FEEDBACK_BOARD_ANTHROPIC_MODEL`, default: `'claude-haiku-4-5'`), `timeout` (`30`), `max_tokens` (`1024`) |
| `runner.enabled` | bool | Enable the AI runner (`FEEDBACK_BOARD_RUNNER_ENABLED`, default: `false`) — see [AI Runner Contract](#ai-runner-contract-level-bc) |
| `runner.endpoint` / `runner.secret` | string\|null | Runner task-receive URL and HMAC secret (`FEEDBACK_RUNNER_ENDPOINT`, `FEEDBACK_RUNNER_SECRET`) |
| `runner.timeout` / `runner.connect_timeout` | int | Dispatch HTTP timeouts in seconds (defaults: `8`, `4`) |
| `runner.max_analysis_chars` / `runner.max_diff_chars` | int | Longest analysis and suggested diff accepted from the runner (defaults: `10000`, `20000`); a longer `runner_result` is rejected with a validation error, not truncated |
| `runner.callback_url` | string\|null | Optional ingest URL placed in the task envelope (`FEEDBACK_RUNNER_CALLBACK_URL`) |
| `runner.stale_after` | int | Seconds before the UI marks a running task as possibly stale (default: `1800`) |

**Attachments and images.** Reports and comments can both carry files. Comments (from reporters and developers, internal notes included) take images only: JPEG, PNG, GIF and WebP, within `allowed_mimes`. Those images show inline and in a preview; every other file, SVG included, downloads. An internal note's images are served to developers only. Deleting a comment deletes its files. Deleting a whole report removes its rows through the database cascade, which runs no model events, so its files stay on the disk under `attachments.directory/{report id}`; delete that directory yourself.

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
`destinations.enabled`. The webhook URL is a secret — it is never logged or stored in error state.

### GitHub Issues
Set `FEEDBACK_GITHUB_TOKEN` (a **fine-grained PAT** scoped to a single repo with
`Issues: write` — least privilege; the classic `repo` scope also works but grants far
broader access, avoid it) and `FEEDBACK_GITHUB_REPO` (`owner/name`). GitHub is **not**
added to `destinations.enabled`: the first issue is created by the developer's manual
"Send to GitHub" action; later replies and status changes auto-comment on it.
Set `feedback-board.destinations.github.close_on_resolved` to close the issue on resolve.

GitHub idempotency, delivery-failure records and the runner's PR link are guarded by a cache
lock from `FEEDBACK_DESTINATIONS_LOCK_STORE` (unset = your app's default cache store). The lock
must be shared by every worker that can deliver: `file` only coordinates workers on one server,
so with several servers use `redis`, `memcached`, `dynamodb` or `database`. The `array` store
is process-local (no cross-worker protection).

Delivery failures are isolated per destination, retried with backoff for up to 15 minutes,
and recorded as a sanitized code in `external_refs.{driver}_last_error` (never the raw error,
URL, or token). Internal notes are never forwarded to any destination.

## AI Triage (Level A)

Optional, in-app AI triage that suggests a **type** and **priority** and a short
**summary** for each new report. Suggestions are stored on the item; a developer
reviews them and applies the type and priority one field at a time (the summary is shown,
not applied) — the AI never changes a report's fields on its own.

**Opt-in.** Disabled by default. Enable it and configure a provider:

```env
FEEDBACK_BOARD_AI_ENABLED=true
FEEDBACK_BOARD_AI_DRIVER=anthropic
FEEDBACK_BOARD_ANTHROPIC_KEY=sk-ant-...
# optional:
FEEDBACK_BOARD_ANTHROPIC_MODEL=claude-haiku-4-5
FEEDBACK_BOARD_AI_QUEUE=
FEEDBACK_BOARD_AI_LOCK_STORE=redis
```

When AI is enabled but its driver is unconfigured (e.g. no API key), triage stays
inert — nothing is dispatched and the manual button returns 422.

**What leaves your app.** Only the report's **title and description** are sent to
the model, jointly truncated to `ai.max_input_chars` (default 4000) to bound cost.
Reporter identity, email, attachments, and all internal fields are never sent. The
API key is read from the environment, never logged, and never stored; failures
record only a short sanitized error code.

**Triggering.** Triage runs automatically on new reports (after the creating
transaction commits) and can be re-run from the "Triage with AI" button in the
board's card dialog or the Filament resource.

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

## Roadmap

Shipped in 1.0: destination drivers (signed webhook, Slack, GitHub Issues), in-app AI triage (Level A) and the AI runner contract (Level B/C).

Planned for 1.1:

- **Multi-tenant developer hub** — one dashboard that aggregates reports from several client applications.

See [CHANGELOG.md](CHANGELOG.md) for release notes.

## License

MIT — see [LICENSE](LICENSE).
