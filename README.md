# Feedback Board

A self-hosted, embeddable feedback widget for Laravel applications. Clients submit bug reports and feature requests inside their own session — your data stays on your infrastructure, processed by your own runner.

## Positioning

Feedback Board is self-hosted by design. Reports are captured inside the authenticated client's session (no third-party script, no external SaaS), stored in your own database, and processed through your own notification and AI pipelines. You own the data, the hosting, and the runner. Phase 2 will add pluggable destination drivers, AI triage (A/B/C classification), and a multi-tenant developer hub — see the Roadmap section below.

## Requirements

- PHP 8.2+ (8.3+ for Laravel 13)
- Laravel 12 or 13
- Livewire 3.8.3+ or 4.3.4+
- Filament 4 or 5 (optional, for the developer Kanban board)

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

The launcher renders a floating button that opens the feedback form in a modal overlay. It is RTL-aware — set `dir` via the translation key as shown above.

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
FeedbackBoard::notifyDevelopersUsing(fn () => User::where('is_admin', true)->get());
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

## i18n / RTL Support

The package ships with English (`en`) and Arabic (`ar`) translations. The Arabic locale sets `dir` to `rtl`. Pass the direction value to your layout's `<html>` tag as shown in the usage example above.

Supported locales: `en`, `ar`. Add more by publishing translations and creating your own locale directory under `resources/lang/vendor/feedback-board/`.

## Configuration Reference

Key configuration options in `config/feedback-board.php`:

| Key | Type | Description |
|-----|------|-------------|
| `authorization.developer_resolver` | callable\|null | Public static method returning `bool`; prefer `FeedbackBoard::resolveDevelopersUsing()` (a closure here breaks `config:cache`) |
| `attachments.disk` | string | Storage disk for uploaded attachments (default: `'local'`) |
| `attachments.max_kb` | int | Maximum upload size in kilobytes (default: `10240`) |
| `route_prefix` | string | URL prefix for package routes (default: `'feedback-board'`) |
| `route_middleware` | array | Middleware applied to package routes (default: `['web', 'auth']`) |
| `notifications.developers` | array\|callable | Developer e-mail addresses (`FEEDBACK_BOARD_DEVELOPER_EMAIL`, comma-separated; invalid entries are skipped); for models prefer `FeedbackBoard::notifyDevelopersUsing()` |
| `notifications.channels` | array | Notification channels (default: `['mail']`; add `'database'` after `make:notifications-table`) |
| `destinations.default` | string | Default destination driver name (default: `'mail'`) |
| `destinations.enabled` | array | Active destination drivers — empty by default (all opt-in; Phase 2 enables webhook/github/slack/etc.) |
| `ingest.enabled` | bool | Enable the remote ingest endpoint (default: `false`) |
| `ingest.secret` | string\|null | HMAC secret for ingest signature verification |
| `ingest.tolerance` | int | Timestamp drift tolerance in seconds (default: `300`); rate limiting is handled by the route (`throttle:60,1`) |

## Roadmap (Phase 2)

- **Pluggable destination drivers** — send reports to Slack, Jira, Linear, GitHub Issues, or custom webhooks via a unified driver interface
- **AI triage (A/B/C)** — automatic classification and prioritisation using your own Claude runner
- **Multi-tenant developer hub** — a centralised dashboard aggregating reports across multiple client applications

## Destinations (outbound)

Mirror feedback events to external systems. Configure under `feedback-board.destinations`.

### Webhook (signed)
Set `FEEDBACK_WEBHOOK_URL` and `FEEDBACK_WEBHOOK_SECRET`, then add `'webhook'` to
`destinations.enabled`. Each POST carries `X-Feedback-Timestamp`, `X-Feedback-Signature`
(HMAC-SHA256 over `"{timestamp}.{rawBody}"`, bare hex — identical to the ingest contract),
`X-Feedback-Event`, and `X-Feedback-Delivery`. The payload is a curated whitelist; verify
the signature against the **raw request body** before trusting it.

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

GitHub idempotency uses an atomic cache lock — set `FEEDBACK_DESTINATIONS_LOCK_STORE` to an
atomic store (`redis`, `memcached`, `dynamodb`, or `database`). If left unset it falls back to
your app's default cache store; a non-lockable default such as `file` will **throw** on the
first GitHub delivery, so set this explicitly when your default cache is `file`. The `array`
store is atomic but process-local (no cross-worker protection).

Delivery failures are isolated per destination, retried with backoff for up to 15 minutes,
and recorded as a sanitized code in `external_refs.{driver}_last_error` (never the raw error,
URL, or token). Internal notes are never forwarded to any destination.

## AI Triage (Level A)

Optional, in-app AI triage that suggests a **type** and **priority** and a short
**summary** for each new report. Suggestions are stored on the item; a developer
reviews and applies them — the AI never changes a report's fields on its own.

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
Livewire detail panel or the Filament resource.

**Lock store.** Like destinations, the per-item `ai_state` lock needs an atomic
cache store (`redis`/`memcached`/`database`/`dynamodb`). Set
`FEEDBACK_BOARD_AI_LOCK_STORE` if your default cache is `file`. The `array`
store is atomic but process-local (fine for a single process / tests, not for
multi-worker deployments).

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

## License

MIT
