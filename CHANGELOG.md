# Changelog

All notable changes to `yousefaman/feedback-board` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

The first public release, to be tagged `1.0.0`.

### Added

- **In-app reporting.** The `<x-feedback-board::launcher />` component adds a floating button that opens a feedback form for bugs, feature requests, questions and other reports. Reports can include attachments, validated by size and type, and capture the page URL, browser and locale.
- **My reports.** Reporters follow their own reports and reply in each report's thread.
- **Developer board.** A Kanban board with drag-and-drop status changes. Each report opens in a drawer where developers reply, add internal notes, assign, set priority and status, and read the activity history.
- **Filament plugin (optional).** For Filament 4 and 5: a Kanban board page and a feedback items resource.
- **Access control.** The `feedback-board.manage` gate, `FeedbackBoard::resolveDevelopersUsing()` and `FeedbackBoard::isDeveloper()`. These are safe with `php artisan config:cache`.
- **Notifications.** New reports, replies and resolutions notify the right people by mail. Database notifications are opt-in. Notifications are sent only after the change is saved, and a failure to send never loses the user's action.
- **Destinations.** Report events can be mirrored to a signed webhook or Slack. A developer can open a GitHub issue from a report, and later replies and status changes are added to it as comments. Deliveries are queued, isolated per destination and retried.
- **Ingest endpoint.** Off by default. Through this HMAC-signed endpoint, external systems such as CI or an agent runner add comments to reports, change their status and attach external references.
- **AI triage (Level A).** Opt-in and Anthropic-backed: suggests a type, priority and summary, which a developer applies one field at a time.
- **AI runner contract (Level B/C).** Opt-in: hands a report to your own agent runner and records its status and results. Both directions are HMAC-signed.
- **Install command.** `php artisan feedback-board:install` publishes the config and migrations.
- **Styling.** A self-contained stylesheet that loads automatically. It supports dark mode (`ui.theme`), right-to-left layouts and custom CSS properties for re-branding.
- **Translations.** English and Arabic.
- **Supported versions.** PHP 8.2+, Laravel 12 and 13, Livewire 3.8.3+ and 4.3.4+.

### Upgrading from a pre-release install

These notes apply only to apps that installed the package from `master` before this release.

- **Notification channels.** The default for `notifications.channels` is now `['mail']`; it was `['mail', 'database']`.
  - A config file you published earlier keeps its old value.
  - Keep `'database'` only if you have run `php artisan make:notifications-table`.
- **UI markup.** The markup and `fb-*` class names changed to support the new stylesheet.
  - Views published earlier with `--tag=feedback-board-views` override the new markup and render unstyled. Delete them, or re-publish them with `--force` and re-apply your changes.
  - Host CSS that targeted the old classes may need updating.
- **Database.** The migrations are unchanged.
