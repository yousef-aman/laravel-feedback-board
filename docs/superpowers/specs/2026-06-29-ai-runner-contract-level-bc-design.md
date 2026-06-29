# AI Runner Contract (Level B/C) — Design Spec

> **Phase 2-C.** An opt-in, manual, in-app contract that lets a developer dispatch a
> feedback item to their own external coding-agent runner (Claude Code Action / Codex /
> Cursor in CI or a dev box). The runner performs **Level B** (analysis + suggested diff)
> or **Level C** (opens a PR) work and posts results back through the existing signed
> `ingest` endpoint as **developer-reviewed suggestions**. The package never runs the
> agent and never merges.

**Status:** approved design — pending adversarial review, then implementation plan.
**Builds on:** the existing `Destinations` signing/HTTP plumbing (`SignsPayloads`,
`TransientRetry`), the signed `ingest` endpoint (HMAC + timestamp + nonce), the
server-authored `ai_state` / `external_refs` columns, and the Level-A triage layer
(`ai_state.triage`) shipped in Phase 2-B.

---

## Global constraints (every task inherits these)

- **PHP** `^8.2` (runs on 8.4) · **Laravel** `^11 || ^12` · **Livewire 3** · optional
  **Filament v4** (`Filament\Actions\Action` + `->recordActions([...])`).
- `declare(strict_types=1);` at the top of every `src/` file.
- **The package never runs the coding agent and never writes to a git repo or merges.**
  It dispatches a task and stores results. Level C produces a PR *link* only; a human
  opens and merges it.
- **Manual dispatch only.** No auto-dispatch trigger in this cycle.
- **Runner results are suggestions**, exactly like Level-A triage: stored and displayed,
  never auto-applied to canonical columns beyond the server-authored `ai_state.runner`
  bookkeeping and the `external_refs.pr_url` link.
- **Server-authored state.** `ai_state` and `external_refs` are NOT in `$fillable`
  (already enforced). Every write to `ai_state.runner` goes through bounded
  `forceFill` / explicit assignment of a single sub-key — never mass assignment.
- **No secret ever leaves env or is logged.** Outbound HMAC secret (`runner.secret`)
  and the ingest secret are read from config/env only.
- **Structural data minimization (not "no PII").** The task envelope is a curated
  whitelist built by `RunnerTaskPayload`; the raw model is never serialized. It
  *deliberately* sends `item.title`, `item.description`, and `triage.summary` — which MAY
  contain reporter-entered free text (emails, names, IDs) and are required for the runner
  to work. What is excluded is the **structured reporter identity** (`reporter_name` /
  `reporter_email` / `reporter_id`), assignee, `meta`, attachments, and the comment
  thread. The runner endpoint must be treated as confidential (documented in
  `runner-contract.md`). Mirrors the Level-A "data minimization" framing, not an absolute
  "no PII" claim.
- **Untrusted runner output is rendered escaped** (`{{ }}` only — never `{!! !!}`,
  `->html()`, or `->markdown()`).
- **Sanitized errors only.** Dispatch failures store a closed-vocabulary `error_code`;
  no raw message / body / URL / secret is persisted. Mirrors `TriageError` /
  `TriageException` (sanitized code on a dedicated `errorCode` property, no `$previous`).
- **Hard authorization in action bodies** via the existing **static**
  `FeedbackItemPolicy::isDeveloper(?Model $actor)` (never UI-only hiding — the documented
  Filament authorize trap). Action handlers take `?Model $actor` (the established sibling
  pattern: `RequestTriage`, `ApplyTriageSuggestion`, `SendItemToGithub`); call sites pass
  `auth()->user()`. There is no `isDeveloper()` instance method.
- **Namespacing** mirrors the Triage layer: `YousefAman\FeedbackBoard\Runner\…`.

---

## 1. Scope

**In scope (this cycle):**
- `runner` config block (endpoint, secret, enabled, timeouts, output caps, callback url).
- `RunnerGate::configured()`.
- `RunnerTaskPayload` — curated outbound task envelope (+ `task_id`).
- `RunnerDispatcher` — signs and POSTs the envelope (reuses `SignsPayloads` +
  `TransientRetry`), plus `RunnerException` / `RunnerError` for sanitized failures.
- `DispatchToRunner` action (developer-only, hard-authorized) + `DispatchToRunnerJob`
  (queued, after-commit).
- `ai_state.runner` lifecycle state machine.
- `ingest` extension: `runner_status` + `runner_result` actions with `task_id`
  correlation and idempotency.
- Result routing: analysis → **internal** AI comment (`is_internal=true`); diff →
  `ai_state.runner.diff` (capped, escaped); `pr_url` → validated → `external_refs.pr_url`
  + `ai_state.runner.pr_url`.
- UI: Livewire detail panel + Filament record actions, all escaped/hard-authorized.
- Reference runner recipe (receiver + GitHub Actions workflow) + `runner-contract.md`
  docs + README section.
- ar/en lang parity for `runner` keys.

**Out of scope (future cycles):** running the agent · auto-merge · auto-dispatch
triggers · a dispatch-history table · sending attachments or the comment thread ·
multi-runner fan-out · direct GitHub-API outbound adapter · hub aggregation (Phase 2-D).

---

## 2. Configuration (`config/feedback-board.php`)

```php
'runner' => [
    'enabled'            => env('FEEDBACK_BOARD_RUNNER_ENABLED', false),
    'endpoint'           => env('FEEDBACK_RUNNER_ENDPOINT'),       // runner task-receive URL
    'secret'             => env('FEEDBACK_RUNNER_SECRET'),         // HMAC secret for outbound task
    'timeout'            => 8,
    'connect_timeout'    => 4,
    'max_analysis_chars' => 10000,   // cap on the analysis comment body
    'max_diff_chars'     => 20000,   // cap on the stored suggested diff
    'callback_url'       => env('FEEDBACK_RUNNER_CALLBACK_URL'),   // optional; placed in envelope
    'stale_after'        => 1800,    // seconds; UI-only "may be stale" hint, no reaper
],
```

- **`RunnerGate::configured()`** genuinely mirrors `TriageGate` — a **truthy** enabled
  check (so `=true` and `=1` both enable, consistent with `TriageGate` and
  `VerifyIngestSignature`):
  `if (! config('feedback-board.runner.enabled')) return false;` then
  `is_string(endpoint) && endpoint !== '' && is_string(secret) && secret !== ''`.
  The gate is **runner-only** (do NOT fold `ingest.enabled` into it — it also drives the
  §6 panel's visibility; folding would hide the panel instead of explaining a misconfig).
- **Cross-dependency on `ingest` is ENFORCED, not just documented:** post-back travels
  over `ingest`, so `DispatchToRunner` hard-aborts `422` when `ingest.enabled` is false
  (§3.4) — otherwise the item is written `dispatched` and stranded forever (there is no
  reaper). `ingest.secret` must also be set for the round trip.
- **`callback_url` resolution:** if set, used verbatim in the envelope's `callback.ingest_url`;
  otherwise the envelope omits `ingest_url` (the runner is pre-configured with it). The
  package never guesses an absolute URL from request context inside a queued job.
- **Lock-store dependency (explicit, like the config warnings at
  `config/feedback-board.php:36-40,76-80`):** **all** `ai_state.runner` writes reuse
  `feedback-board.ai.lock_store`. Enabling `runner` therefore inherits the
  `LockProvider` requirement **even when AI triage is disabled** — a `file` store
  THROWS on `lock()`, and §3.4 performs the **first** runner write *synchronously inside
  the action* (before the job), so on a default `file` cache the very first dispatch
  would 500 in an otherwise-valid runner-only config. Require `feedback-board.ai.lock_store`
  to point at a real `LockProvider` (`redis`/`memcached`/`dynamodb`/`database`/`array`)
  whenever `runner.enabled` is true; document it next to the gate.

---

## 3. Outbound — `RunnerTaskPayload`, `RunnerDispatcher`, `DispatchToRunner`, `DispatchToRunnerJob`

### 3.1 Task envelope (`Runner\Support\RunnerTaskPayload::build`)

Curated whitelist — the raw model is never serialized. No `reporter_*`, `assignee_*`,
`meta`, or comment thread.

```jsonc
{
  "task_id": "<uuid, one per dispatch>",
  "event": "runner.task",
  "item": {
    "id": 123,
    "type": "bug", "status": "new", "priority": "high",
    "title": "...", "description": "...",
    "project": "default",
    "created_at": "2026-06-29T12:00:00+00:00"
  },
  "triage": {                         // present ONLY if ai_state.triage.status === 'completed'
    "suggested_type": "bug",
    "suggested_priority": "high",
    "summary": "..."
  },
  "callback": {
    "ingest_url": "https://app.example.com/feedback-board/ingest", // omitted if callback_url unset
    "item_id": 123,
    "task_id": "<same token>"
  }
}
```

`build(FeedbackItem $item, string $taskId, ?string $callbackUrl): array` is a pure,
side-effect-free whitelist builder.

- `item.title`, `item.description`, and `triage.summary` are **reporter-authored free
  text** sent verbatim — see the data-minimization constraint; the receiver must be
  treated as confidential.
- **Intentional divergence from `WebhookPayload`:** unlike the reused `WebhookPayload`
  whitelist (which includes `reporter_name`, `WebhookPayload.php:31`), the runner envelope
  excludes **all** `reporter_*` fields — a code runner has no need for reporter PII, and
  the no-reporter-identity rule takes precedence over verbatim parity. A plan-writer must
  not reintroduce `reporter_name` by copying `WebhookPayload`.

### 3.2 `RunnerDispatcher` (the sender — mirrors `WebhookDriver`)

`send(FeedbackItem $item, string $taskId): void`:
1. Read `endpoint` / `secret` from config; if either empty → throw
   `RunnerException('not_configured')` (defense in depth; the gate already guards).
2. `$raw = json_encode(RunnerTaskPayload::build(...), JSON_UNESCAPED_SLASHES |
   JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)` — encode once, sign those exact bytes.
3. `$ts = (string) now()->timestamp; $sig = $this->sign($ts, $raw, $secret);`
4. POST with the same hardened pattern as `WebhookDriver`:
   ```php
   Http::timeout($timeout)->connectTimeout($connectTimeout)
       ->retry(2, 200, TransientRetry::when(), throw: false)
       ->withHeaders([
           'X-Feedback-Timestamp' => $ts,
           'X-Feedback-Signature' => $sig,
           'X-Feedback-Event'     => 'runner.task',
           'X-Feedback-Delivery'  => $taskId,
       ])
       ->withBody($raw, 'application/json')
       ->post($endpoint)
       ->throw();
   ```
5. Catch ladder mirrors `AnthropicDriver`:
   `catch (RunnerException $e) { throw $e; } catch (\Throwable $e) { throw new RunnerException(RunnerError::code($e)); }`
   — no `$previous`, so `(string) $e` can never leak the secret/body.

### 3.3 `RunnerException` / `RunnerError`

- `final class RunnerException extends \RuntimeException` with
  `public readonly string $errorCode` (NOT `code` — `Throwable` reserves `$code`),
  constructed with **no** `$previous`.
- `RunnerError::code(\Throwable $e): string` — closed vocabulary:
  `connection_error | http_5xx | http_429 | auth_401 | not_found_404 | validation_422 |
  not_configured | unknown`. Uses the **same `match` arms as `TriageError::code`**
  (`TriageError.php:28-35`): `RunnerException → $e->errorCode`;
  `ConnectionException → connection_error`; `RequestException` by
  `$e->response?->status() ?? 0` → **`401 || 403 → auth_401`**, `404 → not_found_404`,
  `422 → validation_422`, `429 → http_429`, `>= 500 → http_5xx`, else (incl. null/0)
  `→ unknown`. The `403 → auth_401` collapse is the non-obvious branch and gets its own test.

### 3.4 `DispatchToRunner` action (developer-triggered)

`handle(FeedbackItem $item, ?Model $actor): void` (signature mirrors `RequestTriage` /
`ApplyTriageSuggestion`; `use Illuminate\Database\Eloquent\Model`):
1. `abort_unless(FeedbackItemPolicy::isDeveloper($actor), 403);` (hard, in body — the
   static policy method, `FeedbackItemPolicy.php:13`; call sites pass `auth()->user()`).
2. `abort_unless(RunnerGate::configured(), 422);`
3. `abort_unless((bool) config('feedback-board.ingest.enabled'), 422);` — without ingest
   the post-back can never land (`VerifyIngestSignature:18` aborts 404) and the item would
   be stranded `dispatched` with no failure signal.
4. **Debounce + dispatched-write are ONE locked critical section** under the per-item lock
   `feedback-board:ai_state:{id}` (store `feedback-board.ai.lock_store`):
   - fresh-read `ai_state.runner`;
   - **debounce as a SILENT no-op** (mirrors `TriageDispatcher.php:26-28`, which returns
     on in-flight): `if (status ∈ {'dispatched','running'}) return;` — **not** `abort(409)`,
     because this action is invoked from Livewire/Filament where an `HttpException`
     surfaces as a raw error, and the UI button-disable lags the 5 s poll. Re-dispatch is
     thus allowed only from `null | 'completed' | 'failed'`;
   - else mint `$taskId = (string) Str::uuid()` and merge **only** the `runner` sub-key:
     `['status' => 'dispatched', 'task_id' => $taskId,
     'dispatched_at' => now()->toIso8601String(), 'model' => null,
     'analysis_posted' => false, 'diff' => null, 'pr_url' => null,
     'failed_origin' => null, 'error_code' => null,
     'updated_at' => now()->toIso8601String()]`, then `save()`.
   - On this **synchronous** path a `LockTimeoutException` is **NOT swallowed** — surface
     it as `503` so the caller can retry (the opposite of the queued job's
     `writeRunnerFailed`, §3.5, which swallows it). The `task_id` stale-dispatch guard
     (§3.5) already guarantees at-most-one envelope even under a double-dispatch race, so
     this single critical section is robustness, not the sole correctness mechanism.
5. `DispatchToRunnerJob::dispatch($item->id, $taskId);` (after the lock is released).

### 3.5 `DispatchToRunnerJob` (queued)

Structure mirrors `TriageFeedbackItem`:
- `implements ShouldQueue; use Dispatchable, InteractsWithQueue, Queueable;`
- `public int $tries = 3;` · `backoff(): array { return [60, 300]; }`
- `$this->afterCommit = true;` **set in the constructor** (a typed class property is a
  PHP 8.4 fatal against Queueable's untyped `$afterCommit`).
- `handle(RunnerDispatcher $dispatcher)`:
  - `FeedbackItem::find($itemId)`; null → return (deleted).
  - **Stale-dispatch guard (best-effort, unlocked read):** if
    `ai_state.runner.task_id !== $this->taskId` → return (a newer dispatch superseded this
    job; do not send). This only avoids the common-case wasted POST; integrity under
    concurrent re-dispatch ultimately rests on the inbound `task_id` correlation (§4.3),
    which rejects a superseded send's post-back. Do **not** move this read inside a lock —
    the lock would be released before the network send, so it cannot close the race.
  - `try { $dispatcher->send($item, $this->taskId); }`
    `catch (RunnerException $e)`: transient (`http_429|http_5xx|connection_error`) →
    rethrow (retry); permanent → `writeRunnerFailed($itemId, $taskId, $e->errorCode)`.
    `catch (\Throwable $e)` → `writeRunnerFailed($itemId, $taskId, RunnerError::code($e))`.
  - On success: leave status `dispatched` (the runner advances it via post-back).
- `failed(\Throwable $e)`: `writeRunnerFailed($itemId, $taskId, RunnerError::code($e))`.
- `writeRunnerFailed` writes `ai_state.runner.status='failed'` + sanitized `error_code`
  under the lock **only if `task_id` still matches** (never clobber a newer dispatch);
  swallows `LockTimeoutException` (best-effort terminal write).

---

## 4. Inbound — `ingest` extension (`IngestController`)

Extend the validator's `action` rule to
`in:comment,status,external_ref,runner_status,runner_result` and add two `match` arms.
HMAC signature + timestamp + nonce (`VerifyIngestSignature`) stay exactly as-is.

All `ai_state.runner` reads/writes below happen under the per-item lock
`feedback-board:ai_state:{id}` (store `feedback-board.ai.lock_store`) with a fresh-read +
single-sub-key merge (identical to `writeTriage`). On these **synchronous request paths**
a `LockTimeoutException` is **NOT swallowed** → surface `503` so the runner's HMAC-signed
retry re-attempts (a fresh timestamp ⇒ fresh signature ⇒ fresh nonce key, so the retry is
not blocked by `VerifyIngestSignature:31`). Swallowing and returning `200` would be a
silent lost write.

**Status rank** (drives the monotonicity guard): `dispatched=0, running=1,
completed=2, failed=2`.

### 4.1 `runner_status`

Validation: `{item_id:int, task_id:string, status:in(running,completed,failed),
error_code?:string|regex:/^[a-z0-9_]{1,40}$/}`. If `status='failed'` and `error_code` is
absent → store the default slug `'unknown'` (mirrors `RunnerError` default /
`writeRunnerFailed`). No `in:` allow-list of runner codes (the runner is developer-owned;
the value is rendered escaped).

Under the lock, fresh-read `ai_state.runner`:
- **Correlation:** if `runner.task_id !== payload.task_id` → `abort(409)`.
- **Monotonicity guard** — this is how §5's "out-of-order rejected" is *actually*
  enforced (the `task_id` check alone does NOT catch a reordered post sharing the live
  `task_id`): advance status **only when `rank(posted) > rank(current)`**;
  `rank(posted) <= rank(current)` is an **idempotent `200` no-op** (subsumes "same status
  is a no-op" and drops a late `running` after a terminal). Never transition out of
  `completed`/`failed` for the same `task_id`; `completed ↔ failed` re-posts are
  equal-rank no-ops (do not flip one terminal to the other).
- A runner-posted `failed` records `failed_origin = 'runner'` + the slug `error_code`.
  (Dispatch-side failures, §3.5, record `failed_origin = 'dispatch'`.)
- `runner_result` sub-keys (`diff` / `pr_url` / `analysis_posted`) are written
  independently and are untouched by this guard.

### 4.2 `runner_result`

Validation: `{item_id:int, task_id:string, analysis?:string,
diff?:string, pr_url?:url}` with `analysis` ≤ `max_analysis_chars` and
`diff` ≤ `max_diff_chars` (server also truncates as defense in depth).

- **Correlation:** same `task_id` check → `abort(409)` on mismatch.
- **Terminal-state gate (closes the contradictory `failed` + `pr_url` record):**
  - current `status='failed'` with `failed_origin='runner'` → **reject** the content write
    (`200` no-op; a runner that reported failure must not later contradict itself);
  - current `status='failed'` with `failed_origin='dispatch'` → **heal**: accept the
    result (our send errored but the runner actually ran) and advance to `completed`;
  - otherwise accept normally.
- **`analysis` — atomic, at-most-once via compare-and-set, internal-only:** the
  `analysis_posted` flag and the comment are NOT atomic across the cache lock and
  `ReplyToFeedback`'s own DB transaction, and a retry carries a fresh signature (the nonce
  does not dedupe a re-signed retry), so order it as: **inside the lock**, fresh-read; if
  `analysis_posted` is already true → skip; else set `analysis_posted=true` and persist
  the `ai_state` column **FIRST**; **release the lock**, THEN
  `ReplyToFeedback->handle($item, $analysis, null, AuthorRole::Ai, isInternal: true)`.
  The comment **MUST** be `is_internal=true` — `ReplyToFeedback::handle` defaults
  `$isInternal=false` (`ReplyToFeedback.php:15`), and a non-internal AI comment leaks the
  raw agent text to the reporter UI and emails/webhooks it out (§7). Accepted trade-off: a
  crash strictly between flag-persist and comment-create yields a rare **missing** comment,
  never a duplicate.
- **`diff`:** store truncated text into `ai_state.runner.diff` (under the lock).
- **`pr_url` — `parse_url`-based validation; reject (never truncate) on failure:** require
  (a) `strtolower(scheme) === 'https'`, (b) non-empty `host`, **and** (c) **no
  `user`/`pass` userinfo** — reject `https://github.com@evil.com` (parses to host
  `evil.com` yet renders as a github.com-looking link; `rel=noopener` does NOT mitigate,
  and Laravel's `url` / `url:https` rule does NOT reject userinfo, so these explicit
  `parse_url` checks are the real guard and must not be dropped). Also reject
  `http`/`ftp`/`javascript:`/`data:`. On accept → write `external_refs['pr_url']` **and**
  `ai_state.runner.pr_url`; on reject → store nothing.
  - The `external_refs['pr_url']` merge runs under the existing
    **`feedback-board:github:{id}`** lock (store `feedback-board.destinations.lock_store`),
    fresh-read-merge-save — mirroring `DeliverToDestination::failed` /
    `GithubIssueDriver`, which serialize every `external_refs` read-merge-save to avoid
    lost-updating `external_refs['github']`.
- **Opportunistic terminal advance:** when a `runner_result` carries `pr_url` (or any
  result) while status is `dispatched|running`, advance to `completed` under the same lock
  + monotonicity guard — so a delivered result is never stranded by a lost terminal
  `runner_status` POST.

### 4.3 Correlation & idempotency summary

- HMAC + nonce (middleware) → blocks signature replay (but NOT a re-signed retry).
- `task_id` → binds a post-back to the live dispatch; superseded/duplicate dispatches
  rejected `409`.
- **Monotonicity guard** (§4.1) → status never regresses; terminals are sticky per `task_id`.
- `analysis_posted` compare-and-set (flag-before-comment) → analysis comment at most once
  (rare miss, never duplicate).
- `ai_state.runner` writes: per-item lock + fresh-read + single-sub-key merge
  (≡ `writeTriage`) — this guarantee is **`ai_state`-vs-`ai_state` only**.
- `external_refs['pr_url']` write: serialized under the `feedback-board:github:{id}` lock
  (above); it dirties only the `external_refs` column, so it never clobbers a concurrent
  `ai_state` write, and the github-lock additionally prevents `external_refs`-vs-
  `external_refs` lost updates.

---

## 5. State machine & lifecycle

`ai_state.runner.status`:

| status | meaning |
|---|---|
| `null` / absent | never dispatched |
| `dispatched` | action ran, envelope sent (or queued) — awaiting the runner |
| `running` | runner reported it started |
| `completed` | runner reported terminal success (may carry analysis/diff/pr_url) |
| `failed` | terminal failure, disambiguated by **`failed_origin`**: `'dispatch'` (our send errored, §3.5) or `'runner'` (the runner reported failure, sanitized `error_code`) |

`rank`: `dispatched=0, running=1, completed=2, failed=2`.

Transitions:
- **Dispatch action:** `(null|completed|failed) → dispatched`. While `dispatched|running`
  it is a **silent no-op** (§3.4 — not `409`; `409` is reserved for the ingest correlation
  path). Re-dispatch from a terminal state mints a **new `task_id`** that supersedes the old.
- **`runner_status` post-back:** `dispatched → running → completed|failed`, applied only
  when `task_id` matches **and** `rank(posted) > rank(current)` (§4.1 monotonicity).
  `rank(posted) <= rank(current)` is an idempotent no-op; terminals are sticky per `task_id`.
- **`runner_result` post-back vs current status** (§4.2 terminal-state gate):
  | current status | result action |
  |---|---|
  | `dispatched` / `running` | accept; opportunistically advance to `completed` |
  | `completed` | accept additive sub-keys (diff/pr_url); already terminal |
  | `failed (origin=dispatch)` | **heal** → accept + advance to `completed` |
  | `failed (origin=runner)` | **reject** content write (`200` no-op) |
- **Supersession:** after re-dispatch, stale runners' posts fail the `task_id` check (`409`).

**Stale handling:** no background reaper (YAGNI). The UI shows `dispatched_at` and a
soft "may be stale" hint after `runner.stale_after` seconds; re-dispatch is the escape
hatch (it supersedes the in-flight task). Because §4.2 advances status on a result-bearing
post-back, a lost terminal `runner_status` POST does not strand a delivered result.

---

## 6. UI (Livewire + Filament)

All runner output is rendered **escaped** (`{{ }}` only).

**Livewire `FeedbackDetail` — "Runner" panel.** Hidden unless `RunnerGate::configured()`
and the viewer is a developer. By status:
- `null` → **"Send to agent"** button (`dispatchToRunner(DispatchToRunner $action)`).
- `dispatched|running` → status badge + `dispatched_at` + `wire:poll.5s` + a "may be
  stale" hint past `stale_after`. Re-dispatch disabled while in-flight. **Also render
  `diff` (collapsible, escaped) and the `pr_url` link (escaped, `rel="noopener noreferrer"`)
  whenever they are present** — a result can arrive before the terminal `runner_status`,
  and it must not stay invisible while the status is non-terminal.
- `completed` → analysis (already a comment) + diff (collapsible, escaped) + **PR link**
  (escaped, `rel="noopener noreferrer"`) + re-dispatch button.
- `failed` → sanitized `error_code` (+ `failed_origin`) + re-dispatch button.
- When `RunnerGate::configured()` but `ingest.enabled` is false → show an **"ingest
  disabled — post-back will fail"** warning instead of the send button (the dispatch
  action would 422 anyway, §3.4).

The Livewire method calls `$this->guard()` first (developer check symmetric with the
action — `FeedbackItemPolicy::isDeveloper(auth()->user())`), delegates to the action with
`auth()->user()`, then refreshes.

**Filament `FeedbackItemResource` — record actions:**
- `sendToRunner` — `abort_unless(FeedbackItemPolicy::isDeveloper($actor), 403)` in the
  action body, then the action.
- `viewRunnerResult` — modal rendering `analysis` / `diff` / `pr_url` via an escaped
  Blade view (`feedback-board::filament.runner-result`).

`pr_url` is validated server-side at ingest time (§4.2); the UI renders it as a link with
`rel="noopener noreferrer"`.

---

## 7. Security & authorization

- **Manual only**; `abort_unless(FeedbackItemPolicy::isDeveloper($actor), 403)` in the
  action body (not UI hiding), then `abort_unless(RunnerGate::configured(), 422)` and
  `abort_unless((bool) config('feedback-board.ingest.enabled'), 422)`.
- **Envelope is a curated whitelist** (`RunnerTaskPayload`) — excludes the structured
  **reporter identity** (`reporter_*`), assignee, `meta`, attachments, and the comment
  thread; includes only the triage *suggestion*. `item.title` / `item.description` /
  `triage.summary` are reporter-authored free text sent verbatim (data minimization, not
  "no PII"). Raw model never serialized.
- **Runner `analysis` is an internal-only comment** (`is_internal=true`) — this is a
  hard guarantee, not incidental: a non-internal AI comment would surface untrusted runner
  text to the reporter UI and email/webhook it out (`ReplyToFeedback.php:15` defaults
  `false`; the three leak paths are `FeedbackItemModal:35-36`,
  `SendFeedbackNotifications::onReplied`, `DispatchToDestinations::onReplied`).
- **Outbound HMAC-signed** (`SignsPayloads`); secret from env only, never logged.
- **`task_id` correlation** — per-dispatch token; mismatched post-backs rejected (`409`).
  Status is monotonic and terminals are sticky per `task_id` (§4.1).
- **Inbound** = HMAC + timestamp + nonce (replay) + `task_id` correlation; each call
  only touches its authorized item (`item_id` is covered by the signature).
- **No auto-merge, no repo writes by the package** — only a PR *link* is stored; a human
  opens/merges. Level C blast radius preserved: the runner runs in CI/dev with scoped
  credentials, **never in production**; secrets are never exposed to a feedback item.
- **`ai_state.runner` + `external_refs` server-authored** (outside `$fillable`); result
  writes go through bounded `forceFill` of specific keys.
- **Untrusted output** stored capped (`max_analysis_chars` / `max_diff_chars`) and
  rendered escaped; `pr_url` is `parse_url`-validated (https scheme, non-empty host,
  **no userinfo** — rejecting `https://github.com@evil.com`) and rendered with `rel=noopener`.
- **Dispatch failures** store a sanitized `error_code` only (closed `RunnerError`
  vocabulary); the runner-supplied `error_code` is constrained to `^[a-z0-9_]{1,40}$`.
- `declare(strict_types=1);` across `src/`.

---

## 8. Reference runner recipe & docs

**Architectural note (stated explicitly):** the outbound POST is HMAC-signed to
`runner.endpoint`. GitHub Actions cannot be triggered by an HMAC POST directly (it needs
a Bearer token + `repository_dispatch`). The honest architecture is therefore
**package → (HMAC) → a thin developer-owned receiver → CI**. The receiver is part of
"the runner." Targeting the GitHub API directly is a possible future outbound adapter and
is out of scope here.

Ship under `docs/examples/runner/`:
1. **`receiver.example.php`** (and/or `.js`, ~30 lines) — verifies the HMAC signature,
   then calls GitHub `repository_dispatch` with `client_payload` = the envelope.
2. **`github-actions-feedback-runner.example.yml`** — a `repository_dispatch`-triggered
   workflow that reads the task (title / description / triage), runs Claude Code Action,
   and on completion calls `ingest` (`curl`, HMAC-signed) with `runner_status` +
   `runner_result` (analysis / diff / pr_url). Includes the HMAC-signing snippet.
3. **`docs/runner-contract.md`** (+ a README section) — precisely documents the outbound
   envelope schema, the headers (`X-Feedback-Timestamp` / `-Signature` / `-Event` /
   `-Delivery`, plus `task_id` in the body), the two post-back actions and their payloads,
   the HMAC scheme, and the security requirements (no production, scoped credentials, no
   auto-merge), with a worked example. It MUST also state, à la the Level-A docs, **exactly
   what leaves the app**, plus an operator-facing privacy note: *"the runner endpoint
   receives feedback body text — treat it as confidential reporter data; do not point it
   at an untrusted third-party receiver."* It documents that the runner **should retry the
   terminal `runner_status` POST** (and that a result-bearing `runner_result` also advances
   status, §4.2, as a backstop), and that `ingest.enabled` must be true for post-back.

The contract is **runner-agnostic**; the example happens to use GitHub Actions + Claude
Code Action, but any runner that implements the contract works.

---

## 9. Testing strategy (Pest + Orchestra Testbench, TDD)

**Unit:**
- `RunnerGate` — configured/​not-configured matrix (truthy enabled flag, endpoint, secret).
- `RunnerTaskPayload::build` — asserts the whitelist on an item constructed with a
  **non-null `reporter_name`** (so the "no `reporter_*`" assertion is meaningfully
  exercised, not vacuous); includes `triage` only when
  `ai_state.triage.status === 'completed'`; honors `callback_url` (present → `ingest_url`
  set; absent → omitted).
- `RunnerError::code` — each branch maps to the expected sanitized code, **including the
  non-obvious `403 → auth_401` collapse** and null/0-status `→ unknown`.

**Feature:**
- `DispatchToRunner` action: 403 for non-developer (**`Http::assertNothingSent`** and
  `ai_state.runner` unchanged) · 422 when runner not configured · **422 when
  `ingest.enabled` is false** (unchanged state, nothing sent, no job) · happy path writes
  `dispatched` + dispatches the job · **silent no-op (not 409) while in-flight** — a second
  call returns without error, sends nothing, and does not change `task_id`.
- `DispatchToRunnerJob`: `Http::fake` — signed envelope sent with correct headers ·
  transient → retry · permanent → `failed` with sanitized code · **after-commit**
  (a rollback means nothing is sent) · stale-dispatch guard (mismatched `task_id` → no send).
- Envelope signature round-trip (`sign` → `verifySignature`).
- ingest `runner_status`: matching `task_id` advances state · mismatch → `409` ·
  unsigned/stale/replayed still rejected · terminal `failed` stores the safe slug ·
  **`failed` without `error_code` → stored as `'unknown'`** · **monotonicity**: a late
  `running` after `completed`/`failed` is a no-op (no regression); a `completed ↔ failed`
  re-post does not flip the terminal.
- ingest `runner_result`: **`analysis` creates an `is_internal=true` AI comment** — assert
  the comment's `is_internal === true`, that a non-developer viewing `FeedbackItemModal`
  does NOT see it, and (with `Notification::fake()` + `Bus`/`Queue::fake()`) that **no
  reporter notification fires and no `DeliverToDestination` job is dispatched** · capped ·
  `analysis_posted` idempotent (a re-post creates **no** second comment) · `diff` → stored
  capped · `pr_url` validated → `external_refs.pr_url` + `ai_state.runner.pr_url`;
  **rejected (nothing stored) for `https://github.com@evil.com` (userinfo), `http://`,
  `ftp:`, `javascript:`, `data:`, and host-less `https:///path`; accepted for a real
  `https://github.com/o/r/pull/5`** · `task_id` mismatch → `409`.
- ingest `runner_result` **terminal-state gate**: arriving while `failed(origin='dispatch')`
  → heals to `completed`; arriving while `failed(origin='runner')` → `200` no-op, no write.
- ingest `runner_result` **opportunistic advance**: a `pr_url`-bearing result while
  `dispatched`/`running` advances status to `completed`.
- **Concurrency**: two `runner_result` posts with the same `task_id` (and a simulated crash
  between flag-persist and comment-create) → **exactly one** comment.
- UI: the Runner panel renders `diff`/`pr_url` **while status is `dispatched`/`running`**
  (not only `completed`).
- State machine: re-dispatch from a terminal state mints a new `task_id`; an old
  post-back is then rejected (`409`).
- UI: Livewire panel renders each state and escapes `analysis` / `diff` / `pr_url`;
  Filament actions are hard-authorized (direct invocation → abort).
- Lang parity ar/en for the `runner` keys.

---

## 10. Files

**Create:**
- `src/Runner/Support/RunnerGate.php`
- `src/Runner/Support/RunnerTaskPayload.php`
- `src/Runner/Support/RunnerError.php`
- `src/Runner/RunnerDispatcher.php`
- `src/Runner/Exceptions/RunnerException.php`
- `src/Actions/DispatchToRunner.php` (`handle(FeedbackItem, ?Model $actor)` —
  `use Illuminate\Database\Eloquent\Model;` + static `FeedbackItemPolicy::isDeveloper`)
- `src/Jobs/DispatchToRunnerJob.php`
- `resources/views/filament/runner-result.blade.php`
- `docs/examples/runner/receiver.example.php`
- `docs/examples/runner/github-actions-feedback-runner.example.yml`
- `docs/runner-contract.md`
- Tests: `tests/Unit/{RunnerGateTest,RunnerTaskPayloadTest,RunnerErrorTest}.php`,
  `tests/Feature/Runner/{ConfigTest,RunnerStateTest,DispatcherTest,DispatchActionTest,DispatchJobTest,IngestRunnerStatusTest,IngestRunnerResultTest,RunnerLivewireTest,RunnerFilamentTest,RunnerLangTest,DocsTest}.php`.
  (State-machine supersession is covered end-to-end across `DispatchActionTest`
  — terminal re-dispatch mints a fresh `task_id` — and `IngestRunnerStatusTest` —
  the superseded `task_id` post-back is rejected `409`; no separate `StateMachineTest`.)

**Modify:**
- `config/feedback-board.php` — add the `runner` block.
- `src/Http/Controllers/IngestController.php` — add `runner_status` + `runner_result`.
- `src/Livewire/FeedbackDetail.php` — add `dispatchToRunner` + runner state accessors.
- `resources/views/livewire/feedback-detail.blade.php` — add the Runner panel.
- `src/Filament/Resources/FeedbackItemResource.php` — add `sendToRunner` +
  `viewRunnerResult` record actions.
- `resources/lang/{en,ar}/feedback-board.php` — add the `runner` block (parity).
- `README.md` — "AI Runner Contract (Level B/C)" section.

---

## 11. Open questions / deferred

- Direct GitHub-API outbound adapter (skip the receiver) — future.
- Dispatch-history table for audit / re-run timelines (Approach 2) — future upgrade.
- A background stale-reaper job — deferred; re-dispatch supersedes for now.
- Sending attachments / the comment thread to the runner — deferred (privacy surface).
