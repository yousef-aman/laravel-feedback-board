# Changelog

All notable changes to `yousef-aman/laravel-feedback-board` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-10-15

The first public release.

### Added

- **In-app reporting.** The `<x-feedback-board::launcher />` component adds a floating button for signed-in users (guests see nothing, so it can sit in a layout that also serves a login page) that opens a report form in a slide-over: a type, a title, details, and screenshots or files added by picking, dropping or pasting them. Reports capture the page URL, browser and locale.
- **My reports.** Reporters follow each report's progress, see which reports have unread replies, and talk with the support team in a chat-style conversation that takes images. Inside a Filament panel it is a page of the panel; elsewhere it is a standalone page.
- **Developer board.** A Kanban board with drag-and-drop status changes, type filters, and cards that show the report's screenshot as a cover. Each card opens a dialog where developers set the status, priority and assignee, browse every attachment, read the technical context, and reply to the reporter or leave internal notes, with images, next to a merged activity feed.
- **Filament plugin (optional).** For Filament 4 and 5: "My reports" for every panel user who may report or has reports, the Kanban board and a reports resource, in a translated navigation group with icons and badges. Package screens follow the panel's primary colour and dark mode; `->myReports(false)` turns the reporter page off for a panel. It works on panels with tenancy; reports are app-wide, not scoped to a tenant.
- **Access control.** The `feedback-board.manage` gate, `FeedbackBoard::resolveDevelopersUsing()` and `FeedbackBoard::isDeveloper()`. These are safe with `php artisan config:cache`.
- **Reporters on another guard.** `auth.guards` lists the guards whose users the package recognises, for example staff on `web` and clients on a Filament client panel's own guard. `FeedbackBoard::user()` resolves the signed-in user across them, and the `feedback-board.auth` middleware authenticates the package's routes with it. Inside a panel only the panel's guard counts.
- **Developer guards.** With several guards, `auth.developer_guards` names the guards whose users may be developers. Nobody else is, whatever the developer resolver matches, except through a `Gate::before()` hook, which runs first. Guards whose providers use the same model class cannot be told apart. The package reads each guard's model from its user provider's `model` setting, whatever the driver, as long as it names an Eloquent model class.
- **Choosing who can report.** `FeedbackBoard::resolveReportersUsing()`, `authorization.reporter_resolver`, `FeedbackBoard::canReport()` and the `feedback-board.report` gate. A user who may not report gets a "My reports" link instead of the report button and keeps their earlier reports. The launcher and report creation pass through this gate, so a `Gate::before()` hook that returns `false` for abilities it does not know hides the launcher.
- **Resolvers fail safe.** A developer or reporter resolver that throws, or returns anything other than `true`/`false`, `1`/`0`, `'1'`/`'0'`, `null` or a gate `Response`, denies and is reported once instead of failing the page.
- **Notifications.** New reports, replies and resolutions notify the right people by mail. Database notifications are opt-in. Notifications are sent only after the change is saved, and a failure to send never loses the user's action. A reporter's reply reaches developers with its own notification, which names the report, and reporter text is escaped in e-mails, the Filament bell and Slack.
- **Destinations.** Report events can be mirrored to a signed webhook or Slack. A developer can open a GitHub issue from a report, and later replies and status changes are added to it as comments. Deliveries are queued, isolated per destination and retried after 30 seconds, 2 minutes and 5 minutes. A connection error never puts the webhook URL in a log or a failed job.
- **Image-only replies.** A reply may carry only images. Its e-mail says that a reply with images was added, no GitHub comment is posted (GitHub rejects empty comments), and the webhook payload carries `body: ""`.
- **Ingest endpoint.** Off by default. Through this HMAC-signed endpoint, external systems such as CI or an agent runner add comments to reports, change their status and attach external references. Every answer has its real status and a JSON body, validation errors included; `external_ref` keys follow a fixed format, and the package's own keys are reserved.
- **AI triage (Level A).** Opt-in and Anthropic-backed: suggests a type and priority, which a developer applies one field at a time, and adds a short summary. Triage jobs go to `ai.queue` when it is set.
- **AI runner contract (Level B/C).** Opt-in: hands a report to your own agent runner and records its status and results. Both directions are HMAC-signed. A task with no update for `runner.stale_after` seconds can be re-sent. The reference workflow posts back through a signing, retrying `post-back.sh`, and the reference receiver fails closed.
- **Install command.** `php artisan feedback-board:install` publishes the config and migrations.
- **Styling.** A self-contained stylesheet that loads automatically. It supports dark mode (`ui.theme`), right-to-left layouts and custom CSS properties for re-branding. The standalone pages are light by default; set `FEEDBACK_BOARD_THEME=system` to follow the operating system there.
- **Translations.** English and Arabic.
- **Supported versions.** PHP 8.2+ (8.3+ for Laravel 13), Laravel 12 and 13, Livewire 3.8.3+ and 4.3.4+.

### Upgrading from a pre-release install

These notes apply only to apps that installed the package from its development branch before this release.

- **Notification channels.** The default for `notifications.channels` is now `['mail']`; it was `['mail', 'database']`.
  - A config file you published earlier keeps its old value.
  - Keep `'database'` only if you have run `php artisan make:notifications-table` and then `php artisan migrate`.
- **Published config.** A published `config/feedback-board.php` replaces each top-level section of the package's defaults as a whole, so a section you published earlier does not pick up keys or defaults added since. Compare your file with the package's `config/feedback-board.php`. For example:
  - `notifications.developers` now splits a comma-separated `FEEDBACK_BOARD_DEVELOPER_EMAIL`. An older published file treats a comma-separated value as one invalid address, so none of those developers receive mail.
  - Configs published before the destination drivers have no `destinations.webhook`, `.slack`, `.github` or `.lock_store` keys.
- **Route middleware.** The default `route_middleware` is now `['web', 'feedback-board.auth']`. A published config that lists `'auth'` keeps working for users of the default guard; replace `'auth'` with `'feedback-board.auth'` before you list other guards in `auth.guards`.
- **UI markup.** The markup and `fb-*` class names changed to support the new stylesheet.
  - Views published earlier with `--tag=feedback-board-views` override the new markup and render unstyled. Delete them, or re-publish them with `--force` and re-apply your changes.
  - Host CSS that targeted the old classes may need updating.
  - The report form's modal is now a slide-over, and the board's drawer a dialog.
- **Database (required).** Publish and run the new migration: `php artisan vendor:publish --tag=feedback-board-migrations`, then `php artisan migrate`. It adds the reporter's read marker and last-activity time and marks every existing reply as read. Until it runs, sending a report, replying and moving a card on the board fail, and the "My reports" panel page returns a server error; the rest of the panel keeps working.
- **My reports in the panel.** "My reports" now appears for every user of every panel that registers the plugin who may report or has reports. Call `->myReports(false)` on the plugin to turn it off for a panel.
- **Panel colour.** Inside a panel the accent follows the panel's primary colour. Your `--fb-accent` properties still win, and the manual `--fb-accent: var(--primary-600)` setup is no longer needed.
- **Standalone theme.** With `ui.theme` set to `class`, the standalone pages are now light, because no host page sets the `dark` class there. Set `FEEDBACK_BOARD_THEME=system` to follow the operating system as before.
- **Resolver results.** A resolver result other than `true`/`false`, `1`/`0`, `'1'`/`'0'`, `null` or a gate `Response` now denies and is reported. Strings such as `''` or `'0.0'` used to grant or deny silently, and a count now denies from 2, so return `->exists()`.
- **Developer guards on a custom driver.** A developer guard whose provider uses a custom driver (such as `eloquent-webauthn`) and names an Eloquent model class now has developers; before, it had none and logged a warning.
- **Comment attachments.** The `feedback_attachments.feedback_comment_id` foreign key is now `cascadeOnDelete` (it was `nullOnDelete`). Change the key in your database, or re-create the table, so that deleting a comment can never move its images onto the report.
- **Database notification payload.** The new-report notification's `title` key is now `feedback_title`; with Filament installed, `title` holds Filament's escaped bell title.

[Unreleased]: https://github.com/yousef-aman/laravel-feedback-board/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/yousef-aman/laravel-feedback-board/releases/tag/v1.0.0
