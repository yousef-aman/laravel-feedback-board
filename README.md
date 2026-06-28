# Feedback Board

A self-hosted, embeddable feedback widget for Laravel applications. Clients submit bug reports and feature requests inside their own session — your data stays on your infrastructure, processed by your own runner.

## Positioning

Feedback Board is self-hosted by design. Reports are captured inside the authenticated client's session (no third-party script, no external SaaS), stored in your own database, and processed through your own notification and AI pipelines. You own the data, the hosting, and the runner. Phase 2 will add pluggable destination drivers, AI triage (A/B/C classification), and a multi-tenant developer hub — see the Roadmap section below.

## Requirements

- PHP 8.2+
- Laravel ^11 || ^12
- Filament ^4 || ^5 (optional, for the developer Kanban board)

## Installation

```bash
composer require yousefaman/feedback-board
```

## Publish Assets and Migrate

Publish the configuration file:

```bash
php artisan vendor:publish --tag=feedback-board-config
```

Publish and run the migrations:

```bash
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

### 2. Configure the developer resolver

Open `config/feedback-board.php` and configure the `authorization.developer_resolver` callable to tell the package which authenticated users are developers:

```php
// config/feedback-board.php
'authorization' => [
    'developer_resolver' => function (\Illuminate\Contracts\Auth\Authenticatable $user): bool {
        // Return true if this user should have developer-level access.
        return $user->hasRole('developer');
    },
],
```

### 3. Configure developer notifications

In the same config file, list the developer users who should receive new-report email notifications:

```php
'notifications' => [
    'developers' => [
        // IDs of users to notify when a new report is submitted.
        // Example: \App\Models\User::where('role', 'developer')->pluck('id')->all()
        1,
        2,
    ],
],
```

Alternatively, set `notifications.developers` to a closure that returns an array of notifiable models:

```php
'notifications' => [
    'developers' => fn () => \App\Models\User::developers()->get()->all(),
],
```

## Filament Integration

If you are using Filament, register the plugin in your Panel provider:

```php
use YousefAman\FeedbackBoard\Filament\FeedbackBoardPlugin;

$panel->plugin(FeedbackBoardPlugin::make());
```

This adds a Kanban-style Feedback Board page (`/admin/feedback-board`) to your panel where developers can triage, reply, assign, and resolve reports.

## i18n / RTL Support

The package ships with English (`en`) and Arabic (`ar`) translations. The Arabic locale sets `dir` to `rtl`. Pass the direction value to your layout's `<html>` tag as shown in the usage example above.

Supported locales: `en`, `ar`. Add more by publishing translations and creating your own locale directory under `resources/lang/vendor/feedback-board/`.

## Configuration Reference

Key configuration options in `config/feedback-board.php`:

| Key | Type | Description |
|-----|------|-------------|
| `authorization.developer_resolver` | callable\|null | Returns `bool` — whether the current user is a developer |
| `attachments.disk` | string | Storage disk for uploaded attachments (default: `'local'`) |
| `attachments.max_kb` | int | Maximum upload size in kilobytes (default: `10240`) |
| `route_prefix` | string | URL prefix for package routes (default: `'feedback-board'`) |
| `route_middleware` | array | Middleware applied to package routes (default: `['web', 'auth']`) |
| `notifications.developers` | array\|callable | Developer users/notifiables to notify on new reports |
| `notifications.channels` | array | Notification channels (default: `['mail', 'database']`) |
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

## License

MIT
