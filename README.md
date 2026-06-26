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

## License

MIT
