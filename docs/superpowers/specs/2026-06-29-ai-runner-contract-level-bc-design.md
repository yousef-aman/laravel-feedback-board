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
- **No reporter PII leaves the app.** The task envelope is a curated whitelist built by
  `RunnerTaskPayload`; the raw model is never serialized.
- **Untrusted runner output is rendered escaped** (`{{ }}` only — never `{!! !!}`,
  `->html()`, or `->markdown()`).
- **Sanitized errors only.** Dispatch failures store a closed-vocabulary `error_code`;
  no raw message / body / URL / secret is persisted. Mirrors `TriageError` /
  `TriageException` (sanitized code on a dedicated `errorCode` property, no `$previous`).
- **Hard authorization in action bodies** (`abort_unless`), never UI-only hiding
  (the documented Filament authorize trap).
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
- Result routing: analysis → AI comment; diff → `ai_state.runner.diff` (capped,
  escaped); `pr_url` → validated → `external_refs.pr_url` + `ai_state.runner.pr_url`.
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

- **`RunnerGate::configured()`** (mirrors `TriageGate`):
  `config('feedback-board.runner.enabled') === true`
  `&& is_string(endpoint) && endpoint !== ''`
  `&& is_string(secret) && secret !== ''`. Fail-closed.
- **Documented cross-dependency:** post-back travels over `ingest`, so `ingest.enabled`
  must be `true` and `ingest.secret` set for the round trip to complete. The dispatch
  gate checks `runner` config only; the docs state ingest must be enabled for post-back.
- **`callback_url` resolution:** if set, used verbatim in the envelope's `callback.ingest_url`;
  otherwise the envelope omits `ingest_url` (the runner is pre-configured with it). The
  package never guesses an absolute URL from request context inside a queued job.

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
  not_configured | unknown`. `RunnerException → $e->errorCode`;
  `ConnectionException → connection_error`; `RequestException` mapped by status;
  default `unknown`.

### 3.4 `DispatchToRunner` action (developer-triggered)

`handle(FeedbackItem $item, ?Authenticatable $user): void`:
1. `abort_unless($this->isDeveloper($user), 403);` (hard, in body).
2. `abort_unless(RunnerGate::configured(), 422);`
3. Fresh re-read of the item; **debounce**: if
   `ai_state.runner.status ∈ {'dispatched','running'}` → `abort(409)` (in-flight).
   Re-dispatch is allowed only from `null | 'completed' | 'failed'`.
4. `$taskId = (string) Str::uuid();` write
   `ai_state.runner = ['status' => 'dispatched', 'task_id' => $taskId,
   'dispatched_at' => now()->toIso8601String(), 'model' => null, 'analysis_posted' => false,
   'diff' => null, 'pr_url' => null, 'error_code' => null, 'updated_at' => now()->toIso8601String()]`
   under the per-item lock `feedback-board:ai_state:{id}` (store
   `feedback-board.ai.lock_store`), fresh-read-and-merge the `runner` sub-key only.
5. `DispatchToRunnerJob::dispatch($item->id, $taskId);`

### 3.5 `DispatchToRunnerJob` (queued)

Structure mirrors `TriageFeedbackItem`:
- `implements ShouldQueue; use Dispatchable, InteractsWithQueue, Queueable;`
- `public int $tries = 3;` · `backoff(): array { return [60, 300]; }`
- `$this->afterCommit = true;` **set in the constructor** (a typed class property is a
  PHP 8.4 fatal against Queueable's untyped `$afterCommit`).
- `handle(RunnerDispatcher $dispatcher)`:
  - `FeedbackItem::find($itemId)`; null → return (deleted).
  - **Stale-dispatch guard:** if `ai_state.runner.task_id !== $this->taskId` → return
    (a newer dispatch superseded this job; do not send).
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

### 4.1 `runner_status`

Validation: `{item_id:int, task_id:string, status:in(running,completed,failed),
error_code?:string|regex:/^[a-z0-9_]{1,40}$/}`.

- Load item; load `ai_state.runner`.
- **Correlation:** if `runner.task_id !== payload.task_id` → `abort(409)` (superseded /
  duplicate runner). Idempotent: re-posting the same status is a no-op success.
- Advance `ai_state.runner.status` to the posted value (under the per-item lock,
  fresh-read-merge). `failed` stores the validated `error_code` (already a safe slug).

### 4.2 `runner_result`

Validation: `{item_id:int, task_id:string, analysis?:string,
diff?:string, pr_url?:url}` with `analysis` ≤ `max_analysis_chars` and
`diff` ≤ `max_diff_chars` (server also truncates as defense in depth).

- **Correlation:** same `task_id` check → `abort(409)` on mismatch.
- `analysis` (if present and `ai_state.runner.analysis_posted` is false):
  `ReplyToFeedback->handle($item, $analysis, null, AuthorRole::Ai)` (internal AI comment);
  set `ai_state.runner.analysis_posted = true` (idempotency — re-posts don't duplicate).
- `diff` (if present): store truncated text into `ai_state.runner.diff` (under the lock).
- `pr_url` (if present): **validate** `https` scheme + non-empty host (Laravel `url`
  rule plus an explicit `https`-scheme check; reject `http`, `javascript:`, data URIs);
  write `external_refs['pr_url']` (forceFill merge) **and** `ai_state.runner.pr_url`.
- This action does NOT itself set terminal status; the runner sends a separate
  `runner_status: completed`. (Order-independent: result may arrive before or after the
  terminal status; both are idempotent.)

### 4.3 Correlation & idempotency summary

- HMAC + nonce (middleware) → blocks signature replay.
- `task_id` → binds a post-back to the live dispatch; superseded/duplicate dispatches
  are rejected with `409`.
- `analysis_posted` flag → analysis comment is written at most once.
- All `ai_state.runner` writes: per-item lock + fresh-read + single-sub-key merge
  (identical discipline to the triage job's `writeTriage`). `external_refs` writes only
  dirty the `external_refs` column, so they never clobber a concurrent `ai_state` write.

---

## 5. State machine & lifecycle

`ai_state.runner.status`:

| status | meaning |
|---|---|
| `null` / absent | never dispatched |
| `dispatched` | action ran, envelope sent (or queued) — awaiting the runner |
| `running` | runner reported it started |
| `completed` | runner reported terminal success (may carry analysis/diff/pr_url) |
| `failed` | our dispatch failed, or the runner reported failure (sanitized `error_code`) |

Transitions:
- **Dispatch action:** `(null|completed|failed) → dispatched`. Rejected (`409`) while
  `dispatched|running`. Re-dispatch from a terminal state mints a **new `task_id`** that
  supersedes the old one.
- **`runner_status` post-back:** `dispatched → running → completed|failed`, applied only
  when `task_id` matches. Out-of-order / superseded posts are rejected.
- **Supersession:** after re-dispatch, stale runners' posts fail the `task_id` check.

**Stale handling:** no background reaper (YAGNI). The UI shows `dispatched_at` and a
soft "may be stale" hint after `runner.stale_after` seconds; re-dispatch is the escape
hatch (it supersedes the in-flight task).

---

## 6. UI (Livewire + Filament)

All runner output is rendered **escaped** (`{{ }}` only).

**Livewire `FeedbackDetail` — "Runner" panel.** Hidden unless `RunnerGate::configured()`
and the viewer is a developer. By status:
- `null` → **"Send to agent"** button (`dispatchToRunner(DispatchToRunner $action)`).
- `dispatched|running` → status badge + `dispatched_at` + `wire:poll.5s` + a "may be
  stale" hint past `stale_after`. Re-dispatch disabled while in-flight.
- `completed` → analysis (already a comment) + diff (collapsible, escaped) + **PR link**
  (escaped, `rel="noopener noreferrer"`) + re-dispatch button.
- `failed` → sanitized `error_code` + re-dispatch button.

The Livewire method calls `$this->guard()` first (developer check symmetric with the
action), delegates to the action with `auth()->user()`, then refreshes.

**Filament `FeedbackItemResource` — record actions:**
- `sendToRunner` — `abort_unless(isDeveloper, 403)` in the action body, then the action.
- `viewRunnerResult` — modal rendering `analysis` / `diff` / `pr_url` via an escaped
  Blade view (`feedback-board::filament.runner-result`).

`pr_url` is validated server-side at ingest time; the UI renders it as a link with
`rel="noopener noreferrer"`.

---

## 7. Security & authorization

- **Manual only**; `abort_unless(isDeveloper, 403)` in the action body (not UI hiding),
  then `abort_unless(RunnerGate::configured(), 422)`.
- **Envelope is a curated whitelist** (`RunnerTaskPayload`) — no reporter PII, no internal
  comments, no ai/external columns beyond the triage suggestion. Raw model never serialized.
- **Outbound HMAC-signed** (`SignsPayloads`); secret from env only, never logged.
- **`task_id` correlation** — per-dispatch token; mismatched post-backs rejected (`409`).
- **Inbound** = HMAC + timestamp + nonce (replay) + `task_id` correlation; each call
  only touches its authorized item (`item_id` is covered by the signature).
- **No auto-merge, no repo writes by the package** — only a PR *link* is stored; a human
  opens/merges. Level C blast radius preserved: the runner runs in CI/dev with scoped
  credentials, **never in production**; secrets are never exposed to a feedback item.
- **`ai_state.runner` + `external_refs` server-authored** (outside `$fillable`); result
  writes go through bounded `forceFill` of specific keys.
- **Untrusted output** stored capped (`max_analysis_chars` / `max_diff_chars`) and
  rendered escaped; `pr_url` https-validated and rendered with `rel=noopener`.
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
   auto-merge), with a worked example.

The contract is **runner-agnostic**; the example happens to use GitHub Actions + Claude
Code Action, but any runner that implements the contract works.

---

## 9. Testing strategy (Pest + Orchestra Testbench, TDD)

**Unit:**
- `RunnerGate` — configured/​not-configured matrix (enabled flag, endpoint, secret).
- `RunnerTaskPayload::build` — asserts the whitelist (no `reporter_*` / PII keys);
  includes `triage` only when `ai_state.triage.status === 'completed'`; honors `callback_url`.
- `RunnerError::code` — each branch maps to the expected sanitized code.

**Feature:**
- `DispatchToRunner` action: 403 for non-developer (**`Http::assertNothingSent`** and
  `ai_state.runner` unchanged) · 422 when not configured · happy path writes `dispatched`
  + dispatches the job · `409` debounce while in-flight.
- `DispatchToRunnerJob`: `Http::fake` — signed envelope sent with correct headers ·
  transient → retry · permanent → `failed` with sanitized code · **after-commit**
  (a rollback means nothing is sent) · stale-dispatch guard (mismatched `task_id` → no send).
- Envelope signature round-trip (`sign` → `verifySignature`).
- ingest `runner_status`: matching `task_id` advances state · mismatch → `409` ·
  unsigned/stale/replayed still rejected · terminal `failed` stores the safe slug.
- ingest `runner_result`: `analysis` → one AI comment (capped, `analysis_posted`
  idempotent) · `diff` → stored capped · `pr_url` validated (rejects non-`https` /
  `javascript:`) → `external_refs.pr_url` + `ai_state.runner.pr_url` · `task_id` mismatch → `409`.
- State machine: re-dispatch from a terminal state mints a new `task_id`; an old
  post-back is then rejected.
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
- `src/Actions/DispatchToRunner.php`
- `src/Jobs/DispatchToRunnerJob.php`
- `resources/views/filament/runner-result.blade.php`
- `docs/examples/runner/receiver.example.php`
- `docs/examples/runner/github-actions-feedback-runner.example.yml`
- `docs/runner-contract.md`
- Tests: `tests/Unit/{RunnerGateTest,RunnerTaskPayloadTest,RunnerErrorTest}.php`,
  `tests/Feature/Runner/{ConfigTest,DispatchActionTest,DispatchJobTest,IngestRunnerStatusTest,IngestRunnerResultTest,StateMachineTest,RunnerLivewireTest,RunnerFilamentTest,RunnerLangTest}.php`.

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
