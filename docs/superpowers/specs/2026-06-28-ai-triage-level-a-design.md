# AI Triage (Level A, in-app) — Design Spec

**Status:** Approved design, hardened by adversarial review (31 findings triaged)
**Date:** 2026-06-28
**Phase:** 2-B (first AI layer)
**Package:** `yousefaman/feedback-board`

## Goal

Add an in-app AI layer that triages incoming feedback items: it suggests a
`type` and `priority` and produces a concise summary, storing the result as a
**suggestion** the developer reviews and applies. The feature is opt-in
(disabled by default), one-way (AI never mutates canonical columns on its own),
and sends only the item title and description to the model.

## Architecture

A new `Triage` subsystem mirrors the proven `Destinations` pattern. A dedicated
synchronous listener subscribes to `FeedbackItemCreated` and, when AI is
configured, asks a shared `TriageDispatcher` to mark the item `pending` and
enqueue a job **after the DB transaction commits**. The job extracts **only the
title and description** and hands them to a `TriageManager`-resolved driver
(`fake` or `anthropic`). The driver returns a validated `TriageResult` DTO and
guarantees that no transport-layer exception carrying the API key can escape.
The job writes the result into the existing `ai_state` JSON column under a
per-item lock, always reaching a terminal `completed` or `failed` state. A manual
path (a shared `RequestTriage` action wired to Livewire and Filament buttons)
reuses the same dispatcher. Applying a suggestion to the canonical
`type`/`priority` columns is a separate, hard-authorized `ApplyTriageSuggestion`
action.

## Tech Stack

Laravel 11/12, Livewire 3, optional Filament v4, `spatie/laravel-package-tools`,
Pest 3 + Orchestra Testbench 10. The Anthropic driver calls the Messages API via
the Laravel `Http` client (no SDK dependency), mirroring how `Destinations`
drivers use `Http`. No new Composer runtime dependency is added.

## Global Constraints

- `declare(strict_types=1)` on every new `src/` file (enforced by `tests/ArchTest.php`
  over the whole `YousefAman\FeedbackBoard` namespace).
- The feature is **opt-in**: master switch `ai.enabled` defaults to `false`. When
  off — or when the selected driver is unconfigured (e.g. `anthropic` without an
  API key) — the listener does not dispatch and the manual action returns 422.
- **Data minimization is structural:** the `TriageDriver` contract receives
  `(string $title, ?string $description)` only — never a `FeedbackItem`. The model
  never receives reporter email/name/id, assignee, attachments, `meta`,
  `external_refs`, or `ai_state`.
- **Both** the title and the description are length-bounded before they leave the
  app: their combined budget is `ai.max_input_chars` (default 4000).
- **`ai_state` and `external_refs` are server-authored only.** They are removed
  from the model's `$fillable`; create/ingest payloads cannot set them. Only the
  triage job (for `ai_state`) and the destination drivers (for `external_refs`)
  write them, via explicit property assignment.
- **AI never writes canonical columns.** Suggestions live in `ai_state['triage']`
  until a developer explicitly applies them via `ApplyTriageSuggestion`.
- **Untrusted output is rendered escaped only.** The stored `summary`/`model` are
  influenced by reporter-controlled text; both UI layers render them with Blade
  `{{ }}` (never `{!! !!}`) and a Filament `TextEntry`/`TextColumn` that never uses
  `->html()`/`->markdown()`. No triage field is interpolated into HTML attributes
  or JS.
- **Secrets:** the API key is read from env only; it is never logged and never
  written to `ai_state`. The driver catches every transport-layer throwable and
  rethrows a `TriageException` carrying **only** a sanitized code and **no previous
  exception** (so `(string) $exception` cannot walk back to a message/headers
  containing the key). Failures store a sanitized `error_code` only.
- **Transactional dispatch:** the job sets `public bool $afterCommit = true;`
  (version-agnostic across Laravel 10/11/12), so it never runs against an
  uncommitted or rolled-back row, on any queue connection (including `sync`).
- Reuse `Destinations\Support\TransientRetry` (generic). The triage subsystem does
  **not** reuse `RetryAfter`/`release()` — see Section 5 for its throw-based,
  always-terminal retry model.
- Suggested enum values are stored only when they pass `FeedbackType::tryFrom`
  / `FeedbackPriority::tryFrom`; otherwise stored as `null` (no hallucinated values).

---

## Section 1 — Architecture & Components

Data flow:

```
FeedbackItemCreated ──► TriageNewFeedback (listener, synchronous, NOT ShouldQueue)
                              │  (only if TriageGate::configured())
                              ▼
                        TriageDispatcher::dispatch($item)
                              │  writes ai_state.triage.status='pending'
                              │  enqueues TriageFeedbackItem AFTER COMMIT (debounced)
                              ▼
                        TriageFeedbackItem (Job, ShouldQueue, $afterCommit = true)
                              │  passes title + description only ──►
                              ▼
                        TriageManager (singleton) ──► TriageDriver
                              │                         ├─ FakeTriageDriver (default; tests/dev)
                              │                         └─ AnthropicDriver (Http, no SDK)
                              ▼
                        TriageResult (DTO: ?type, ?priority, summary, model)
                              │  terminal write under per-item ai_state lock ──►
                              ▼
                        ai_state['triage'] (status: completed | failed)

Manual path:  button (Livewire/Filament) ──► RequestTriage (action, hard auth + gate) ──► TriageDispatcher
Apply path:   "apply" button ──► ApplyTriageSuggestion (action, hard auth) ──► writes canonical type/priority
```

New components (all `declare(strict_types=1)`):

| Component | Responsibility |
|-----------|----------------|
| `src/Triage/Contracts/TriageDriver.php` | Contract: `triage(string $title, ?string $description): TriageResult` |
| `src/Triage/TriageManager.php` | `extends Illuminate\Support\Manager`; `fake`/`anthropic` factories |
| `src/Triage/Drivers/FakeTriageDriver.php` | Deterministic, no-network result (default + tests) |
| `src/Triage/Drivers/AnthropicDriver.php` | Anthropic Messages API via `Http`; forced tool-use; sanitizes all errors |
| `src/Triage/Support/TriageResult.php` | Immutable DTO |
| `src/Triage/Support/TriagePrompt.php` | Builds system/user prompt + curation (title+description truncation) |
| `src/Triage/Support/TriageError.php` | Maps a `Throwable` to a sanitized code; default `unknown` |
| `src/Triage/Support/TriageGate.php` | `configured(): bool` — `ai.enabled` AND selected driver has its required config |
| `src/Triage/Support/TriageDispatcher.php` | Marks `pending` (debounced) + enqueues the job after commit |
| `src/Triage/Exceptions/TriageException.php` | Carries a sanitized code only; constructed with no `$previous` |
| `src/Listeners/TriageNewFeedback.php` | Subscribes to `FeedbackItemCreated`; gated by `TriageGate`; calls the dispatcher |
| `src/Jobs/TriageFeedbackItem.php` | Resolves driver, writes `ai_state` under lock, throw-based bounded retry, always terminal; `$afterCommit = true` |
| `src/Actions/RequestTriage.php` | Manual trigger: hard auth + gate (422) + dispatcher |
| `src/Actions/ApplyTriageSuggestion.php` | Apply a suggestion to canonical columns: hard auth + guarded column update |

Integration edits to existing files:

| File | Edit |
|------|------|
| `src/FeedbackBoardServiceProvider.php` | `packageRegistered()`: bind `TriageManager` singleton. `packageBooted()`: `Event::subscribe(TriageNewFeedback::class)`; register the Livewire methods/views if new components are added |
| `src/Models/FeedbackItem.php` | Remove `ai_state` and `external_refs` from `$fillable` (server-authored only) |
| `config/feedback-board.php` | Add the `ai` block (Section 7) |
| `resources/lang/en/feedback-board.php`, `resources/lang/ar/feedback-board.php` | Add a `triage` block of UI/label/error strings (Section 6) |
| `src/Livewire/FeedbackDetail.php` + its Blade view | Triage panel + `requestTriage`/`applyTriage` methods + poll-while-pending |
| `src/Filament/Resources/FeedbackItemResource.php` | `recordActions` for triage + per-field apply + a modal that displays the suggestion |
| `README.md` | "AI Triage" section |

Key isolation principle: the job passes the title and description (not the
`FeedbackItem`), so it is structurally impossible to leak email/id/attachments to
the model — not merely a code-comment promise.

## Section 2 — `ai_state['triage']` structure

The reserved `ai_state` (array) column carries a single `triage` key:

```php
$item->ai_state = [
    'triage' => [
        'status'             => 'completed',          // 'pending' | 'completed' | 'failed'
        'model'              => 'claude-haiku-4-5',     // producing model id, or 'fake'; null while pending/failed
        'suggested_type'     => 'bug',                  // valid enum value or null
        'suggested_priority' => 'high',                 // valid enum value or null
        'summary'            => '...',                  // concise, in the input's language; null while pending/failed
        'generated_at'       => '2026-06-28T10:00:00+00:00',
        'error_code'         => null,                   // on failure: connection_error|http_5xx|http_429|auth_401|validation_422|not_found_404|not_configured|invalid_output|unknown
    ],
];
```

States:

- **`pending`** — written by `TriageDispatcher` at dispatch time. `suggested_*`,
  `summary`, `model` are absent/null. The UI shows an "analyzing…" state and polls.
- **`completed`** — the terminal success state written by the job.
- **`failed`** — the terminal failure state written by `failed()`; `suggested_*`,
  `summary`, `model` are null and a sanitized `error_code` is set.

Rules:

- The status is **always** driven to a terminal `completed`/`failed` (the
  retry model in Section 5 guarantees this — there is no path that leaves a
  `pending` record stuck forever for a hard-failing item).
- There is **no `applied` flag.** The UI decides whether to offer "apply" by
  comparing the suggestion to the live column (Section 6), which is correct across
  re-triage and manual edits. Applying overwrites the canonical column with the
  suggested value, and the button only appears when they differ.
- Re-triage (auto or manual) **replaces** the `triage` block (back to `pending`,
  then a new terminal state). Canonical columns are never touched by triage.
- `suggested_type` / `suggested_priority` are stored only if they pass
  `FeedbackType::tryFrom` / `FeedbackPriority::tryFrom`; otherwise `null`.

Concurrency rationale (corrected): cross-column safety does **not** come from the
lock. It comes from (a) the triage write touching **only** the `ai_state` column,
so Eloquent emits `UPDATE … SET ai_state = ?` and never re-persists a stale
`external_refs`, and (b) re-reading the model fresh inside the lock before
mutating. The per-item lock key `feedback-board:ai_state:{id}` exists solely to
serialize concurrent **triage-vs-triage** writes to the same JSON blob (an auto
dispatch racing a manual re-triage). It is deliberately **distinct** from the
Destinations `feedback-board:github:{id}` lock — they guard different columns and
must not be merged.

## Section 3 — Contract, Manager, Fake driver

Contract (text only — minimization enforced by the signature):

```php
interface TriageDriver
{
    public function triage(string $title, ?string $description): TriageResult;
}
```

Immutable DTO:

```php
final class TriageResult
{
    public function __construct(
        public readonly ?FeedbackType $type,
        public readonly ?FeedbackPriority $priority,
        public readonly string $summary,
        public readonly string $model,
    ) {}
}
```

Manager (same pattern as `DestinationManager`):

```php
final class TriageManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('feedback-board.ai.default', 'anthropic');
    }

    protected function createFakeDriver(): TriageDriver { return new FakeTriageDriver(); }
    protected function createAnthropicDriver(): TriageDriver { return new AnthropicDriver(); }
}
```

Registered as a singleton in `packageRegistered()` alongside `DestinationManager`.

`FakeTriageDriver` — deterministic, no network: `type = FeedbackType::Bug`,
`priority = FeedbackPriority::Medium`, `summary = Str::limit($title, 100)`,
`model = 'fake'`. Makes the feature runnable and testable without an API key.

**Test substitution (mirrors Destinations exactly):** tests register a distinct
driver name — `$manager->extend('spy', fn () => …)` — and set
`config(['feedback-board.ai.default' => 'spy'])` so the job (which resolves the
driver from config, not a constructor arg) routes to it. Tests do not override the
built-in `fake` name, avoiding the singleton driver-cache pitfall.

## Section 4 — Anthropic driver

Calls the **Anthropic Messages API** via `Http` (no SDK), with structured output
via forced tool-use. The exact request body:

```php
POST https://api.anthropic.com/v1/messages
Headers: x-api-key: <ai.anthropic.api_key>
         anthropic-version: 2023-06-01
         content-type: application/json
Body: {
  "model":       <ai.anthropic.model>,
  "max_tokens":  <ai.anthropic.max_tokens>,        // required by the API
  "system":      <system prompt>,                   // TOP-LEVEL field, not a message
  "messages":    [{ "role": "user", "content": <title + truncated description> }],
  "tools":       [ submit_triage schema ],
  "tool_choice": { "type": "tool", "name": "submit_triage" }
}
```

`submit_triage` input schema:
- `type`: enum `[bug, feature, question, other]` (may be omitted)
- `priority`: enum `[low, medium, high, urgent]` (may be omitted)
- `summary`: string (required)

Behaviour:

- **Prompt (`TriagePrompt`):** the system message describes the task and the enum
  vocabularies and instructs the model to pick the single best value *or* leave it
  unset when genuinely unclear, and to write a concise summary **in the input's
  language**. The user content is the title plus the description, with the two
  jointly truncated to `ai.max_input_chars`.
- **Network + post-retry guard (mirrors `GithubIssueDriver::guardResponse`):**
  `Http::retry(2, 200, TransientRetry::when(), throw: false)` retries only
  connection/429/5xx during the loop. Because `throw: false` returns the final
  non-2xx response instead of throwing, the driver then calls an explicit guard:
  on a non-2xx final response it calls `$response->throw()` so the failure becomes
  a `RequestException`. (There is no `RetryAfter`/`release` path — see Section 5.)
- **Sanitization of every error path:** the driver wraps the call and converts any
  `ConnectionException`/`RequestException`/other throwable into a `TriageException`
  built from `TriageError::code($e)` with **no `$previous`**. The original
  throwable — whose message/trace can embed the `x-api-key` header — never
  propagates out of the driver.
- **Output validation:** read the `tool_use` block for `submit_triage`; map
  `type`/`priority` via `tryFrom` (invalid/absent → `null`); `summary` must be a
  non-empty string, else throw `TriageException('invalid_output')`. Build
  `TriageResult` with the model id actually used.
- **Missing key:** the driver throws `TriageException('not_configured')` before any
  HTTP call. (The normal paths never reach this — `TriageGate` blocks dispatch and
  the manual action returns 422 — but the driver fails closed defensively.)
- **Config:** `ai.anthropic.{api_key, model, timeout, max_tokens}`; default model
  `claude-haiku-4-5` (cheap, accurate for classification; configurable, and the
  exact pinned id is confirmed during implementation).

## Section 5 — Listener, dispatcher & job (automatic path)

**Listener** — subscribes to `FeedbackItemCreated` only, intentionally **not**
`ShouldQueue` (the only queued unit is the job; this mirrors `DispatchToDestinations`).
It dispatches **nothing** unless triage is fully configured:

```php
final class TriageNewFeedback
{
    public function handle(FeedbackItemCreated $event): void
    {
        if (! TriageGate::configured()) {
            return; // disabled OR selected driver unconfigured (e.g. anthropic w/o key) → inert, no failed records
        }
        TriageDispatcher::dispatch($event->item);
    }

    public function subscribe(Dispatcher $events): array
    {
        return [FeedbackItemCreated::class => 'handle'];
    }
}
```

`TriageGate::configured()` returns `ai.enabled === true` AND, for the selected
driver, its required config is present (`anthropic` ⇒ non-empty `api_key`; `fake`
⇒ always true). The same gate backs `RequestTriage`'s 422.

**`TriageDispatcher::dispatch($item)`** — debounces, marks `pending`, enqueues
after commit:

```php
public static function dispatch(FeedbackItem $item): void
{
    // Under the ai_state lock: re-read fresh; if triage.status is already 'pending', return (debounce).
    // Otherwise write ai_state.triage = ['status' => 'pending', 'generated_at' => now()] (ai_state column only).
    TriageFeedbackItem::dispatch($item->id); // job is ShouldDispatchAfterCommit
}
```

Debounce bounds paid API calls: a second trigger while one is already `pending`
is ignored. (A `completed`/`failed` record is replaced with a fresh `pending`, so
re-triage still works.)

**Job** — passes title+description only, throw-based bounded retry, always terminal:

```php
final class TriageFeedbackItem implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public bool $afterCommit = true; // never run against an uncommitted/rolled-back row
    public int $tries = 3;

    public function __construct(public int $itemId) {}

    public function backoff(): array { return [60, 300]; } // seconds between attempts

    public function handle(TriageManager $manager): void
    {
        $item = FeedbackItem::find($this->itemId);
        if ($item === null) {
            return; // deleted → no-op (afterCommit guarantees the row existed at enqueue time)
        }

        // Driver sanitizes all errors into TriageException; a thrown TriageException
        // re-attempts the job (up to $tries) and, on exhaustion, triggers failed().
        $result = $manager->driver((string) config('feedback-board.ai.default'))
            ->triage($item->title, $item->description); // text only

        $this->writeTriage($item->id, [
            'status'             => 'completed',
            'model'              => $result->model,
            'suggested_type'     => $result->type?->value,
            'suggested_priority' => $result->priority?->value,
            'summary'            => $result->summary,
            'generated_at'       => now()->toIso8601String(),
            'error_code'         => null,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        // Best-effort terminal write; never rethrows (a LockTimeoutException here is swallowed).
        $this->writeTriage($this->itemId, [
            'status'             => 'failed',
            'model'              => null,
            'suggested_type'     => null,
            'suggested_priority' => null,
            'summary'            => null,
            'generated_at'       => now()->toIso8601String(),
            'error_code'         => TriageError::code($e), // TriageException already carries a sanitized code
        ], swallowLockTimeout: true);
    }
}
```

`writeTriage()` locks `feedback-board:ai_state:{id}` on the configured store,
re-reads the item fresh, sets `ai_state['triage']`, and `save()`s — only the
`ai_state` column is dirty. In `handle()` a `LockTimeoutException` propagates (the
job re-attempts; idempotent). In `failed()` it is swallowed (best-effort, like
`DeliverToDestination`'s best-effort notify) so the failure record attempt never
itself crashes the worker.

**Why throw-based, not `release()`:** the whole contract is that `ai_state` ends
in a terminal state. A `release()`d job that later exceeds `retryUntil` can stop
**without** running `failed()`, leaving `pending` forever — indistinguishable from
"never triaged". Throwing on failure guarantees `failed()` runs at `$tries`
exhaustion. Transient errors (429/5xx/connection) are already absorbed by the
inner `Http::retry(2, …)` within a single attempt; the outer `$tries`/`backoff`
covers longer outages. The queue is configurable via `ai.queue`.

## Section 6 — Manual trigger, apply, UI

`RequestTriage` (mirrors `SendItemToGithub`): hard auth, then gate, then dispatcher:

```php
final class RequestTriage
{
    public function handle(FeedbackItem $item, ?Model $actor): void
    {
        abort_unless(FeedbackItemPolicy::isDeveloper($actor), 403);
        abort_unless(TriageGate::configured(), 422);
        TriageDispatcher::dispatch($item);
    }
}
```

`ApplyTriageSuggestion` — applies a suggestion to canonical columns (no `ai_state`
write, so no lock needed; a single guarded column update is atomic and disjoint
from any concurrent triage `ai_state` write):

```php
final class ApplyTriageSuggestion
{
    /** @param array<int, string> $fields  subset of ['type', 'priority'] */
    public function handle(FeedbackItem $item, ?Model $actor, array $fields): void
    {
        abort_unless(FeedbackItemPolicy::isDeveloper($actor), 403);

        $fresh  = FeedbackItem::findOrFail($item->id);     // re-read fresh
        $triage = $fresh->ai_state['triage'] ?? null;
        abort_unless(is_array($triage) && ($triage['status'] ?? null) === 'completed', 422);

        $update = [];
        foreach (array_intersect($fields, ['type', 'priority']) as $field) {
            $suggested = $triage["suggested_{$field}"] ?? null;
            $enum = $field === 'type'
                ? FeedbackType::tryFrom((string) $suggested)
                : FeedbackPriority::tryFrom((string) $suggested);
            if ($enum !== null) {
                $update[$field] = $enum;                    // explicit, whitelisted columns only
            }
        }
        if ($update !== []) {
            $fresh->forceFill($update)->save();             // type/priority only become dirty
        }
    }
}
```

- **No mass assignment:** only `type`/`priority` are whitelisted, and only values
  that pass `tryFrom` are applied; anything else is ignored.
- Per-field apply (a developer may accept the type but not the priority). The
  summary is display-only.
- **Apply visibility is gated on difference, not on a stored flag:** the "apply
  type" button shows iff `status === 'completed'` AND `suggested_type` is non-null
  AND `suggested_type !== item.type->value` (same for priority). This is correct
  across re-triage and across a developer's intervening manual edit — the button
  only ever offers a change the developer can see, and clicking it is a deliberate
  overwrite of the visible current value. (No activity-feed entry and no separate
  applied-audit this cycle — deliberate YAGNI.)

UI surfaces (mirror the Send-to-GitHub wiring across both layers):

- **Livewire `FeedbackDetail`:** a panel that, when `ai_state['triage']` is present,
  branches on `status`:
  - `pending` → an "analyzing…" indicator with `wire:poll.5s` (stops polling once
    the status is terminal);
  - `completed` → the **escaped** summary plus per-field "apply" buttons shown only
    when the suggestion differs from the live column;
  - `failed` → a friendly, code-mapped message and a "re-triage" button.
  Methods `requestTriage(RequestTriage $a)` and
  `applyTriage(string $field, ApplyTriageSuggestion $a)` delegate to the shared
  actions; a "triage / re-triage" button is always available when configured.
- **Filament `FeedbackItemResource`:** the resource has only a list page (no view
  page), so triage is surfaced via `recordActions`, **not** a standalone Infolist:
  - "Triage with AI" → `RequestTriage`;
  - "Apply type" and "Apply priority" → `ApplyTriageSuggestion` each with a fixed
    single-element `$fields`, so the per-field guarantee holds;
  - a "View AI suggestion" modal action renders the current suggestion read-only
    (escaped `TextEntry`, never `->html()`/`->markdown()`).
  Each action uses `->visible(...)` for UX (hidden when unconfigured, or when the
  suggestion doesn't differ) **and** the shared action enforces hard auth + the 422
  gate inside its body (the `filament-action-authorization-trap` lesson — UI hiding
  is not a security control).

## Section 7 — Config block

Add an `ai` block to `config/feedback-board.php`:

```php
'ai' => [
    'enabled'         => env('FEEDBACK_BOARD_AI_ENABLED', false),   // opt-in
    'default'         => env('FEEDBACK_BOARD_AI_DRIVER', 'anthropic'),
    'queue'           => env('FEEDBACK_BOARD_AI_QUEUE'),            // null = default queue
    'lock_store'      => env('FEEDBACK_BOARD_AI_LOCK_STORE'),       // atomic store
    'max_input_chars' => 4000,                                      // combined title+description budget
    'anthropic' => [
        'api_key'    => env('FEEDBACK_BOARD_ANTHROPIC_KEY'),
        'model'      => env('FEEDBACK_BOARD_ANTHROPIC_MODEL', 'claude-haiku-4-5'),
        'timeout'    => 30,
        'max_tokens' => 512,
    ],
],
```

`enabled` defaults to `false`. `lock_store` must be an atomic store
(redis/memcached/database/array) — the same caveat Destinations documents for the
`file` store.

## Section 8 — Security & privacy

- **Opt-in:** `ai.enabled = false` by default; the listener is also inert when the
  selected driver is unconfigured, so enabling AI without a key marks nothing as
  failed.
- **Structural data minimization:** the contract receives `(title, description)`
  only, both length-bounded. The model never receives reporter email/name/id,
  assignee, attachments, `meta`, `external_refs`, or `ai_state`.
- **Server-authored columns:** `ai_state` and `external_refs` are not `$fillable`;
  create/ingest payloads cannot plant a `triage` block, so the apply path cannot be
  tricked into writing an attacker-chosen value.
- **Secrets never escape:** the driver converts every throwable to a
  `TriageException` carrying only a sanitized code and no `$previous`; nothing
  with the key reaches `ai_state`, the log channel, the `failed_jobs` table, or an
  exception reporter.
- **Untrusted output:** `summary`/`model` are rendered escaped only — never
  `{!! !!}`, `->html()`, or `->markdown()`.
- **Hard authorization:** `RequestTriage` and `ApplyTriageSuggestion` call
  `abort_unless(isDeveloper, 403)`; a 422 gate covers unconfigured state; the
  automatic path is gated by `TriageGate`.
- **Mass-assignment prevention:** apply whitelists `type`/`priority` via
  `forceFill` of validated enums; invalid input is ignored.
- **No silent mutation:** AI never writes canonical columns — suggestions stay in
  `ai_state` until a developer applies them.
- **`strict_types`** on all new `src/` (enforced for the whole namespace by `ArchTest`).
- **Documentation:** a README "AI Triage" section covering opt-in, exactly what
  leaves the app, provider/model/key setup, and the cost note (cheap model + the
  combined title+description truncation budget).

## Section 9 — Testing strategy

**Unit:**
- `TriageResult` — DTO holds its values.
- `TriagePrompt` — title **and** description jointly truncated to `max_input_chars`;
  enum vocabularies present in the prompt; user content contains the title and
  description only.
- `AnthropicDriver` (via `Http::fake`) — request body contains `model`,
  `max_tokens` (positive int), top-level `system`, `messages`, forced `tool_choice`,
  and the right headers; parses `tool_use` → `TriageResult`; invalid `type`/`priority`
  → `null`; missing `summary` → `invalid_output`; a non-2xx final response is
  surfaced by the post-retry guard and converted to a sanitized `TriageException`;
  **the request body contains the title and description only**; **a thrown error
  never contains the configured api_key string** (assert against the exception and a
  `Log` spy).
- `TriageError` — exception → sanitized code; default `unknown`; `not_configured`
  for the missing-key exception.
- `FakeTriageDriver` — deterministic output, no network.
- `TriageGate` — `configured()` false when disabled or when `anthropic` lacks a key.

**Feature:**
- Listener calls the dispatcher on `FeedbackItemCreated` only when
  `TriageGate::configured()`; inert when disabled **and** when enabled-without-key.
- `TriageDispatcher` writes `pending` and enqueues; debounces a second dispatch
  while `pending`.
- **After-commit:** a `CreateFeedbackItem` whose surrounding transaction rolls back
  leaves **no** `ai_state['triage']` (job did not run); on `sync` the job runs only
  after commit.
- Job writes `completed` via the spy driver; `failed()` writes `failed` +
  `error_code`; a deleted item → no-op; writing `ai_state` leaves `external_refs`
  intact.
- Mass-assignment: a create/ingest payload cannot set `ai_state` (or
  `external_refs`).
- `RequestTriage` — 403 for a non-developer; 422 when disabled/unconfigured;
  dispatches when developer + configured.
- `ApplyTriageSuggestion` — 403 for a non-developer; applies suggested
  `type`/`priority` to the columns; ignores invalid/non-whitelisted fields; 422 when
  there is no completed triage; a stored markup `summary` renders HTML-escaped in
  both UI layers.
- Apply visibility differs correctly: button hidden when the suggestion equals the
  live column, shown when it differs (covers re-triage and manual-edit cases).
- Livewire `FeedbackDetail` and Filament resource delegate to the shared actions
  with hard auth enforced; the pending panel polls and stops on a terminal state.
- Config test for the `ai` defaults (`enabled = false`, `default = anthropic`,
  default model).

**Arch:** the existing `ArchTest` covers `strict_types` for the namespace automatically.

## Out of Scope (deferred)

Duplicate detection, reply drafts, auto-apply, providers beyond `anthropic`/`fake`,
embeddings, an activity-feed entry or who/when/old-value audit on apply,
honoring a precise `retry-after` delay (the bounded `backoff` approximates it),
streaming/batching. Each is a separate future cycle.
