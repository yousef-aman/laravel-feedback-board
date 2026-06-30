# Multi-Project Hub Aggregation — Design Spec

> Phase 2-D. A **read-only** hub that aggregates feedback items from many spoke
> apps into one dashboard. Spokes deliver via the existing `WebhookDriver`; the
> hub upserts an item snapshot and renders it on a dedicated Filament page. The
> hub never writes back, never mirrors comment threads, never runs an agent.

## 1. Overview & Scope

A developer runs several delivered apps, each with its own feedback board. The
hub is one of those apps configured to **also receive** the others' feedback so
the developer sees everything in one place.

Direction is strictly one-way (spoke → hub). The hub stores a **snapshot** of
each foreign item (title, description, type/status/priority, reporter_name,
timestamps) keyed by `(project, source_id)` and refreshes it on every event the
spoke sends. The developer still acts on each item **in its own spoke app** —
the hub view carries no record actions.

**In scope**
- `source_id` column + unique index `(project, source_id)` (new migration).
- `hub.enabled` config flag.
- A `hubSync()` branch on the existing `ingest` endpoint (header-gated).
- `HubSync::upsert()` — a bounded, whitelist snapshot upsert.
- A dedicated read-only Filament `FeedbackHubPage` (gated by `hub.enabled` +
  developer-only), with project/status filters and per-project counts.
- Spoke/hub setup docs + i18n keys.

**Out of scope (deferred, see §9)**
- Any reverse direction (hub → spoke).
- Comment-thread mirroring.
- Per-spoke secret registry.
- A Livewire (non-Filament) hub view.
- Historical backfill of items created before the spoke was wired up.
- Advanced search / export.

## 2. Data Model & Identity

A new migration `add_source_id_to_feedback_items_table` adds:

```php
Schema::table('feedback_items', function (Blueprint $t) {
    $t->string('source_id')->nullable()->after('project');
    $t->unique(['project', 'source_id']);
});
```

- **`source_id`** holds the spoke's `item.id` (as a string). It is `null` for
  **native** items (feedback created in this app directly) and non-null for
  **mirrored** items (received from a spoke).
- **Unique index `(project, source_id)`** is the dedup guarantee. The upsert key
  is `(project, source_id)` with a **non-null** `source_id`. In SQL (SQLite,
  MySQL, PostgreSQL) `NULL` values are **distinct** in a unique index, so an
  unbounded number of native rows (`source_id = NULL`) coexist, and the upsert
  — whose key is always non-null — can **never** match or overwrite a native
  row. Native/mirrored isolation is therefore structural, not enforced in code.
- **Two spokes with the same `item.id`** but different `project` produce two
  distinct rows (key differs on `project`). This is why each spoke MUST set a
  **unique `project`** (see §3, §5).
- `source_id` is **server-authored** — it is set only by `HubSync` from a signed
  payload. It is **NOT** added to the model's `$fillable` (same posture as
  `ai_state` / `external_refs`). Client-side item creation cannot set it.

`down()` drops the unique index then the column:

```php
$t->dropUnique(['project', 'source_id']);
$t->dropColumn('source_id');
```

The new migration is appended to the package's `hasMigrations([...])` list in
`FeedbackBoardServiceProvider` **after** `create_feedback_items_table` so it runs
in order.

## 3. Configuration

```php
'hub' => [
    // Opt-in. With this false (default) the ingest hub branch is never taken
    // and the Filament hub page is hidden + 403 on direct access.
    'enabled' => env('FEEDBACK_BOARD_HUB_ENABLED', false),
],
```

The hub **reuses** the existing `ingest` block — `ingest.enabled`,
`ingest.secret`, `ingest.tolerance` — and the existing `VerifyIngestSignature`
middleware. There is no new route and no new secret.

**Becoming a hub** (the aggregating app):
1. `ingest.enabled = true` and set `ingest.secret` (the `ingest` middleware
   returns **404** when ingest is disabled, so this is required — the hub *is*
   the ingest endpoint).
2. `hub.enabled = true`.

**Becoming a spoke** (each contributing app):
1. Add `webhook` to `destinations.enabled`.
2. `destinations.webhook.url` = the hub's `…/feedback-board/ingest` URL.
3. `destinations.webhook.secret` = the hub's `ingest.secret` (shared secret).
4. Set a **unique** `feedback-board.project` value (collision risk otherwise —
   §5).
5. Keep `destinations.webhook.events` = all three (`feedback.created`,
   `feedback.replied`, `feedback.status_changed`) so the snapshot refreshes on
   every change.

## 4. Inbound Sync

### 4.1 Routing the branch

At the very top of `IngestController::__invoke`, **before** the existing runner
check and the base validator:

```php
if (config('feedback-board.hub.enabled') && $request->hasHeader('X-Feedback-Event')) {
    return $this->hubSync($request);
}
```

Why this is unambiguous:
- Authentication (HMAC + timestamp tolerance + nonce replay) is already enforced
  by `VerifyIngestSignature` before the controller runs. `hubSync()` adds no new
  unauthenticated path.
- `X-Feedback-Event` is sent by `WebhookDriver` on **every** delivery and by
  nothing else. Item-level ingest actions (`comment` / `status` /
  `external_ref`) and runner callbacks (`runner_status` / `runner_result`) are
  identified by an `action` body field and carry **no** `X-Feedback-Event`
  header. The webhook payload carries **no** `action` field. The three branches
  are mutually exclusive on disjoint signals; hub-first ordering is defensive,
  not load-bearing.
- When `hub.enabled` is false the branch is skipped entirely and behaviour is
  byte-for-byte the existing controller.

### 4.2 Validation (in `hubSync()`)

Mirror `IngestController::runner()` — validate the nested `item` shape, return a
clean **422** on malformed input, then hand the validated `item` array to
`HubSync::upsert()`:

```php
$data = $request->validate([
    'item'              => ['required', 'array'],
    'item.id'           => ['required', 'integer'],
    'item.project'      => ['nullable', 'string', 'max:255'],
    'item.title'        => ['required', 'string', 'max:255'],
    'item.description'  => ['nullable', 'string', 'max:65535'],
    'item.type'         => ['nullable', 'string'],
    'item.status'       => ['nullable', 'string'],
    'item.priority'     => ['nullable', 'string'],
    'item.reporter_name'=> ['nullable', 'string', 'max:255'],
    'item.created_at'   => ['nullable', 'string'],
]);

HubSync::upsert($data['item']);

return response()->json(['ok' => true]);
```

- `item.id` is validated as `integer` (the spoke's `$t->id()` is always an
  auto-increment bigint, and `WebhookPayload` emits it as an int), which bounds
  it to a sane length before the `(string)` cast for `source_id`.
- `item.type` / `item.status` / `item.priority` are validated only as **strings**
  here — **not** with an `in:` rule. Enum coercion happens in `HubSync` via
  `tryFrom` with safe defaults (§4.3). This is deliberate: a newer spoke that
  introduces a status value the hub does not yet know must not hard-fail the
  whole delivery (forward compatibility). The trade-off (unknown enum → safe
  default rather than reject) is accepted and documented in §5.
- Lengths are bounded to the destination columns (`title`, `reporter_name`,
  `project` are `string(255)`; `description` is `text`) so a malformed/oversized
  payload yields a clean 422 instead of a DB-level 500.

### 4.3 `HubSync::upsert(array $item): void`

A new final class `src/Hub/Support/HubSync.php`. It performs a **bounded,
explicit-field** snapshot upsert — never `$request->all()`, never the raw model.

```php
$project  = ($p = trim((string) ($item['project'] ?? ''))) !== '' ? $p : 'default';
$sourceId = (string) $item['id'];

$row = FeedbackItem::firstOrNew([
    'project'   => $project,
    'source_id' => $sourceId,
]);

$wasNew = ! $row->exists;

// Bounded, explicit assignment. Enum columns via tryFrom with safe defaults.
$row->title         = (string) $item['title'];
$row->description   = $item['description'] ?? null;
$row->reporter_name = $item['reporter_name'] ?? null;
$row->type     = (FeedbackType::tryFrom((string) ($item['type'] ?? '')) ?? FeedbackType::Bug)->value;
$row->status   = (FeedbackStatus::tryFrom((string) ($item['status'] ?? '')) ?? FeedbackStatus::default())->value;
$row->priority = FeedbackPriority::tryFrom((string) ($item['priority'] ?? ''))?->value; // null allowed

// Preserve the spoke's creation time on first sight only.
if ($wasNew && ! empty($item['created_at'])) {
    $parsed = rescue(fn () => Carbon::parse($item['created_at']), null, report: false);
    if ($parsed !== null) {
        $row->created_at = $parsed; // dirty => Eloquent will not overwrite on insert
    }
}

$row->save();
```

**Explicitly NOT copied from the payload** (snapshot, not mirror):
`ai_state`, `external_refs`, `meta`, `assignee_*`, `reporter_email`,
`reporter_id`/`reporter_type`, `resolved_at`, comments, and the `change` block.
The hub stores the developer-visible snapshot only.

**All three event types** (`feedback.created`, `feedback.replied`,
`feedback.status_changed`) run the **same** upsert. `WebhookPayload::build`
carries the full current item snapshot on every event, so one code path
refreshes the snapshot regardless of which event triggered it; the per-event
`change` block is ignored.

**`created_at` preservation mechanics:** setting `created_at` on a new model
marks it dirty; Eloquent's `updateTimestamps()` only sets `created_at` on insert
when the column is **not** dirty, so the spoke's time is kept. On updates we do
not touch `created_at` (preserved); `updated_at` is bumped to reflect the last
sync.

## 5. Security

- **Authentication:** unchanged. HMAC signature + timestamp tolerance + nonce
  replay via the existing `VerifyIngestSignature`, using the shared
  `ingest.secret`. No new route, no unsigned path.
- **Gate:** `hub.enabled = false` (default) ⇒ the branch is never entered and
  the page is inaccessible. Disabled is the safe default.
- **Mass-assignment bounded:** explicit per-field assignment only; `source_id`
  is not in `$fillable`; enum columns coerced via `tryFrom` so no out-of-set or
  `ValueError`-inducing value can reach the column.
- **PII minimization:** only `reporter_name` is copied. No `reporter_email`, no
  reporter morph ids, no assignee. (The outbound `WebhookPayload` already omits
  these, and `HubSync` copies none of them even if present.)
- **Native-item isolation:** the upsert key always has a non-null `source_id`,
  so a native row (`source_id = NULL`) can never be matched or clobbered — a
  structural guarantee from the unique index's NULL-distinct semantics.
- **Input bounds:** all string fields length-bounded in validation (§4.2),
  yielding clean 422s rather than DB 500s; `item.id` constrained to integer.
- **Accepted trade-off — single shared secret:** every spoke holds the same
  `ingest.secret`, so any spoke (or anyone holding the secret) can assert an
  arbitrary `project` and forge/overwrite another project's mirrored items.
  This is acceptable because **all spokes are apps the same developer owns**;
  a per-spoke secret registry is deferred (§9). Documented, not silent.
- **Accepted trade-off — unknown enum → safe default:** an unrecognized
  type/status/priority becomes Bug/New/null rather than rejecting the delivery,
  favouring forward-compatibility and delivery durability over strictness.
- **Accepted limitation — eventual consistency / ordering:** webhook deliveries
  are async and retried; `WebhookPayload` is built when the delivery job runs
  (reading the item's then-current state), and the hub applies last-writer-wins
  per `(project, source_id)`. Out-of-order delivery can momentarily show a
  slightly stale snapshot until the next event arrives. No sequence/version
  field in v1 (deferred). The hub is a read-only overview, so this is
  acceptable.
- **UI:** the hub page is developer-only (`canAccess` + `mount` guard) and
  read-only — no record actions, no bulk actions, no edit. The developer acts in
  each spoke app.

## 6. Aggregation UI

A dedicated Filament page `src/Filament/Pages/FeedbackHubPage.php`
(`Filament\Pages\Page`), mirroring the existing `FeedbackBoardPage` registration
and authorization pattern, registered via `FeedbackBoardPlugin::register()`
in its `->pages([...])` array (unconditionally registered; access-gated).

- **Authorization / gating:**
  ```php
  public static function canAccess(): bool
  {
      return config('feedback-board.hub.enabled')
          && FeedbackItemPolicy::isDeveloper(auth()->user());
  }
  public function mount(): void
  {
      abort_unless(
          config('feedback-board.hub.enabled') && FeedbackItemPolicy::isDeveloper(auth()->user()),
          403,
      );
  }
  ```
  With `hub.enabled = false` the page is hidden from navigation and 403s on
  direct access.
- **Table (read-only)** over **mirrored items only** (`whereNotNull('source_id')`
  — native items belong to the regular board, keeping the two views cleanly
  separated). Columns: `project` (badge), `title`, `type`, `status` (badge),
  `priority`, `reporter_name`, `created_at`. Built with the same Filament table
  column API the `FeedbackItemResource` already uses.
- **Filters:** `project` (select, distinct values present) and `status`.
- **Per-project counts:** a summary (navigation badge or header summary) of item
  counts per project.
- **No** record actions, bulk actions, row links, or edit. Pure read.
- Filament-only for v1; a Livewire hub view is deferred (§9). The page is only
  registered when Filament is installed (the plugin is only registered by apps
  that use Filament — same condition as today's resource/page).

## 7. Testing

All tests use the package's existing Pest + Orchestra Testbench setup (SQLite).

- **Migration:** `source_id` column exists; unique index `(project, source_id)`
  exists; multiple native rows (`source_id = NULL`) coexist (NULL-distinct);
  two rows with the same `source_id` under **different** `project` coexist;
  inserting a duplicate `(project, source_id)` violates the unique index.
- **`HubSync::upsert`:**
  - creates a new mirrored row from a payload;
  - a second event for the same `(project, source_id)` updates in place (one row,
    dedup) and refreshes `status`;
  - `tryFrom` coerces an unknown `type`/`status` to the safe default and an
    unknown/absent `priority` to null;
  - bounded assignment — `ai_state`, `external_refs`, `meta`, `assignee`,
    `reporter_email` present in the payload are **ignored** (not persisted);
  - only `reporter_name` is copied (email ignored);
  - **never clobbers a native row** — a native item with the same numeric id but
    `source_id = NULL` is untouched while a mirrored row is created;
  - `created_at` is taken from the payload on create and **preserved** on update.
- **`IngestController` branch:**
  - `X-Feedback-Event` header + `hub.enabled = true` ⇒ upsert runs;
  - `hub.enabled = false` ⇒ branch skipped, falls through to existing behaviour;
  - unsigned / bad-signature request ⇒ 403 (middleware, unchanged);
  - **no regression:** existing `comment` / `status` / `external_ref` and
    `runner_status` / `runner_result` actions still work with the hub branch in
    place.
- **Filament page:** visible only when `hub.enabled` + developer; hidden / 403
  otherwise; read-only (no actions); shows mirrored rows, excludes native rows.
- **i18n parity:** the page's `en` and `ar` keys match (same key set).

## 8. Files

| File | Responsibility |
|------|----------------|
| `database/migrations/add_source_id_to_feedback_items_table.php` | `source_id` column + unique `(project, source_id)` |
| `src/FeedbackBoardServiceProvider.php` | append migration to `hasMigrations([...])` |
| `config/feedback-board.php` | add `hub.enabled` block |
| `src/Hub/Support/HubSync.php` | bounded snapshot upsert |
| `src/Http/Controllers/IngestController.php` | `hubSync()` branch + validation, before base validator |
| `src/Filament/Pages/FeedbackHubPage.php` (+ blade view) | read-only aggregation page |
| `src/Filament/FeedbackBoardPlugin.php` | register `FeedbackHubPage` in `->pages([...])` |
| `resources/lang/{en,ar}/feedback-board.php` | hub page keys |
| `docs/hub-aggregation.md` | spoke/hub setup guide |
| `README.md` | brief hub section |
| `tests/…` | as enumerated in §7 |

## 9. Deferred (Later Cycles)

- Reverse direction (hub → spoke actions).
- Comment-thread mirroring.
- Per-spoke secret registry (replaces the single-shared-secret trade-off).
- Livewire (non-Filament) hub view.
- Historical backfill of pre-wiring items.
- Sequence/version field for strict ordering across out-of-order deliveries.
- Advanced search / filtering / export on the hub view.
