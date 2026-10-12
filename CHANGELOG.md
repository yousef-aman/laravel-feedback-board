# Changelog

All notable changes to `yousef-aman/laravel-feedback-board` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

The first public release, to be tagged `1.0.0`.

### Added

- **In-app reporting.** The `<x-feedback-board::launcher />` component adds a floating button for signed-in users (guests see nothing, so it can sit in a layout that also serves a login page) that opens a report form in a slide-over: a type, a title, details, and screenshots or files added by picking, dropping or pasting them. Reports capture the page URL, browser and locale.
- **My reports.** Reporters follow each report's progress, see which reports have unread replies, and talk with the support team in a chat-style conversation that takes images. Inside a Filament panel it is a page of the panel; elsewhere it is a standalone page.
- **Developer board.** A Kanban board with drag-and-drop status changes, type filters, and cards that show the report's screenshot as a cover. Each card opens a dialog where developers set the status, priority and assignee, browse every attachment, read the technical context, and reply to the reporter or leave internal notes, with images, next to a merged activity feed.
- **Filament plugin (optional).** For Filament 4 and 5: "My reports" for every panel user, the Kanban board and a reports resource, in a translated navigation group with icons and badges. Package screens follow the panel's primary colour and dark mode; `->myReports(false)` turns the reporter page off for a panel.
- **Access control.** The `feedback-board.manage` gate, `FeedbackBoard::resolveDevelopersUsing()` and `FeedbackBoard::isDeveloper()`. These are safe with `php artisan config:cache`.
- **Notifications.** New reports, replies and resolutions notify the right people by mail. Database notifications are opt-in. Notifications are sent only after the change is saved, and a failure to send never loses the user's action.
- **Destinations.** Report events can be mirrored to a signed webhook or Slack. A developer can open a GitHub issue from a report, and later replies and status changes are added to it as comments. Deliveries are queued, isolated per destination and retried.
- **Image-only replies.** A reply may carry only images. Its e-mail says that a reply with images was added, no GitHub comment is posted (GitHub rejects empty comments), and the webhook payload carries `body: ""`.
- **Ingest endpoint.** Off by default. Through this HMAC-signed endpoint, external systems such as CI or an agent runner add comments to reports, change their status and attach external references.
- **AI triage (Level A).** Opt-in and Anthropic-backed: suggests a type and priority, which a developer applies one field at a time, and adds a short summary.
- **AI runner contract (Level B/C).** Opt-in: hands a report to your own agent runner and records its status and results. Both directions are HMAC-signed.
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
- **UI markup.** The markup and `fb-*` class names changed to support the new stylesheet.
  - Views published earlier with `--tag=feedback-board-views` override the new markup and render unstyled. Delete them, or re-publish them with `--force` and re-apply your changes.
  - Host CSS that targeted the old classes may need updating.
  - The report form's modal is now a slide-over, and the board's drawer a dialog.
- **Database (required).** Publish and run the new migration: `php artisan vendor:publish --tag=feedback-board-migrations`, then `php artisan migrate`. It adds the reporter's read marker and last-activity time and marks every existing reply as read. Until it runs, sending a report, replying and moving a card on the board fail, and the "My reports" panel page returns a server error; the rest of the panel keeps working.
- **My reports in the panel.** "My reports" now appears for every user of every panel that registers the plugin. Call `->myReports(false)` on the plugin to turn it off for a panel.
- **Panel colour.** Inside a panel the accent follows the panel's primary colour. Your `--fb-accent` properties still win, and the manual `--fb-accent: var(--primary-600)` setup is no longer needed.
- **Standalone theme.** With `ui.theme` set to `class`, the standalone pages are now light, because no host page sets the `dark` class there. Set `FEEDBACK_BOARD_THEME=system` to follow the operating system as before.
