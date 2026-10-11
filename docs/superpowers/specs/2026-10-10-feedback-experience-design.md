# Feedback experience redesign — design

> **Status:** approved direction (interactive mockup, 2026-10-10). Revised after two adversarial review rounds: the first found 1 Critical, 17 Important and 15 Minor issues, the second 3 Important and 13 Minor; all are resolved below. Awaiting the owner's review.
> **Mockup:** [`assets/2026-10-10-feedback-experience-mockup.html`](assets/2026-10-10-feedback-experience-mockup.html) — open it in a browser; it is the visual reference for every surface below.
> **Package:** `yousef-aman/laravel-feedback-board` · **Builds on:** [`2026-10-10-ui-styling-design.md`](2026-10-10-ui-styling-design.md) (stylesheet architecture, token contract, delivery).

## 1. Why

Dogfooding in a real Filament 4 app (Arabic, RTL, pink primary) showed that the package looks bolted on:

- the "My reports" page is a bare standalone page outside the panel, always dark (it followed the OS);
- the report form is a small centred modal with a native select and an English native file input;
- the package's navigation items have no icons, and the resource is labelled "Feedback Items" in English;
- the developer board shows text-only cards; screenshots, the most useful part of a bug report, are only download links;
- the conversation is text only.

The owner reviewed an interactive mockup and approved it, then asked for Trello-style cards (cover image), comments with images on both sides, and image uploads inside comments.

## 2. Goals

1. **Reporter** (any authenticated user): reports a problem from a slide-over, attaches screenshots by drag, paste or picker, and follows the report and its conversation (with images) from "My reports", inside the Filament panel when there is one.
2. **Developer**: triages from a Kanban whose cards show the screenshot as a cover, and works a report in a Trello-style dialog: content on one side, comments and activity on the other, replies and internal notes with images.
3. **Filament-native**: the package's pages sit in the panel's navigation with icons and translated labels, use the panel's primary colour and dark mode, and never look like a foreign page.
4. **Apps without Filament** get the same components on standalone pages with a simple app header, light by default.

## 3. Non-goals

- Trello features nobody asked for: checklists, due dates, labels, members beyond the assignee, manually chosen covers.
- Server-side thumbnails (no GD/Imagick dependency). Images are served as-is with lazy loading.
- Multi-guard reporters (a panel with its own `authGuard`, e.g. new-esaad's beneficiary panel). The package keeps the default guard; documented as a limitation.
- Links from notification e-mails to a specific report; real-time conversation updates.
- A configurable navigation group (YAGNI; the group is fixed and translated).

## 4. Surfaces

The mockup is normative for layout, hierarchy, copy and states. This section fixes the behaviour and the contracts the mockup cannot show.

### 4.1 Launcher and the new-report slide-over

- The floating launcher stays at the bottom inline-end corner (bottom-left in RTL). It becomes a pill: icon + label (`launcher`); below 560 px only the icon shows and the label stays as its accessible name.
- It opens a **slide-over** anchored to the inline-end edge (left in RTL, right in LTR), full height, `min(460px, 100%)` wide, with a backdrop. It replaces the centred `fb-modal`.
- **Slide-over contract** (Blade component `x-feedback-board::slide-over`, props: `id` (required, unique per page), `labelledby`, `wide`): `role="dialog"`, `aria-modal="true"`, `aria-labelledby`, Alpine focus trap with `.noscroll`, Escape and backdrop click close it, focus returns to the opener (or to the page heading when opened from a deep link). Enter animation is a CSS keyframe so `prefers-reduced-motion` can disable it. The launcher's instance keeps the id `fb-launcher-title` for its title.
- **Form** (`FeedbackForm`), top to bottom:
  1. **Type** as four radio cards (bug, feature, question, other), each with an icon and a one-line hint; `wire:model="type"`; a real `<fieldset>` and `<legend>`; the selected card shows the accent ring and tint (§6.2).
  2. **Title** (required) with an example placeholder.
  3. **Details** textarea with a guiding placeholder.
  4. **Attachments dropzone**: a `<label>` wrapping a visually hidden but focusable `<input type="file" multiple>` (focus shown with `:focus-within`). Picking, dropping on the zone, and pasting anywhere in the form all go through the same upload path (§5.5). The selected files list shows a preview for allow-listed images (§5.2) and a file icon otherwise, the name, the size, and a remove button. The allowed-types and size hint stays (`AttachmentStorage::limits()`), plus the file-count limit.
  5. **Context notice**: one line saying the page URL and browser are attached automatically, with the URL shown LTR.
  6. **Sticky footer**: primary "Send report", secondary "Cancel", and a "My reports" link. The submit button is disabled while uploads are in flight through the Alpine upload queue's state (`x-bind:disabled="uploading > 0"`, §5.5), plus `wire:loading.attr="disabled"` targeting `submit`. `wire:target="upload"` cannot see `$wire.upload()` calls.
- **Ids**: every id inside the form derives from the component id (`$this->getId()`), so two forms on a page never collide.
- **Sent state**: success icon, "We received your report", the reference `#ID` (`public ?int $sentId`), and two actions: **Follow the report** and **New report** (resets the form). The current focus contract (`role="status" tabindex="-1"` focused on render) and the reset on `fb-launcher-opened` stay.
- **Report links**: `mount()` stores the base URL from `Ui::reportsUrl()` (§4.6) in a `#[Locked]` property. The follow link is that base URL plus `?report={sentId}`, built at render time, because the id only exists after submit.

### 4.2 Reporter: My reports

Component `MyReportsBoard` (redesigned), rendered by the Filament page (§4.6) and the standalone page (§4.7). In a Filament panel the page heading, subheading and the **New report** header action belong to the Filament page; the component hides its own header (`$inPanel`, set by the page).

- **New report** opens a slide-over with a `FeedbackForm` instance owned by this component (the floating launcher may also be on the page; both work independently, and `feedback-submitted` refreshes the list). The Filament header action dispatches the browser event `fb-open-report-form`, which only this component listens to.
- **Filter buttons** (plain buttons with `aria-pressed`, not ARIA tabs): All / Open (`new`, `in_review`, `in_progress`) / Done (`resolved`, `closed`, `declined`), each with a count.
- **List**: a column header row (Report, Status, Last update, Replies), then one row per report ordered by `reporter_activity_at` desc (§5.3): type icon tile, title (one line, ellipsis, `dir="auto"`), `#ID · type`, status badge, last update (`reporter_activity_at`, relative), and the count of non-internal comments with an **unread dot** plus visually hidden text "new reply" when unread (§5.3). Rows are buttons. Empty state per filter.
- **Report slide-over** (wide variant, `min(560px, 100%)`), component `FeedbackItemModal` (redesigned):
  - header: type tile, title, `#ID · type · status badge`, close;
  - **status steps** for `new`, `in_review`, `in_progress`, `resolved`: New → In review → In progress → Resolved, the current one with `aria-current="step"`. For `closed` and `declined` the steps are replaced by a terminal banner ("This report was closed" / "This report was declined") with the time of that change. The time is the `created_at` of the latest `StatusChanged` activity (read server-side; the reporter still never sees the log);
  - meta: sent time; page URL as LTR text (never a link here);
  - description, the report's own image attachments as a thumbnail grid, and its non-image attachments as file links;
  - **conversation**: non-internal comments only, **unconditionally** (also when the viewer is a developer), chat-style: the viewer's own comments on the inline-start side labelled "You", everyone else's on the inline-end side with the accent tint and the author's role label (`AuthorRole::label()`). Each shows the relative time, a "new" tag when it arrived after the previous read (`readBefore`, below), and its image thumbnails;
  - empty conversation: "No reply yet. We'll notify you when someone replies.";
  - **composer** (sticky footer): an attach-image button, an auto-height textarea, and Send. Images can also be pasted. Pending images show as removable thumbnails above the field. A reply needs text or at least one image. Composer attachments are **images only** (§5.1).
- **Reading** is owned by `MyReportsBoard`, not by the child (§5.3):
  - `open(id)` captures the report's current `reporter_read_at` as `readBefore`, then marks it read.
  - `mount()` does the same when `?report` is present.
  - The child receives `readBefore` as a locked prop.
  - The list renders after the mark, so the opened row loses its dot.
  - The Filament sidebar badge refreshes on the next full page load.
  - **After a reply**, the child `FeedbackItemModal` writes `reporter_read_at = now()` itself, with the same owner check and the same `toBase()->update()`. `readBefore` does not change, so tags already shown stay.
- **Deep link**: `#[Url(as: 'report')]` on an **untyped** property. `#[Url]` JSON-decodes `?report=5` to an integer, so `render()` normalises with `$id = is_int($v) ? $v : (is_string($v) && ctype_digit($v) ? (int) $v : null)`. `ctype_digit()` must never receive an int: it reads ints from −128 to 255 as ASCII codes. Selection stays scoped to the reporter's own items, so another user's id, a missing id, `abc` or an array opens nothing and never errors. This matters on Livewire 3, which assigns query values without a type guard.
- The reporter never sees internal notes, internal-note images, or the activity log.

### 4.3 Developer: Kanban

Component `FeedbackKanban` (redesigned). Developers only (unchanged guards). In a panel, the Filament page heading and subheading carry the title and the line "Drag a card between columns to change its status; click it to open."

- **Filter chips**: All / bug / feature / question / other, each with a count. `#[Url(as: 'type')]` sits on an untyped property, normalised with `is_string($v) ? FeedbackType::tryFrom($v) : null`. Under `strict_types`, `tryFrom()` throws for an int, an array, a bool or null.
- **Columns**: one per `FeedbackStatus`, in enum order, header with status dot, label and count; horizontal scroll; empty state.
- **Card** (`role="button"`, keyboard-activatable as today):
  - **cover**: the report's cover attachment (§5.2), `16:9`, `object-fit: cover`, top-aligned, `loading="lazy"`, `decoding="async"`, empty `alt` (decorative; the title follows), and an `x-on:error` fallback that hides a broken cover;
  - chips: priority (coloured by `FeedbackPriority::color()`) and type (icon + label);
  - title, clamped to three lines;
  - footer: `#ID`, comment count, attachment count, internal-note count (lock icon, warning colour), and avatars for the reporter and the assignee.
- **Drag and drop** keeps the existing contract verbatim: `dataTransfer` key `id`, `$wire.moveTo(id, status)`, the `getData('id') &&` drop guard, the `types.includes('id')` enter/leave counters, `fb-col--over`, `fb-card--dragging`.
- Clicking a card opens the **card dialog** (§4.4) instead of the side drawer.

### 4.4 Developer: card dialog

The `fb-drawer` around `FeedbackDetail` becomes a centred **dialog**: `min(1060px, 100% - 36px)` wide, full height minus 18 px margins, rounded, with a backdrop. Below 920 px it is full-screen with one scrolling column. Same a11y contract as the slide-over.

- **Cover band** (150 px; 64 px without a cover image): the cover centred on a neutral band, click to enlarge. On top of it, the **status select** sits at the inline-start and the **close** button at the inline-end.
- **Main column** (wider):
  - type tile, title (`h2#fb-drawer-title`, kept), sub-line `#ID · type · sent by NAME, TIME`;
  - properties: **priority** select with a selectable "No priority" option (§5.4); **assignee** (avatar + name, Assign to me / Unassign); **reporter** (avatar + name);
  - description;
  - **attachments gallery**: every attachment of the report and of its comments, including internal notes (developers only). Images show as thumbnails that open the lightbox; other files show as tiles with a download link. Each tile shows the name and relative time;
  - **technical context** in a closed `<details>`: page URL (an http(s) URL becomes a link, as today), browser, locale;
  - the existing **AI triage** and **runner** panels, restyled only. Their markup contract stays: `wire:key`, the conditional literal `wire:poll.5s` attributes, `dir="auto"` on the summary, the button labels and the `wire:click` names. The suggested type and priority already appear in the "Apply …" buttons; no extra chips;
  - a **Send to GitHub** button (new in this view; today only the resource has it). It appears under the same conditions as the resource action and calls the existing `sendToGithub()`, which gains the missing `guard()`.
- **Side column, "Comments and activity"**:
  - header with a **Hide activity / Show activity** toggle (Alpine; remembered per browser in `localStorage`, wrapped in try/catch);
  - **composer**: a segmented control *Reply to reporter* / *Internal note* (buttons with `aria-pressed`, default reply). One textarea is bound to `body`, with an attach-image button, paste support and pending thumbnails. A hint line states who will see it: "The reporter sees this and gets notified" or "Only developers see this; no notification". Internal mode tints the composer with the warning colour. The form's submit calls `internalNote()` when the Alpine mode is internal and `reply()` otherwise (`x-on:submit.prevent="mode === 'internal' ? $wire.internalNote() : $wire.reply()"`). Both methods share `body`, `files` and the same validation. The `note` property is removed;
  - **feed**, newest first: comments (avatar, name, role tag, time, body, image thumbnails; internal notes tinted, with a lock tag "Internal · hidden from the reporter") interleaved with activity lines (§5.4).

### 4.5 Lightbox

Each component that renders images (`FeedbackItemModal`, `FeedbackDetail`) owns one lightbox in its root Alpine scope (no window events). Thumbnails are `<button>`s whose image URL and file name live in `data-*` attributes read through `$el.dataset` (§8). The lightbox is a dialog with the image, its name, a close button, a download link (`<a download>` on the same URL), backdrop close, and Escape bound on the lightbox root with `.stop`, so closing the lightbox does not also close the dialog under it. Focus moves to its close button and returns to the thumbnail.

### 4.6 Filament integration

`FeedbackBoardPlugin` registers:

| Page / resource | Who sees it | Navigation |
|---|---|---|
| `MyFeedbackPage` (new, slug `my-feedback`, view in `resources/views-filament/`) renders `MyReportsBoard` | any authenticated panel user | label `nav.my_reports`, icon `heroicon-o-chat-bubble-left-right`, badge = reports with unread replies (primary), sort 1 |
| `FeedbackBoardPage` renders `FeedbackKanban` | developers | label `board.title`, icon `heroicon-o-view-columns`, badge = open reports (gray), sort 2 |
| `FeedbackItemResource` | developers | label `nav.all_reports`, icon `heroicon-o-inbox-stack`, sort 3 |

- All three are in the navigation group `nav.group` ("Feedback" / "الملاحظات"). Labels and the group are translated **inside** the `getNavigation*()` methods at call time, not at panel registration (panels are built in `PanelProvider::register()`, before the locale middleware runs).
- Plugin option `myReports(bool $condition = true)`. Pages read it from `Filament::getCurrentPanel()?->getPlugin('feedback-board')` at call time, never from static state, so two panels can differ.
- `getTitle()` and `getNavigationLabel()` keep the existing override pattern (`static::$title ?? __()`). `MyFeedbackPage` maps the mockup header to `getHeading()`, `getSubheading()` and `getHeaderActions()`.
- **Resource**: translated model labels; translated column headers; `type`, `status` and `priority` badges show `label()` and `color()` through `formatStateUsing` and `color` closures (the enums do not implement Filament interfaces, because Filament is optional); `created_at` relative with the full date as a tooltip; the "Send to GitHub" label translated.
- **`Ui::reportsUrl(?int $reportId = null)`**:
  - Inside a panel whose plugin has the reporter page enabled, it returns `MyFeedbackPage::getUrl()`. It takes the panel from `Filament::getCurrentPanel()`, then calls that **Panel's** own `hasPlugin('feedback-board')` before `getPlugin()`. `Panel::getPlugin()` throws when the plugin is absent. The `Filament::` facade helpers `hasPlugin()`/`getPlugin()` are not used, because they fall back to the default panel and could link a host-page user to a panel they cannot open.
  - Otherwise it returns `route('feedback-board.page')`.
  - In both cases it appends `?report=ID` when an id is given.
- **Panel colours.**
  - The plugin registers a panel-scoped render hook (`PanelsRenderHook::STYLES_AFTER`). It prints one `<style>` (with the Vite CSP nonce when set) that defines three bridge tokens on `:root`:
    - `--fb-panel-accent`: the primary 600 shade;
    - `--fb-panel-accent-hover`: the 500 shade;
    - `--fb-panel-accent-contrast`: white or the 950 shade, whichever passes WCAG AA against 600. If neither passes, whichever has the higher ratio. It is computed with Filament's `Color::calculateContrastRatio()`.
  - Values are printed only if they match the package's own allow-list: `oklch(...)` with numbers only, or hex. `Str::sanitizeCssColor` is absent in early Filament 4.x (verified in 4.0.0 and 4.1.6), so the package does not use it. If a shade is missing, nothing is printed.
  - The stylesheet reads the bridge tokens **under** the public ones, only inside `.fi .fb-scope`, for example `--_fb-accent: var(--fb-accent, var(--fb-panel-accent, #4f46e5))`. A host override still wins, and a panel without the hook keeps indigo.
  - **Which private tokens follow the panel**, in both themes: `--_fb-accent`, `--_fb-accent-hover`, `--_fb-accent-contrast`, `--_fb-link` and `--_fb-ring`.
    - Light mode declares them under `.fi .fb-scope`.
    - Dark mode declares them under `.fi.dark .fb-scope[data-fb-theme="class"]` and `.fi .fb-scope[data-fb-theme="dark"]`, so they out-rank the generic dark blocks. These selectors are added to both copies of the dark rules, keeping the dark-parity test green.
  - The bridge tokens are internal, not public API. The token-contract test lists them as a separate, allowed set.
  - This replaces the UI styling spec's fixed-indigo ruling. The package still reads no Filament variables directly.
- **Dark mode**: unchanged (`ui.theme = class` follows Filament's `.dark`).

### 4.7 Standalone pages (apps without Filament)

- Both pages get a thin header: the app name (`config('app.name')`) and a "Back to the app" link to `url('/')`, with content up to 1200 px wide, and the same components as the panel.
- **Theme**: with `ui.theme = class` the standalone pages are **light**, because there is no host `.dark` class to follow. `system` follows the OS; `light` and `dark` force a theme. `Ui::colorScheme()` returns `light` for `class`, and the stylesheet's standalone rules change to match. This reverses the earlier ruling that standalone pages follow the OS, which produced the always-dark page. Set `FEEDBACK_BOARD_THEME=system` to get the old behaviour.
- The standalone reports page opens the new-report form from its header button (slide-over); it is no longer inline.

## 5. Data and backend

### 5.1 Comment attachments

- The schema already links attachments to comments (`feedback_attachments.feedback_comment_id`).
- `FeedbackItemModal::reply()`, `FeedbackDetail::reply()` and `FeedbackDetail::internalNote()` take the component's pending `files`.
- **Validation:**
  - `body` is `nullable|string|required_without:files`;
  - `files` is `array|max:{attachments.max_files}`;
  - `files.*` uses the image subset of `AttachmentStorage::allowedRules()`: the configured mimes ∩ `jpg,jpeg,png,gif,webp`. The composers' inputs use `accept="image/*"`.
- **Storing and failures:**
  - After `ReplyToFeedback::handle()` returns the comment, each file is stored with `AttachmentStorage::store($file, $item, $comment)`, after the comment's transaction (the same pattern as report creation: disk writes cannot roll back).
  - If storing fails, the comment is kept, the exception is reported, and the composer shows "Some images could not be saved."
- **Image-only replies:**
  - The body is stored as `''` (the column is `NOT NULL`).
  - Outbound channels handle an empty body: the reply e-mail uses a translated line ("A reply with images was added"); the GitHub driver skips the auto-comment for an empty body (GitHub rejects empty comments with 422); the webhook payload carries `body: ''` (documented).
- New config key `attachments.max_files` (default `10`), used by the report form and both composers.
- When the host's `allowed_mimes` contains no image types, the image subset is empty: the composers hide the attach button and ignore pastes.
- **Deleting a comment** removes its attachments, so a deleted internal note never leaves its images behind as report-level attachments (`nullOnDelete` would otherwise promote them).
  - `FeedbackComment::deleting` deletes the attachment rows.
  - A `deleted` handler removes their files inside `DB::afterCommit`, so a rolled-back delete keeps both.
  - Database-level cascades (deleting a whole item) fire no model events, so those files stay on disk. That is existing behaviour, and the README documents it.

### 5.2 Serving images inline; the cover

- `AttachmentController::show` keeps its authorization and adds a rule: an attachment of an **internal** comment requires a developer. The leak is latent today (nothing creates comment attachments yet), and this feature would make it real.
- A missing file on disk returns 404 instead of a 500.
- `FeedbackAttachment::isImage()` is true when the stored `mime` is in `image/jpeg, image/png, image/gif, image/webp` **and** the extension of `original_name` matches it.
- Images are served `inline` with:
  - `Content-Type` from the allow-list;
  - `X-Content-Type-Options: nosniff`;
  - `Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox`;
  - `Cache-Control: private, max-age=3600`.
- Everything else, SVG included, stays a download.
- Livewire upload previews are shown only for files that pass the same allow-list. Livewire's own preview list includes svg, bmp and media types.
- **Cover**: `FeedbackItem::coverAttachment()` is an eager-loadable `hasOne()->ofMany(['id' => 'min'], …)`. It takes the report's own attachments (`feedback_comment_id` null) whose mime is in the allow-list. If `isImage()` then rejects the extension, the card shows no cover.

### 5.3 Reporter-visible activity and unread replies

- New migration **`update_feedback_items_add_reporter_columns`**. The name sorts after the `create_*` files, which the package's tests load in filename order. It is registered in `hasMigrations()`, so `feedback-board:install` and `vendor:publish --tag=feedback-board-migrations` publish it. It:
  - adds nullable `reporter_read_at` and `reporter_activity_at` timestamps to `feedback_items`;
  - **backfills** existing rows: `reporter_read_at = now()` (no historical reply becomes unread after the upgrade) and `reporter_activity_at = updated_at`;
  - adds an index on `feedback_comments (feedback_item_id, is_internal, created_at)`.
- **`reporter_activity_at`** is the only time shown to and sorted for the reporter.
  - It is set in `FeedbackItem::creating` (`??= now()`, which also covers factories and host code), on every **non-internal** comment (`ReplyToFeedback`, which also covers ingest and AI comments), and on status changes (`TransitionStatus`).
  - Internal notes, triage, runner, GitHub refs, assignment and priority never touch it. No `$touches` on comments.
  - Reads use `COALESCE(reporter_activity_at, created_at)` for ordering and display, so a NULL never sorts differently on PostgreSQL than on SQLite and MySQL.
- **Unread** means non-internal comments whose `author_role` is **not** `reporter` (developer and AI replies; both already e-mail the reporter), created after `COALESCE(reporter_read_at, feedback_items.created_at)`. Strict `>`, with second precision; the same-second tie is accepted.
- **Marking read** happens in `MyReportsBoard` only when the viewer owns the item. It writes **without touching `updated_at`** (`toBase()->update()`).
- **Queries**:
  - `FeedbackItem::scopeWithUnreadCount()` is one correlated `withCount`, with identifiers wrapped by the query grammar so table prefixes work.
  - The navigation badge is one `whereExists` count over the reporter's items. It returns null when zero. It is wrapped in `rescue(…, report: false)` with a single `Log::warning` per process, so a host that has not run the migration yet can still browse the panel without a log flood.
- **Upgrade**: publishing and running the new migration is a **required** step (CHANGELOG). Before it runs, the panel's other pages work, but the package's writes fail: report submits, replies and Kanban moves all write `reporter_activity_at`. `MyFeedbackPage` also returns a 500. The CHANGELOG says exactly this.

### 5.4 Activity feed

- **`SetPriority` action** (new), used by `FeedbackDetail::setPriority()` and `ApplyTriageSuggestion`:
  - it writes the priority, accepting null for "No priority";
  - it records `ActivityAction::PriorityChanged` (`priority_changed`) with `properties: {from, to}`;
  - it does nothing when the value is unchanged.
- **Type changes**: `ApplyTriageSuggestion` records `ActivityAction::TypeChanged` (`type_changed`) the same way.
- **`AssignFeedback`** now also stores `assignee_type`.
- **`FeedbackActivity::sentence(?string $actor, ?string $assignee = null)`** returns a full line: created, moved from A to B, assigned to X, unassigned, priority changed to P (or cleared), type changed to T.
  - Translations use `:actor`, `:from`, `:to` and `:assignee`.
  - A missing actor name falls back to "Someone".
  - For old assignment rows, which have no `assignee_type`, the assignee name is resolved only when `assignee_id` equals the actor (assign-to-me was the only UI path); otherwise it shows `#id`.
  - `summary()` stays for existing callers.
- **Merged feed** (developer view): comments and activities by time, newest first, **omitting `Replied` activities** (the comment itself is the record). Actors and assignees are eager-loaded; names come from `name`, then `email`, then `#id`.

### 5.5 Uploads (form and composers)

- **One path for every source.** The picker, drop and paste all call `$wire.upload('upload', file)` **once per file**, into a single non-array staging property. The same JS path runs on Livewire 3 and 4. This avoids `uploadMultiple`'s S3 restriction and its single large POST, and it does not depend on the two majors' different `wire:model` append semantics. (`$wire.upload` is a proxy alias checked before properties, so a property named `upload` does not shadow it.)
- **The upload queue** is an Alpine object shared by the three sources:
  - Each upload awaits a Promise that its finish, error and cancel callbacks resolve. A failed or cancelled file resolves too, so it never stalls the queue.
  - The next upload starts from the Promise continuation, **never inside a callback**. Livewire's callback runs before its own queue check, so starting from inside it posts the same file twice.
  - The queue keeps `uploading` (files left) and `progress` (from the progress callback). They drive the submit button's `disabled` state and a progress line, because `wire:loading`/`wire:target` cannot see `$wire.upload()` calls.
- **`updatedUpload()`** runs once per finished upload:
  - it accepts only a `TemporaryUploadedFile`;
  - it validates the file with `Validator::make()` against the component's rules (all allowed types in the form, images only in the composers), and reports failures with `addError('upload', …)` rather than by throwing;
  - when the file passes and fewer than `max_files` are pending, it appends it to `files`, which is `#[Locked]` so the client cannot set it;
  - otherwise it deletes the temporary file and adds an error;
  - it clears the stage in a `finally` block, so a failure never leaves it set.
- `removeFile(int $index)` drops one pending file and deletes its temporary file.
- **Picker**: an Alpine `change` handler (not `wire:model`) feeds the queue, then resets `input.value` so that picking the same file again still fires.
- **Paste**:
  - the handler reads `clipboardData.files`, falls back to `clipboardData.items[].getAsFile()`, and calls `preventDefault()` only when it found files;
  - a pasted image whose name is empty, or the browsers' generic `image.png`/`image.jpg`/`image.jpeg`/`image.gif`/`image.webp`, is renamed client-side to `screenshot-YYYYMMDD-HHMMSS.<ext>`, with the extension taken from `file.type`: `image/jpeg` → `jpg`, `image/png` → `png`, `image/gif` → `gif`, `image/webp` → `webp`. This keeps the stored `original_name` meaningful and consistent with `isImage()`, where `jpg` and `jpeg` both map to `image/jpeg`.
- **Drop**: the zone handles `dragover.prevent`, `dragleave` and `drop.prevent`; only `dataTransfer.files` are used.
- **Errors**: errors for `upload`, `files` and `files.*` show under the zone.

## 6. Styling

### 6.1 Architecture (unchanged)

One self-contained stylesheet; `.fb-scope` roots; `--fb-*` public tokens only read; private `--_fb-*` aliases; logical properties; no `!important`, `@layer`, `url(`, `:has`, nesting or `color-mix`; the reduced-motion rule last; forced-theme backgrounds; the coverage test (every class used in a view is styled). New views stay one directory level deep, which is all the coverage test scans. New `fb-`-prefixed ids and event names (`fb-open-report-form`, the slide-over and dialog title ids) are added to the coverage test's `$notClasses`.

### 6.2 Accent tints without `color-mix`

The selected type card, the support-side chat bubble, the active filter and the unread badge need a tint of the accent, and the accent can be any host or panel colour. No new public token; it works for any accent in both themes.

- **How it is painted**: the tinted element (`.fb-tint`) has `position: relative; isolation: isolate`. Its `::before` has `content: ""; position: absolute; inset: 0; border-radius: inherit; pointer-events: none; z-index: -1`, filled with `var(--_fb-accent)` at a fixed opacity (light `.10`, dark `.16`).
- **`::before` is reserved for tints**; other decorations use `::after`. The existing activity dot is moved to `::after`.
- `isolation` is never put on an ancestor of an overlay (slide-over, dialog, lightbox), so their z-index is never trapped.
- Text on tints uses the normal foreground colour, never the accent.
- In forced-colors mode the tint disappears, so every tinted selected state also has a non-colour cue: a 2 px ring or border.

### 6.3 New and removed blocks

- **New**: slide-over, dialog, lightbox, radio cards, dropzone, file list and pending thumbnails, filter buttons, report rows and header row, steps and terminal banner, chat bubbles, Kanban cover/chips/footer/avatars, filter chips, gallery, feed entries, internal tint, standalone header.
- **Removed**: `fb-modal*`, the drawer around the Kanban detail, the inline-form layout of the standalone reports page.
- **Icons**: inline SVG (Heroicons outline paths), `aria-hidden`.
- **Avatars**: one grapheme (`mb_substr($name, 0, 1)`), never two joined Arabic letters, in a circle tinted from a fixed four-hue palette by a stable hash of the name.

## 7. Translations

All new strings exist in `en` and `ar` (the parity test stays):

- navigation: `nav.group`, `nav.my_reports`, `nav.all_reports`;
- the report form: type hints, dropzone and paste hints, the file-count limit, the context notice, the sent state;
- My reports: filters, steps and terminal banners, conversation labels ("You") and empty states;
- the developer dialog: composer modes and hints, feed sentences, gallery and lightbox labels, the image-save failure;
- the image-only reply line in the e-mail;
- the Filament resource: labels and column headers;
- the standalone header.

## 8. Security

- **User-controlled strings** (attachment names, URLs, titles, bodies) are never interpolated into `x-*`, `wire:*` or `@…` expressions. HTML entity decoding happens before Alpine evaluates an attribute, so escaping does not protect them there. They go into `data-*` attributes read through `$el.dataset`, or through `@js()`. Expressions contain only integers, enum values and package-defined strings. A test uploads a file named `x');alert(1);('"</script>.png` and asserts it appears only escaped and only in text and `data-*` positions.
- Authorization is unchanged except:
  - the internal-attachment rule (§5.2);
  - `sendToGithub()` gaining `guard()`;
  - the reporter conversation filtering internal notes unconditionally.
- Uploads are validated on staging (§5.5) and again on the final store. SVG is never served inline.
- Other user text is plain escaped Blade output, with `dir="auto"`.
- The colour hook prints only allow-listed values (§4.6).

## 9. Performance

- **Kanban**: one query for the items, with `withCount` (comments, attachments, internal notes) and the eager-loaded cover and assignee; no per-card queries. Covers are full images, lazy-loaded (the trade-off of having no thumbnails).
- **Navigation badges** run on every panel page: one indexed query each (§5.3). `canAccess()` runs first, so the board badge never runs for non-developers.

## 10. Compatibility and upgrade

- Laravel 12/13, Livewire 3.8.3+/4.3.4+, Filament 4.0+/5: all lanes keep passing. Behaviours that differ between majors are covered by tests on both: URL-property assignment, upload append, colour helpers.
- **CHANGELOG** additions:
  - **Required:** publish and run the new migration.
  - **Markup and class changes:** the modal became a slide-over and the drawer a dialog. Re-publish any views published earlier.
  - **My reports in the panel:** "My reports" now appears for every panel user (`->myReports(false)` to disable).
  - **Panel colour:** the accent follows the panel's primary colour.
  - **Standalone theme:** standalone pages are light with `class` (`FEEDBACK_BOARD_THEME=system` restores the old behaviour).
  - **Image-only replies:** they send an e-mail line, skip the GitHub comment, and carry an empty webhook body.
- **README and config**: the styling section (the standalone theme, the manual `--fb-accent: var(--primary-600)` tip, which is now automatic), the `ui.theme` config comment, a Filament section for the new page and `myReports()`, and the multi-guard limitation.

## 11. Testing

TDD per task. When done, coverage must include:

- **Form**: radio-card type binding; appending uploads one by one; an invalid staged file (rejected, temp deleted, stage cleared); `max_files` (rejected file deleted); removing a pending file; preview only for allow-listed images; the sent state with `#ID` and the follow link; reset on reopen; the focus contract; unique ids with two forms on a page.
- **Upload queue** (browser pass): three pasted images upload exactly once each, in order; submit stays disabled until the last one finishes; a failed file does not stall the rest; a pasted JPEG keeps a `.jpg` name.
- **Comment attachments**: reporter and developer replies with images, with images only, and with a non-image (rejected); internal-note images hidden from the reporter in the views **and** forbidden by the attachment route; the composer's submit expression for both modes; deleting a comment deletes its images.
- **Inline serving**: allow-listed images served inline with the security headers; SVG, PDF and a mime/extension mismatch served as downloads; a missing file returns 404.
- **Outbound**: an image-only reply produces the e-mail fallback line, no GitHub comment, and an empty webhook body.
- **Reporter activity and unread**:
  - `reporter_activity_at` moves on public comments and status changes, and stays put on internal notes, triage, runner, assignment and priority;
  - counts after developer and AI replies, not after internal notes or the reporter's own replies;
  - reading by opening, by deep link and by replying; only the owner's read counts; marking read leaves `updated_at` alone;
  - the opened row loses its dot and its new replies are tagged;
  - the badge query; the migration's backfill.
- **Deep link and filters**: `?report=` with a small own id (`5`), someone else's id, a missing id, `abc` and an array; `?type=` with `5`, `true`, an array and junk. Both Livewire majors.
- **Lightbox** (browser pass): the download link triggers a download event.
- **Activity**: `SetPriority` (set, clear, unchanged = no row) from the detail and from triage; `TypeChanged`; `assignee_type` stored; sentences with and without names and for old assignment rows; `Replied` omitted from the merged feed; ordering.
- **Kanban**: cover choice (comment images and non-allow-listed files excluded), counts, avatars, filters, and the drag-and-drop contract strings.
- **Filament** (a real panel, both majors): the group, icons and labels translated per request locale; badges; `MyFeedbackPage` for a non-developer; board and resource still developer-only; `myReports(false)`; `Ui::reportsUrl()` inside and outside a panel; the colour hook with and without a CSP nonce, with a light primary (amber), and with a missing shade.
- **XSS**: the hostile-filename test (§8).
- **Standalone**: the header; light by default with `class`; the `system` media query.
- **Stylesheet**: coverage, scoping, token contract (with the bridge set) and dark parity extended to the new classes; the tint technique uses no banned constructs.
- **Browser pass** (Playwright, as in the UI styling cycle): en/ar, light/dark, 1280/390, a Filament panel with a pink and an amber primary, and a plain host; screenshots compared against the mockup.

**Existing assertions this redesign replaces on purpose.** Each is rewritten to the new contract in the task that changes it:

| Test | Today | Becomes |
|---|---|---|
| `MyReportsTest` 45–48, 60–63 | `fb-drawer`, `fb-list__item` | slide-over and row markup |
| `KanbanTest` 38, 41, 52–53 | drawer, priority badge, column dot | dialog, priority chip; the column dot stays |
| `FeedbackDetailTest` 16–17, 97 | separate `body` / `note` | shared `body`, mode-based submit |
| `FeedbackDetailTest` 57, 60 | `<option value="" disabled selected>` | selectable "No priority" |
| `FeedbackDetailTest` 104, 118 | `summary()` text in the timeline | `sentence()` text in the feed |
| `FeedbackDetailTest` 107 | `tab === 'note'` | `mode === 'internal'` |
| `FeedbackFormTest` 18, 47, 87–89 | `set('files')`, plain reports link | `upload` staging, `?report=` link |
| `LivewireRegistrationTest` 55–56, 62 | `fb-launcher-title`, `placeholder=" "` | the id stays; the example placeholders keep the RTL placeholder rule |
| `StandalonePagesTest` 23, 26, 38, 52–65 | standalone markup, unique ids | new header; ids stay unique |
| `UiTest` 62 | `class` → `light dark` | `light` |
| `StylesheetTest` 126, 146–147 | public token list, standalone dark selectors | the bridge set; standalone light |
| `InstallCommandTest` 18, 35, 54 | glob `*_create_feedback_*`, count 4 | includes the update migration |

Kept verbatim: the drag-and-drop strings, `wire:poll.5s` (triage and runner), `h2#fb-drawer-title`, `role="status" tabindex="-1"` and its focus, the `fb-launcher-opened` reset, `data-fb-theme`, `dir`, and the `fb-scope` roots.

## 12. Review focus

The inputs most likely to break for a real user that no single test names:

1. A pasted screenshot (an unnamed clipboard blob) on Livewire 3 and 4, in Chrome and Firefox.
2. A report whose only attachment is a large phone photo (8–10 MB) on a board with many cards.
3. An RTL panel with a long English title and an English URL in the same card and dialog.
4. A panel whose primary colour is light (amber or yellow): button text must stay readable.
5. A first panel visit after `composer update` but before `migrate`.
