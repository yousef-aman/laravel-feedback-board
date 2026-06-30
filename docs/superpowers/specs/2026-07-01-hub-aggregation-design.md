# Multi-Project Hub Aggregation — Design Spec

> Phase 2-D. A **read-only** hub that aggregates feedback items from many spoke
> apps into one dashboard. Spokes deliver via the existing `WebhookDriver`; the
> hub upserts an item snapshot and renders it on a dedicated Filament page. The
> hub never writes back, never mirrors comment threads, never runs an agent.
>
> Hardened against the 2026-07-01 adversarial spec review (8 distinct confirmed
> defects). Review-driven changes are marked **[R]** at the relevant point.

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
- `hub.enabled` config flag, plus `ingest.actions_enabled` **[R]** (gate for the
  existing item-level signed write actions, so a *pure* read-only hub exposes no
  native-item write path).
- A `hubSync()` branch on the existing `ingest` endpoint (value-gated **[R]**).
- `HubSync::upsert()` — a bounded, whitelist snapshot upsert, idempotent under a
  concurrent first-sight insert race **[R]**.
- A dedicated read-only Filament `FeedbackHubPage` (gated by `hub.enabled` +
  developer-only), with project/status filters and per-project counts.
- **[R]** Unconditional `whereNull('source_id')` scoping of the **existing**
  native developer surfaces (`FeedbackItemResource`, `FeedbackKanban`) so
  mirrored rows never leak into the native board, and `whereNull('source_id')`
  scoping of the base + runner ingest write paths so mirrored items are
  read-only to signed item-level actions.
- Spoke/hub setup docs + i18n keys.

**Out of scope (deferred, see §9)**
- Any reverse direction (hub → spoke).
- Comment-thread mirroring.
- Per-spoke secret registry.
- A Livewire (non-Filament) hub view.
- Historical backfill of items created before the spoke was wired up.
- Advanced search / export.

## 2. Data Model & Identity

A new migration **`update_feedback_items_add_source_id`** adds:

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
  row **via the upsert path** (see §5 for the separate signed-action write path).
- **Two spokes with the same `item.id`** but different `project` produce two
  distinct rows (key differs on `project`). This is why each spoke MUST set a
  **unique `project`** that also differs from the hub's own native project
  (§3, §5).
- `source_id` is **server-authored** — it is set only by `HubSync` from a signed
  payload. It is **NOT** added to the model's `$fillable` (same posture as
  `ai_state` / `external_refs`). Client-side item creation cannot set it.
  **[R] CRITICAL:** because `source_id` is not fillable, it MUST be assigned by
  **explicit property assignment** (`$row->source_id = $sourceId;`), never
  through `firstOrNew(['source_id' => …])`'s mass-assignment array — `fill()`
  silently drops non-fillable keys, which would persist every mirrored row with
  `source_id = NULL` and defeat dedup/identity entirely. See §4.3.

`down()` drops the unique index then the column:

```php
$t->dropUnique(['project', 'source_id']);
$t->dropColumn('source_id');
```

**[R] Rollback note:** rolling back merges any mirrored rows into the native
namespace (they lose the `source_id` discriminator and become indistinguishable
from native items on the developer board). Document this in the migration and
the hub guide; a hub should drain/ignore mirrored rows before rollback if that
matters.

**[R] Migration ordering — filename sort, not array order.** Appending the new
migration to `hasMigrations([...])` in `FeedbackBoardServiceProvider` governs
only the **publish** step (Spatie assigns sequential timestamps in array order).
The package's **test suite** loads migrations via
`loadMigrationsFrom(database/migrations)` (see `tests/TestCase.php`), and
Laravel's `Migrator` sorts by **bare filename**. The migration is therefore
named `update_feedback_items_add_source_id` so it sorts **after**
`create_feedback_items_table` (`'u' > 'c'`) — an `add_…`/date-prefixed name
would sort *before* the create and the `ALTER` would hit a non-existent table,
failing the entire suite at boot. A §7 test runs the full ordered migration set
against a fresh database to lock this in.

The new migration is appended to `hasMigrations([...])` after
`create_feedback_items_table`.

## 3. Configuration

```php
'hub' => [
    // Opt-in. With this false (default) the ingest hub branch is never taken
    // and the Filament hub page is hidden + 403 on direct access.
    'enabled' => env('FEEDBACK_BOARD_HUB_ENABLED', false),
],

'ingest' => [
    'enabled'   => env('FEEDBACK_BOARD_INGEST_ENABLED', false),
    'secret'    => env('FEEDBACK_BOARD_INGEST_SECRET'),
    'tolerance' => 300,
    // [R] Gate for the item-level signed WRITE actions (comment / status /
    // external_ref and the runner callbacks). Default true preserves existing
    // behaviour. A *pure* read-only hub sets this false so the only write the
    // ingest endpoint performs is the bounded webhook snapshot upsert.
    'actions_enabled' => env('FEEDBACK_BOARD_INGEST_ACTIONS_ENABLED', true),
],
```

The hub **reuses** the existing `ingest.enabled` / `ingest.secret` /
`ingest.tolerance` and the existing `VerifyIngestSignature` middleware. No new
route, no new secret.

**Becoming a hub** (the aggregating app):
1. `ingest.enabled = true` and set `ingest.secret` (the `ingest` middleware
   returns **404** when ingest is disabled — the hub *is* the ingest endpoint).
2. `hub.enabled = true`.
3. **[R]** For a **pure** read-only hub that does not use the base ingest write
   actions or the runner: `ingest.actions_enabled = false` — closes every
   item-level native-write path, leaving only the bounded snapshot upsert.

**Becoming a spoke** (each contributing app):
1. Add `webhook` to `destinations.enabled`.
2. `destinations.webhook.url` = the hub's `…/feedback-board/ingest` URL. **[R]**
   It MUST point at a *different* app's ingest — never the spoke's own ingest
   (a self-loop would mirror the app's own items back onto itself; §5).
3. `destinations.webhook.secret` = the hub's `ingest.secret` (shared secret —
   read §5 for the trust this implies).
4. Set a **unique** `feedback-board.project` value that also differs from the
   hub's own native `project` (collision/conflation risk otherwise — §5).
5. Keep `destinations.webhook.events` = all three (`feedback.created`,
   `feedback.replied`, `feedback.status_changed`) so the snapshot refreshes on
   every change.

## 4. Inbound Sync

### 4.1 Routing the branch

`IngestController::__invoke` gains a hub branch **before** the existing runner
check and base validator, and an `actions_enabled` gate **[R]**:

```php
// [R] Value allow-list, NOT mere header presence: RunnerDispatcher also sends
// an X-Feedback-Event header (value 'runner.task'), so gating on hasHeader()
// would mis-route. Match the three webhook event values exactly.
$event = (string) $request->header('X-Feedback-Event', '');
if (config('feedback-board.hub.enabled')
    && in_array($event, ['feedback.created', 'feedback.replied', 'feedback.status_changed'], true)) {
    return $this->hubSync($request);
}

// [R] Item-level signed WRITE actions (base + runner) past this point.
abort_unless((bool) config('feedback-board.ingest.actions_enabled', true), 404);

if (in_array($request->input('action'), ['runner_status', 'runner_result'], true)) {
    return $this->runner($request);
}
// … existing base validator + comment/status/external_ref arms (unchanged) …
```

Why this is correct:
- Authentication (HMAC + timestamp tolerance + nonce replay) is already enforced
  by `VerifyIngestSignature` before the controller runs. `hubSync()` adds no new
  unauthenticated path.
- **[R]** The spec's earlier claim that `X-Feedback-Event` is "sent by nothing
  else" was **false**: `RunnerDispatcher` sends `X-Feedback-Event: runner.task`
  (signed with the separate `runner.secret`, posted to `runner.endpoint` — it
  cannot authenticate at the hub, but a hub that is *also* a runner target could
  see the header on a relayed/mis-addressed request). Gating on the **value**
  allow-list makes disjointness structural, not a trust assumption. A runner
  callback (`runner_status`/`runner_result`) — whose body has no `item` key —
  can therefore never be hijacked into `hubSync` and lost as a 422.
- The webhook payload carries no `action` field; base/runner requests carry no
  `feedback.*` `X-Feedback-Event` value. The branches are mutually exclusive.
- When `hub.enabled` is false the hub branch is skipped entirely.

### 4.2 Validation (in `hubSync()`)

Mirror `IngestController::runner()` — validate the nested `item` shape, return a
clean **422** on malformed input, then hand the validated `item` array to
`HubSync::upsert()`:

```php
$data = $request->validate([
    'item'               => ['required', 'array'],
    'item.id'            => ['required', 'integer'],
    'item.project'       => ['nullable', 'string', 'max:255'],
    'item.title'         => ['required', 'string', 'max:255'],
    // [R] byte-bounded to the TEXT column (65,535 BYTES), not Laravel's default
    // character count — an Arabic/CJK description can exceed the column in bytes
    // while passing a char-count max. Custom closure measures strlen() (bytes).
    'item.description'   => ['nullable', 'string', function (string $attr, mixed $v, Closure $fail) {
        if (is_string($v) && strlen($v) > 65535) {
            $fail('The :attribute exceeds the storage limit.');
        }
    }],
    'item.type'          => ['nullable', 'string'],
    'item.status'        => ['nullable', 'string'],
    'item.priority'      => ['nullable', 'string'],
    'item.reporter_name' => ['nullable', 'string', 'max:255'],
    'item.created_at'    => ['nullable', 'string'],
]);

HubSync::upsert($data['item']);

return response()->json(['ok' => true]);
```

- `item.id` is validated as `integer` (the spoke's `$t->id()` is an
  auto-increment bigint emitted as an int by `WebhookPayload`), bounding it
  before the `(string)` cast for `source_id`.
- `item.type` / `item.status` / `item.priority` are validated only as **strings**
  here, **not** with an `in:` rule. Enum coercion happens in `HubSync` via
  `tryFrom` with safe defaults (§4.3) so a newer spoke that introduces a status
  value the hub does not yet know does not hard-fail the delivery. Accepted
  trade-off documented in §5.
- `item.created_at` is validated only as a string here; **strict** format
  parsing happens in `HubSync` (§4.3) — Laravel's `date` rule (and
  `Carbon::parse`) would accept relative strings like `"now"`/`"+10 years"`,
  which must not control a canonical timestamp.
- VARCHAR(255) fields use `max:255` to match their columns; `description` uses
  the byte rule to match `TEXT`.

### 4.3 `HubSync::upsert(array $item): void`

A new final class `src/Hub/Support/HubSync.php`. It performs a **bounded,
explicit-field** snapshot upsert — never `$request->all()`, never the raw model
— and is **idempotent under a concurrent first-sight insert** **[R]**.

```php
public static function upsert(array $item): void
{
    $project  = ($p = trim((string) ($item['project'] ?? ''))) !== '' ? $p : 'default';
    $sourceId = (string) $item['id'];

    try {
        $row    = FeedbackItem::firstOrNew(['project' => $project, 'source_id' => $sourceId]);
        $wasNew = ! $row->exists;

        // [R] CRITICAL: source_id is NOT fillable — assign explicitly, or
        // firstOrNew's fill() drops it and the row persists with source_id NULL.
        $row->source_id = $sourceId;
        $row->project   = $project;

        self::applyFields($row, $item);

        // Preserve the spoke's creation time on first sight only — strict ATOM
        // parse rejects relative strings; on any mismatch fall back to now().
        if ($wasNew && ! empty($item['created_at'])) {
            $parsed = rescue(
                fn () => Carbon::createFromFormat(\DateTimeInterface::ATOM, (string) $item['created_at']),
                null,
                report: false,
            );
            if ($parsed instanceof Carbon) {
                $row->created_at = $parsed; // dirty => Eloquent won't overwrite on insert
            }
        }

        $row->save();
    } catch (UniqueConstraintViolationException) {
        // [R] Lost a first-sight race with a concurrent delivery for the same
        // (project, source_id). The row now exists — re-read and update in place
        // (skip the created_at-on-new branch; the winner already set it).
        $row = FeedbackItem::where('project', $project)->where('source_id', $sourceId)->firstOrFail();
        self::applyFields($row, $item);
        $row->save();
    }
}

/** Bounded, explicit field assignment. Enum columns via tryFrom + safe defaults. */
private static function applyFields(FeedbackItem $row, array $item): void
{
    $row->title         = (string) $item['title'];
    $row->description   = $item['description'] ?? null;
    $row->reporter_name = $item['reporter_name'] ?? null;
    $row->type     = (FeedbackType::tryFrom((string) ($item['type'] ?? '')) ?? FeedbackType::Bug)->value;
    $row->status   = (FeedbackStatus::tryFrom((string) ($item['status'] ?? '')) ?? FeedbackStatus::default())->value;
    $row->priority = FeedbackPriority::tryFrom((string) ($item['priority'] ?? ''))?->value; // null allowed
}
```

**Explicitly NOT copied from the payload** (snapshot, not mirror):
`ai_state`, `external_refs`, `meta`, `assignee_*`, `reporter_email`,
`reporter_id`/`reporter_type`, `resolved_at`, comments, and the `change` block.

**All three event types** run the **same** upsert. `WebhookPayload::build`
carries the full current item snapshot on every event, so one code path refreshes
the snapshot regardless of which event triggered it; the per-event `change` block
is ignored.

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
  is not in `$fillable` and is set by explicit property assignment (§4.3); enum
  columns coerced via `tryFrom` so no out-of-set or `ValueError` value reaches a
  column.
- **PII minimization:** only `reporter_name` is copied. No `reporter_email`, no
  reporter morph ids, no assignee.
- **Native/mirrored isolation — two layers [R]:**
  1. *Upsert path:* the upsert key always has a non-null `source_id`, so a native
     row (`source_id = NULL`) can never be matched or clobbered (NULL-distinct).
  2. *Read surfaces:* the **existing** native developer surfaces queried the
     whole table unscoped, so mirrored rows would otherwise appear on the native
     board/Kanban with the full action set (and a Kanban drag would write
     `status`, which the next spoke event then silently overwrites — breaking the
     "hub never writes back" invariant). Therefore `FeedbackItemResource` and
     `FeedbackKanban` are scoped to `whereNull('source_id')` **unconditionally**
     (not gated on `hub.enabled`, since disabling the hub must not strand
     previously-ingested mirrored rows on the native board). `MyReportsBoard` is
     already reporter-scoped and naturally excludes mirrored rows (`reporter_id`
     is null) — verified by test, no change.
- **[R] Signed item-level write surface — the shared-secret blast radius
  (expanded, documented):** every spoke now holds the hub's `ingest.secret`
  (§3). The ingest endpoint also serves the base actions (`comment` / `status`
  / `external_ref`) and the runner callbacks, and these resolve their target by
  `findOrFail($item_id)` with no project/source scoping. So **any** secret
  holder can drive those write branches against **any** item id — including the
  hub's own **native** items (post AI-authored comments, force status
  transitions, write `external_refs`/`ai_state`). Before Phase 2-D the secret
  lived only in the runner's CI env; the hub expands the holder set to every
  spoke. Mitigations applied in v1:
  1. **Mirrored items are read-only to signed actions:** the base and runner
     `findOrFail` are scoped to `whereNull('source_id')`, so a signed action can
     never mutate a *mirrored* snapshot (it would 404). Mirrored data integrity
     is structural.
  2. **`ingest.actions_enabled` gate:** a pure read-only hub sets it false,
     closing **all** item-level native-write paths — the endpoint then performs
     only the bounded snapshot upsert. Default true preserves existing behaviour
     for apps that legitimately use the base/runner write actions.
  3. **Residual (documented, accepted):** when `actions_enabled = true`, a
     secret holder can still write the hub's **native** items. This is accepted
     because all spokes are apps the same developer owns; the **full** fix is the
     per-spoke secret registry, deferred to §9 and explicitly conditioned on this
     blast radius being acceptable.
- **Input bounds:** all string fields length-bounded in validation (§4.2) —
  VARCHAR fields by char count, `description` by **byte** count to match `TEXT`
  — yielding clean 422s rather than DB 500s; `item.id` constrained to integer;
  `item.created_at` strictly ATOM-parsed (relative strings rejected, §4.3).
- **[R] Concurrency:** a concurrent first-sight insert for the same
  `(project, source_id)` (two different events → different signatures → both
  pass replay → both INSERT) is caught (`UniqueConstraintViolationException`) and
  resolved by re-read + update (§4.3), so it yields a consistent row, not a 500.
- **Accepted trade-off — unknown enum → safe default:** an unrecognized
  type/status/priority becomes Bug/New/null rather than rejecting the delivery,
  favouring forward-compatibility over strictness.
- **Accepted limitation — eventual consistency / ordering:** webhook deliveries
  are async and retried; `WebhookPayload` is built when the delivery job runs and
  the hub applies last-writer-wins per `(project, source_id)`. Out-of-order
  delivery can momentarily show a slightly stale snapshot until the next event.
  No sequence/version field in v1 (deferred §9). Acceptable for a read-only
  overview.
- **[R] Self-loop / topology:** a hub MUST NOT point a webhook destination at
  its own ingest URL, and every spoke's `project` MUST differ from the hub's own
  native `project` — otherwise the app's native items are mirrored back as
  foreign rows and double-counted. Documented in §3 and the hub guide (operator
  constraint; no silent drop, so misconfiguration surfaces as visible duplicate
  rows rather than hidden data loss).
- **UI:** the hub page is developer-only (`canAccess` + `mount` guard) and
  read-only — no record actions, no bulk actions, no edit.

## 6. Aggregation UI

A dedicated Filament page `src/Filament/Pages/FeedbackHubPage.php`
(`Filament\Pages\Page`), mirroring the existing `FeedbackBoardPage` registration
and authorization pattern, registered via `FeedbackBoardPlugin::register()` in
its `->pages([...])` array (unconditionally registered; access-gated).

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
- **Table (read-only)** over **mirrored items only** (`whereNotNull('source_id')`).
  Columns: `project` (badge), `title`, `type`, `status` (badge), `priority`,
  `reporter_name`, `created_at`. Built with the same Filament table column API
  the `FeedbackItemResource` already uses. All untrusted values render escaped
  (Filament default).
- **Filters:** `project` (select, distinct values present) and `status`.
- **Per-project counts:** a summary (navigation badge or header summary) of
  mirrored-item counts per project.
- **No** record actions, bulk actions, row links, or edit. Pure read.
- Filament-only for v1; a Livewire hub view is deferred (§9). The page is only
  registered when Filament is installed (same condition as today's
  resource/page).

**[R] Native-surface scoping (the inverse of the hub page).** The existing native
developer surfaces are scoped to `whereNull('source_id')` so the hub page and the
native board stay disjoint and mirrored rows never appear on, or are actionable
from, the native board — **unconditionally**, independent of `hub.enabled`:
- `FeedbackItemResource::getEloquentQuery()` → `parent::getEloquentQuery()->whereNull('source_id')`.
- `FeedbackKanban`: the per-column query (`FeedbackItem::where('status', …)`),
  the `moveCard`/select `findOrFail($itemId)`, and the `find($this->selectedId)`
  lookup are each scoped with `whereNull('source_id')`.
- The base + runner ingest `findOrFail($item_id)` paths are likewise scoped with
  `whereNull('source_id')` (§5) so mirrored items are read-only to signed
  actions.

## 7. Testing

All tests use the package's existing Pest + Orchestra Testbench setup (SQLite).

- **Migration:** running the **full ordered migration set** against a fresh DB
  boots cleanly **[R]** (guards the filename-sort ordering); `source_id` column
  and unique `(project, source_id)` index exist; multiple native rows
  (`source_id = NULL`) coexist; two rows with the same `source_id` under
  **different** `project` coexist; a duplicate `(project, source_id)` violates
  the unique index.
- **`HubSync::upsert`:**
  - creates a new mirrored row and **asserts `source_id === (string) item.id`**
    (non-null) **[R]** — not just a row count;
  - a second event for the same `(project, source_id)` updates in place
    (exactly **one** row) and refreshes `status` **[R]**;
  - `tryFrom` coerces an unknown `type`/`status` to the safe default and an
    unknown/absent `priority` to null;
  - bounded assignment — `ai_state`, `external_refs`, `meta`, `assignee`,
    `reporter_email` in the payload are **ignored** (not persisted);
  - only `reporter_name` is copied (email ignored);
  - **never clobbers a native row** — a native item with the same numeric id but
    `source_id = NULL` is untouched while a mirrored row is created;
  - `created_at` is taken from a valid ATOM payload on create and **preserved**
    on update; a **relative/garbage `created_at` is rejected** and the row falls
    back to `now()` **[R]**;
  - **concurrent first-sight insert** resolves to one row, not a 500 **[R]**
    (simulate by pre-inserting the row, then calling `upsert` and asserting the
    `UniqueConstraintViolationException` path updates in place).
- **`IngestController` branch:**
  - `X-Feedback-Event: feedback.created` + `hub.enabled = true` ⇒ upsert runs;
  - `hub.enabled = false` ⇒ branch skipped, falls through to existing behaviour;
  - **a signed runner callback that *also* carries an `X-Feedback-Event` header
    still routes to the runner branch** (value allow-list, not presence) **[R]**;
  - `ingest.actions_enabled = false` ⇒ a base/runner action returns 404 while the
    hub upsert still works **[R]**;
  - a base/runner action targeting a **mirrored** item returns 404 **[R]**
    (read-only to signed actions);
  - unsigned / bad-signature request ⇒ 403 (middleware, unchanged);
  - **no regression:** existing `comment` / `status` / `external_ref` and
    `runner_status` / `runner_result` actions still work (with
    `actions_enabled = true`) against native items.
- **Native-surface scoping [R]:** a mirrored row is **excluded** from the
  `FeedbackItemResource` list and from every `FeedbackKanban` column, while a
  same-status native row is **included**; `MyReportsBoard` excludes mirrored
  rows (reporter-scoped).
- **Filament hub page:** visible only when `hub.enabled` + developer; hidden /
  403 otherwise; read-only (no actions); shows mirrored rows, excludes native.
- **i18n parity:** the page's `en` and `ar` keys match (same key set).

## 8. Files

| File | Responsibility |
|------|----------------|
| `database/migrations/update_feedback_items_add_source_id.php` | `source_id` column + unique `(project, source_id)`; **[R]** name sorts after the create migration |
| `src/FeedbackBoardServiceProvider.php` | append migration to `hasMigrations([...])` |
| `config/feedback-board.php` | add `hub.enabled`; **[R]** add `ingest.actions_enabled` |
| `src/Hub/Support/HubSync.php` | bounded snapshot upsert (explicit `source_id`, strict `created_at`, race-idempotent) |
| `src/Http/Controllers/IngestController.php` | `hubSync()` branch (value allow-list) + `actions_enabled` gate; **[R]** scope base/runner `findOrFail` to `whereNull('source_id')` |
| `src/Filament/Resources/FeedbackItemResource.php` | **[R]** `getEloquentQuery()` → `whereNull('source_id')` |
| `src/Livewire/FeedbackKanban.php` | **[R]** scope the three `FeedbackItem` lookups to `whereNull('source_id')` |
| `src/Filament/Pages/FeedbackHubPage.php` (+ blade view) | read-only aggregation page |
| `src/Filament/FeedbackBoardPlugin.php` | register `FeedbackHubPage` in `->pages([...])` |
| `resources/lang/{en,ar}/feedback-board.php` | hub page keys |
| `docs/hub-aggregation.md` | spoke/hub setup guide (incl. self-loop/project-uniqueness + rollback notes) |
| `README.md` | brief hub section |
| `tests/…` | as enumerated in §7 |

## 9. Deferred (Later Cycles)

- Reverse direction (hub → spoke actions).
- Comment-thread mirroring.
- **Per-spoke secret registry** — the full fix for the §5 signed-action blast
  radius (replaces the single-shared-secret trust). Until then, a hub that needs
  the base/runner write actions accepts that every spoke can write its native
  items; a pure read-only hub closes this via `ingest.actions_enabled = false`.
- Livewire (non-Filament) hub view.
- Historical backfill of pre-wiring items.
- Sequence/version field for strict ordering across out-of-order deliveries.
- Advanced search / filtering / export on the hub view.
