# Feedback experience redesign — design

> **Status:** approved direction (interactive mockup, 2026-10-10), spec under review.
> **Mockup:** [`assets/2026-10-10-feedback-experience-mockup.html`](assets/2026-10-10-feedback-experience-mockup.html) — open it in a browser; it is the visual reference for every surface below.
> **Package:** `yousef-aman/laravel-feedback-board` · **Builds on:** [`2026-10-10-ui-styling-design.md`](2026-10-10-ui-styling-design.md) (stylesheet architecture, token contract, delivery).

## 1. Why

Dogfooding in a real Filament 4 app (Arabic, RTL, pink primary) showed that the package looks bolted on:

- the "My reports" page is a bare standalone page outside the panel, always dark (it followed the OS);
- the report form is a small centred modal with a native select and an English native file input;
- the package's navigation items have no icons, and the resource is labelled "Feedback Items" in English;
- the developer board shows text-only cards; screenshots — the most useful part of a bug report — are only download links;
- conversation is text only.

The owner reviewed an interactive mockup and approved it, then asked for Trello-style cards (cover image), comments with images on both sides, and image uploads inside comments.

## 2. Goals

1. **Reporter** (any authenticated user): reports a problem from a slide-over, attaches screenshots by drag, paste or picker, and follows the report and its conversation (with images) from "My reports" — inside the Filament panel when there is one.
2. **Developer**: triages from a Kanban whose cards show the screenshot as a cover, and works a report in a Trello-style dialog: content on one side, comments and activity on the other, replies and internal notes with images.
3. **Filament-native**: the package's pages sit in the panel's navigation with icons and translated labels, use the panel's primary colour and dark mode, and never look like a foreign page.
4. **Non-Filament apps** get the same components on standalone pages with a simple app header, light by default.

## 3. Non-goals

- Trello features that were not asked for: checklists, due dates, labels, members beyond the assignee, card covers chosen manually.
- Server-side thumbnail generation (no GD/Imagick dependency). Images are served as-is with lazy loading.
- Multi-guard reporters (a panel with its own `authGuard`, e.g. new-esaad's beneficiary panel). The package keeps using the default guard; documented as a limitation.
- Links from notification e-mails to a specific report; real-time updates of the conversation.

## 4. Surfaces

The mockup is normative for layout, hierarchy, copy and states. This section fixes the behaviour and the contracts the mockup cannot show.

### 4.1 Launcher and the new-report slide-over

- The floating launcher stays at the bottom inline-end corner (bottom-left in RTL). It becomes a pill: icon + label (`launcher`); below 560 px only the icon shows (label kept as the accessible name).
- It opens a **slide-over** anchored to the inline-end edge (left in RTL, right in LTR), full height, `min(460px, 100%)` wide, with a backdrop. It replaces the centred `fb-modal`.
- Slide-over contract (shared Blade component `x-feedback-board::slide-over`, also used by My reports): `role="dialog"`, `aria-modal="true"`, `aria-labelledby` on its title, Alpine focus trap with `.noscroll`, Escape and backdrop click close it, focus returns to the opener. Enter animation is a CSS keyframe so `prefers-reduced-motion` can disable it (same reason as the existing modal).
- Form (`FeedbackForm`), top to bottom:
  1. **Type** as four radio cards (bug, feature, question, other) with an icon and a one-line hint each; `wire:model="type"`; a real `<fieldset>`/`<legend>`; the selected card shows the accent ring.
  2. **Title** (required) with an example placeholder.
  3. **Details** textarea with a guiding placeholder.
  4. **Attachments** dropzone: a `<label>` for a visually hidden `<input type="file" multiple>`. Drag-and-drop on the zone and paste (Ctrl/Cmd+V) anywhere in the form add files. Selected files list below with an image preview (Livewire `temporaryUrl()` when `isPreviewable()`, otherwise a file icon), name, size and a remove button. The allowed types and size hint stays (it reads `AttachmentStorage::limits()`).
  5. **Context notice**: one line telling the reporter that the page URL and browser are attached automatically, with the URL shown LTR.
  6. **Sticky footer**: primary "Send report", secondary "Cancel", and a "My reports" link.
- Sent state: success icon, "We received your report", the reference `#ID`, and two actions: **Follow the report** (opens My reports on that report, §4.2 deep link) and **New report** (resets the form). It keeps the current focus behaviour (`role="status"`, focused on render) and the reset-on-reopen behaviour (`fb-launcher-opened`).
- "My reports" links resolve with `Ui::reportsUrl()` (§4.6): the panel page inside Filament, the standalone page otherwise. The URL is computed in `mount()` and stored in a `#[Locked]` property, because Livewire update requests carry no panel context.

### 4.2 Reporter: My reports

Component `MyReportsBoard` (redesigned), rendered by the Filament page (§4.6) and by the standalone page (§4.7).

- **Header**: title, subtitle, and a **New report** button that opens the same new-report slide-over (an instance of `FeedbackForm` inside the page; the floating launcher may also be on the page — both work independently, and `feedback-submitted` refreshes the list).
- **Tabs**: All / Open (`new`, `in_review`, `in_progress`) / Done (`resolved`, `closed`, `declined`), each with a count. The selected tab is a `#[Url(as: 'tab')]` property.
- **List** (one row per report, newest activity first): type icon tile, title (single line, ellipsis, `dir="auto"`), `#ID · type`, status badge, last update (`updated_at`, relative), and the count of non-internal comments with an **unread dot** when the report has unread developer replies (§5.3). Rows are buttons. Empty state per tab.
- **Report slide-over** (wide variant, `min(560px, 100%)`), component `FeedbackItemModal` (redesigned):
  - header: type tile, title, `#ID · type · status badge`, close;
  - **status steps**: New → In review → In progress → Resolved; for `closed`/`declined` all steps are complete and the last step shows that status;
  - meta: sent time; page URL (LTR, never a link here — the reporter already knows it);
  - description, then the report's own image attachments as a thumbnail grid, then non-image attachments as file links;
  - **conversation**: non-internal comments only, chat-style — the reporter's on the inline-start side, the support team's on the inline-end side with the accent tint, author label from `AuthorRole::label()`, relative time, a "new" tag on replies created after the previous read, and each comment's images as thumbnails;
  - empty conversation: "No reply yet. We'll notify you when someone replies."
  - **composer** (sticky footer): attach button, auto-height textarea, Send. Images can also be pasted. Pending images show as removable thumbnails above the field. A reply needs text or at least one image.
  - Opening the slide-over **marks the report read** (§5.3).
- **Deep link**: `#[Url(as: 'report')] public ?int $selectedId`. Opening a row updates the URL; loading `?report=ID` opens it. Selection stays scoped to the reporter's own items (as today), so another user's ID opens nothing.
- The reporter never sees internal notes, internal-note images, or the activity log.

### 4.3 Developer: Kanban

Component `FeedbackKanban` (redesigned). Developers only (unchanged guards).

- **Filters**: chips for All / bug / feature / question / other, each with a count; `#[Url(as: 'type')]`.
- **Columns**: one per `FeedbackStatus`, in enum order, header with status dot, label and count; horizontal scroll; empty state.
- **Card** (a `role="button"` element, keyboard-activatable as today):
  - **cover**: the report's first image attachment (§5.2), `16:9`, `object-fit: cover`, top-aligned, `loading="lazy"`, `decoding="async"`, empty `alt` (decorative — the title follows);
  - chips: priority (coloured by `FeedbackPriority::color()`) and type (icon + label);
  - title, clamped to three lines;
  - footer: `#ID`, comment count, attachment count, internal-note count (lock icon, warning colour), and avatars (initials) for the reporter and the assignee.
- **Drag and drop**: the existing contract is kept verbatim — `dataTransfer` key `id`, `$wire.moveTo(id, status)`, the `getData('id') &&` drop guard, `types.includes('id')` enter/leave counters, `fb-col--over`, `fb-card--dragging`.
- Clicking a card opens the **card dialog** (§4.4) instead of the side drawer.

### 4.4 Developer: card dialog

The existing `fb-drawer` around `FeedbackDetail` becomes a centred **dialog**: `min(1060px, 100% - 2×18px)` wide, full height minus margins, rounded, with a backdrop; below 920 px it is full-screen with a single scrolling column. Same a11y contract as the slide-over (role, aria-modal, labelled by the title, focus trap, Escape, backdrop click, focus return).

- **Cover band** (150 px; 64 px when there is no image): the cover image centred on a neutral band, click to enlarge; on top, the **status select** (inline-start) and the **close** button (inline-end).
- **Main column** (wider):
  - type tile, title (`h2#fb-drawer-title`, kept for the existing tests), sub-line `#ID · type · sent by NAME, TIME`;
  - properties: **priority** select (now with a "No priority" option that is selectable, §5.4), **assignee** (avatar + name, Assign to me / Unassign), **reporter** (avatar + name);
  - description;
  - **attachments gallery**: every attachment of the report and of its comments; images as thumbnails (click → lightbox), others as file tiles with a download link; each tile shows name and relative time;
  - **technical context** in a closed `<details>`: page URL (http(s) only becomes a link, as today), browser, locale;
  - the existing **AI triage** and **runner** panels, restyled only. Their markup contract is kept: `wire:key`, the conditional literal `wire:poll.5s` attributes, `dir="auto"` on the summary, the button labels and `wire:click` names.
  - **Send to GitHub** keeps its current conditions and action.
- **Side column — Comments and activity**:
  - header with a **Hide activity / Show activity** toggle (Alpine; remembered per browser in `localStorage`, wrapped in try/catch);
  - **composer**: a segmented toggle *Reply to reporter* / *Internal note* (`aria-pressed`), one textarea, attach button, paste support, pending thumbnails, a hint line that states who will see it ("The reporter sees this and gets notified" / "Only developers see this; no notification"). Internal mode tints the composer with the warning colour;
  - **feed**, newest first: comments (avatar, name, role tag, time, body, image thumbnails; internal notes tinted with a lock tag "Internal · hidden from the reporter") interleaved with activity lines (§5.4).

### 4.5 Lightbox

Each component that renders images (`FeedbackItemModal`, `FeedbackDetail`) owns one lightbox in its root Alpine scope (no window events, so two components on a page never open two lightboxes). Thumbnails are `<button>`s carrying the inline image URL and name; the lightbox is a dialog with the image, its name, a close button, Escape and backdrop close, and a "download" link to the original.

### 4.6 Filament integration

`FeedbackBoardPlugin` registers:

| Page / resource | Who sees it | Navigation |
|---|---|---|
| `MyFeedbackPage` (new) — renders `MyReportsBoard` | any authenticated panel user | label `nav.my_reports`, icon `heroicon-o-chat-bubble-left-right`, badge = number of reports with unread replies (primary colour), sort 1 |
| `FeedbackBoardPage` — renders `FeedbackKanban` | developers | label `board.title`, icon `heroicon-o-view-columns`, badge = open reports (gray), sort 2 |
| `FeedbackItemResource` | developers | label `nav.all_reports`, icon `heroicon-o-inbox-stack`, sort 3 |

- All three share the navigation group `nav.group` ("Feedback" / "الملاحظات"). Plugin fluent options, each optional: `navigationGroup(?string)` (null = no group), `myReports(bool)` (default true) to not register the reporter page.
- `getTitle()`/`getNavigationLabel()` keep the existing override pattern (`static::$title ?? __()`).
- **Resource**: translated model labels; columns with translated headers; `type`/`status`/`priority` badges show `label()` and `color()` through `formatStateUsing`/`color` closures (the enums must not implement Filament interfaces — Filament is optional); `created_at` relative with the full date as tooltip; "Send to GitHub" label translated.
- **`Ui::reportsUrl()`**: when Filament is installed and the current panel has the plugin with the reporter page enabled, `MyFeedbackPage::getUrl()`; otherwise `route('feedback-board.page')`. Accepts an optional report ID for the deep link.
- **Panel colours and dark mode**: inside `.fi`, the package accent follows the panel's primary colour. The plugin registers a panel render hook (`PanelsRenderHook::STYLES_AFTER`) that prints one small `<style>` (with the Vite CSP nonce when set) defining `--fb-fi-accent` (the panel primary 600), `--fb-fi-accent-hover` (500) and `--fb-fi-accent-contrast` — white or the primary 950 shade, whichever passes WCAG AA against the 600 shade, computed with Filament's own `Color::isTextContrastRatioAccessible()`. The stylesheet reads them **under** the public tokens: `--_fb-accent: var(--fb-accent, var(--fb-fi-accent, #4f46e5))`, so a host override still wins and a panel without the hook keeps indigo. Dark mode is unchanged (`ui.theme = class`, which follows Filament's `.dark`).

### 4.7 Standalone pages (apps without Filament)

- Both pages get a thin header: the app name (`config('app.name')`) and a "Back to the app" link to `url('/')`; content width up to 1200 px; the same components as the panel.
- **Theme**: with `ui.theme = class` the standalone pages are **light** (there is no host `.dark` class to follow). `system` follows the OS; `light`/`dark` force. `Ui::colorScheme()` and the stylesheet's standalone rules change accordingly. This reverses the earlier "standalone follows the OS" ruling, which produced the always-dark page.
- The standalone reports page keeps the new-report form reachable through the header button (slide-over), not inline.

## 5. Data and backend

### 5.1 Comment attachments

- The schema already supports them (`feedback_attachments.feedback_comment_id`). No migration.
- `FeedbackItemModal::reply()` and `FeedbackDetail::reply()/internalNote()` accept pending files (`public array $files`, same rules as the form). Validation: `body` is `required_without:files`; `files` is `array|max:{attachments.max_files}`; `files.*` uses `AttachmentStorage::allowedRules()`.
- After `ReplyToFeedback::handle()` returns the comment, each file is stored with `AttachmentStorage::store($file, $item, $comment)`. Storage happens after the comment's transaction (same pattern as report creation: disk writes cannot roll back). A storage failure is reported and does not delete the comment.
- New config key `attachments.max_files` (default `10`), used by the report form and both composers.

### 5.2 Serving images inline

- `AttachmentController::show` keeps its authorization and adds one rule: an attachment that belongs to an **internal** comment requires a developer (today any viewer of the item could fetch it by ID — a leak this feature would otherwise make visible).
- Images in an allow-list (`image/jpeg`, `image/png`, `image/gif`, `image/webp`, judged by the stored `mime` **and** the file extension) are served `inline` with `Content-Type` from the allow-list, `X-Content-Type-Options: nosniff`, `Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox`, and `Cache-Control: private, max-age=3600`. Everything else (including SVG) keeps being a download. `?download=1` forces a download for images too.
- `FeedbackAttachment::isImage()` encodes the allow-list; `FeedbackItem::coverAttachment()` returns the oldest image attachment of the report itself (`feedback_comment_id` null).

### 5.3 Unread replies

- New migration `add_reporter_read_at_to_feedback_items_table`: nullable `reporter_read_at` timestamp on `feedback_items`. Registered in the service provider's `hasMigrations()` list so `feedback-board:install` and `vendor:publish --tag=feedback-board-migrations` publish it.
- **Unread** = non-internal comments with `author_role = developer` created after `COALESCE(reporter_read_at, feedback_items.created_at)`.
- The reporter opening a report, or replying to it, sets `reporter_read_at = now()` — only when the viewer owns the item (a developer opening it changes nothing).
- Queries: a `scopeWithUnreadCount()` on `FeedbackItem` (one correlated `withCount`, portable `COALESCE`), used by the list; the navigation badge counts the reporter's items having any unread reply (one `whereExists` query; returns null when zero).
- `FeedbackComment` gets `protected $touches = ['item']` so a new comment updates the report's `updated_at` (the list's "last update" and order).

### 5.4 Activity feed

- New `ActivityAction::PriorityChanged` (`priority_changed`); `FeedbackDetail::setPriority()` records it with `properties: {from, to}`, and accepts an empty value to clear the priority (the select's "No priority" option).
- New `FeedbackActivity::sentence(?string $actor)` returns a full line with the actor's name: created, moved from A to B, assigned to X / unassigned, priority changed to P. Translations use `:actor`, `:from`, `:to`, `:assignee`; a missing actor name falls back to the role ("Someone"). `summary()` stays for existing callers.
- The developer feed merges comments and activities by time, newest first, and **omits `Replied` activities** (the comment itself is the record). Actors and assignees are eager-loaded; names come from `name`, then `email`, then `#id`.

### 5.5 Uploads UX (form and composers)

- Livewire's `wire:model` on a multiple file input replaces the selection, so new picks, drops and pastes go to a staging property (`uploads`) whose `updatedUploads()` hook **appends** to `files` (up to `max_files`) and clears the stage. `removeFile(int $index)` drops one pending file.
- Paste and drop are Alpine handlers that call `$wire.uploadMultiple('uploads', files)`; only `File` items from `clipboardData.files`/`dataTransfer.files` are used. An upload progress line and errors (`files`, `files.*`, `uploads.*`) are shown.

## 6. Styling

- The architecture of the UI styling spec stays: one self-contained stylesheet, `.fb-scope` roots, `--fb-*` public tokens only read, private `--_fb-*` aliases, logical properties, no `!important`/`@layer`/`url(`/`:has`/nesting/`color-mix`, reduced-motion rule last, forced-theme backgrounds, coverage test (every class used in a view is styled).
- New component blocks: slide-over, dialog, lightbox, radio cards, dropzone, file list and pending thumbnails, tabs, report rows, steps, chat bubbles, Kanban card cover/chips/footer/avatars, filter chips, gallery, feed entries, internal tint.
- Removed: `fb-modal*`, the drawer around the Kanban detail (the reporter's drawer becomes the wide slide-over), and the inline-form layout of the standalone reports page.
- Icons: inline SVG (Heroicons outline paths), `aria-hidden`.
- Avatars: initials in a circle; the tint is chosen from four accent tints by a stable hash of the name.

## 7. Translations

All new strings in `en` and `ar` (parity test stays): navigation (`nav.group`, `nav.my_reports`, `nav.all_reports`), type hints, dropzone and paste hints, context notice, sent state, tabs, steps, conversation labels and empty states, composer modes and hints, feed sentences, gallery and lightbox labels, resource labels and column headers, standalone header.

## 8. Security

- Authorization is unchanged except the internal-attachment rule (§5.2). All writes keep their guards (`authorize('reply')` for the reporter, `guard()` for developers).
- Uploaded files keep server-side validation (`mimes`, `max`) on the final store; staging uploads go through Livewire's temporary-upload rules. SVG is never served inline.
- No user text becomes HTML: everything is escaped Blade output; `dir="auto"` on user text.
- The theme `<style>` hook prints only values derived from the panel's registered colours, sanitised like Filament does (`Str::sanitizeCssColor`).

## 9. Performance

- Kanban: one query for items with `withCount` (comments, attachments, internal notes), eager-loaded cover attachment and assignee — no per-card queries. Covers are full images, lazy-loaded; documented as a trade-off (no thumbnails).
- Navigation badges run on every panel page: one cheap query each, indexed by the existing reporter columns.

## 10. Compatibility and upgrade

- Laravel 12/13, Livewire 3.8.3+/4.3.4+, Filament 4/5 — all lanes keep passing.
- **New migration** (§5.3): existing installs publish and run it. CHANGELOG upgrade note.
- **Markup and class changes** again (modal → slide-over, drawer → dialog): views published earlier must be re-published. CHANGELOG note.
- Notification e-mails are unchanged.

## 11. Testing

TDD per task. Coverage that must exist when done:

- Form: radio-card type binding, appending uploads across picks, `max_files`, removing a pending file, preview only for previewable files, sent state with `#ID` and the follow link, reset on reopen, focus contract.
- Comment attachments: reporter and developer replies with images and with images only; internal-note images hidden from the reporter in views **and** forbidden by the attachment route.
- Inline serving: allow-listed images inline with the security headers; SVG, PDF and mismatched extension/mime are downloads; `?download=1`.
- Unread: counts after developer replies, not after internal notes or the reporter's own replies; reading by opening and by replying; only the owner's read counts; badge query.
- Activity: priority change recorded and clearable; sentences with and without actor names; `Replied` omitted from the merged feed; ordering.
- Kanban: cover choice, counts, avatars, filters, the drag-and-drop contract strings unchanged.
- Filament (real panel, both majors): navigation group, icons, labels, badges, `MyFeedbackPage` for a non-developer, board and resource still developer-only, `Ui::reportsUrl()` inside and outside a panel, the theme-vars hook with and without a CSP nonce.
- Standalone: header, light default for `class`, `system` media query.
- Stylesheet: coverage, scoping, token contract and dark parity tests extended to the new classes.
- Browser pass (Playwright, as in the UI styling cycle): en/ar, light/dark, 1280/390, Filament panel and a plain host; screenshots compared against the mockup.

## 12. Review focus

The inputs most likely to break for a real user that no single test names:

1. A pasted screenshot from the clipboard (unnamed `image.png` blob) on Livewire 3 and 4.
2. A report whose only attachment is a large phone photo (8–10 MB) on a board with many cards.
3. An RTL panel with a long English title and an English URL in the same card and dialog.
4. A host whose primary colour is light (amber/yellow): button text must stay readable.
5. Opening `?report=ID` for a report that belongs to someone else, or that does not exist.
