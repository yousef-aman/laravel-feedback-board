# Task 13 Report: Reference recipe + docs (AI Runner Contract)

## Status: DONE

---

## Files Created

1. `tests/Feature/Runner/DocsTest.php` — Pest 3 presence + content-shape test (exact code from brief).
2. `docs/runner-contract.md` — Full contract document (8 sections, complete prose).
3. `docs/examples/runner/receiver.example.php` — ~30-line HMAC-verifying PHP receiver (verbatim from brief).
4. `docs/examples/runner/github-actions-feedback-runner.example.yml` — GitHub Actions `repository_dispatch` workflow (verbatim from brief).
5. `README.md` — `## AI Runner Contract (Level B/C)` section appended before the License section.

---

## TDD: RED → GREEN Evidence

**RED** (`vendor/bin/pest tests/Feature/Runner/DocsTest.php` before docs created):
```
FAIL  Tests\Feature\Runner\DocsTest
⨯ it ships the runner contract docs and reference recipe   0.23s
Failed asserting that false is true.  (docs/runner-contract.md missing)
Tests:  1 failed (1 assertions)
```

**GREEN** (after all docs files created):
```
PASS  Tests\Feature\Runner\DocsTest
✓ it ships the runner contract docs and reference recipe   0.24s
Tests:  1 passed (8 assertions)
```

---

## Full Suite Final Result

```
Tests:  194 passed (525 assertions)
Duration: 15.15s
```

Prior count: 193. New total: 194 (+1 DocsTest). All green.

---

## Self-Review: Mandatory Content Directives in runner-contract.md

| Directive | Present? | Location |
|-----------|----------|----------|
| `runner_status` action with `item_id, task_id, status: running|completed|failed, error_code?` matching `^[a-z0-9_]{1,40}$` | YES | §4.2 |
| `runner_result` action with `item_id, task_id, analysis?, diff?, pr_url?` | YES | §4.3 |
| `X-Feedback-Signature` header documented | YES | §3 table |
| `task_id` present and documented (echo verbatim) | YES | §2, §4.2, §4.3, §5 |
| `triage.suggested_type` and `triage.suggested_priority` MAY be `null` | YES | §2 field notes |
| `triage.summary` is always non-null string | YES | §2 field notes |
| `pr_url` MUST be `https` with NO userinfo; `https://github.com@evil.com` rejected | YES | §4.3 |
| Status is monotonic (terminals sticky per task_id) | YES | §4.2, §5 |
| Runner SHOULD retry terminal `runner_status` POST | YES | §4.2, §5 |
| `runner_result` also advances status | YES | §4.3, §5 |
| `ingest.enabled` must be true for post-back | YES | §4 intro, §5 |
| HMAC formula `hash_hmac('sha256', "{ts}.{rawBody}", secret)` documented | YES | §3 |
| Privacy note (reporter body text = confidential, do not point at untrusted receiver) | YES | §2 |
| Security: runner in CI/dev, NEVER production | YES | §6 |
| Security: no auto-merge (PR link only) | YES | §6 |
| Security: secrets never placed in feedback item | YES | §6 |
| Same HMAC formula for both outbound runner secret and inbound ingest secret | YES | §3, §4.1 |
| Worked end-to-end example | YES | §7 |
| README `## AI Runner Contract (Level B/C)` section | YES | README.md |

---

## Concerns

None. All directives satisfied. Full suite green at 194 tests.

---

## Commit

`99c440f` — docs(runner): contract docs + reference receiver/GitHub Actions recipe

---

## Fix Round — runner-contract envelope drift

**Date:** 2026-06-30

### What Changed

**`docs/runner-contract.md` — §2 schema and §7 worked example:**

1. **Removed `dispatched_at`** from both the §2 JSON example and the §7 literal HTTP request body. This field does not exist in `RunnerTaskPayload::build()`.
2. **Added missing root fields** — `event: "runner.task"` added to §2 and §7 examples.
3. **Expanded `item` object** — §2 and §7 now show the full set of fields built by `build()`: `id, type, status, priority, title, description, project, created_at`. Previously only `id/title/description` appeared.
4. **Added `callback` object** — §2 and §7 now document `{ ingest_url?, item_id, task_id }` with the note that `ingest_url` is present only when `runner.callback_url` is configured.
5. **Fixed `triage` block** — documented as conditional (absent entirely when Level A never ran). `summary` documented as "string, normally non-null" (built as `$triage['summary'] ?? null`). Removed the false claim that `summary` is "always a non-null string" or "empty string if Level A not run". Updated field-note order to `suggested_type, suggested_priority, summary`.
6. **Added `error_code` default behaviour** — documented that if `status: failed` is posted without `error_code` (or with empty string), the stored value defaults to `'unknown'` (per `RunnerIngest::status()`).

**`docs/examples/runner/github-actions-feedback-runner.example.yml`:**

7. **Added `runner_status running` POST** — inserted before the `runner_result` POST in the "Post results back" step, so the canonical runnable example matches the §7 prose that says the runner sends `runner_status running` first.

### Final §2 Envelope Schema vs. `RunnerTaskPayload::build()`

| `build()` key | Doc §2 | Notes |
|---|---|---|
| `task_id` | present | ULID string |
| `event` | present | always `"runner.task"` |
| `item.id` | present | |
| `item.type` | present | enum value or null |
| `item.status` | present | enum value or null |
| `item.priority` | present | enum value or null |
| `item.title` | present | |
| `item.description` | present | |
| `item.project` | present | |
| `item.created_at` | present | ISO 8601 or null |
| `triage` (conditional) | present, marked conditional | absent when `ai_state.triage.status !== 'completed'` |
| `triage.suggested_type` | present, nullable | |
| `triage.suggested_priority` | present, nullable | |
| `triage.summary` | present, normally non-null | |
| `callback.ingest_url` | present, marked optional | present only when `runner.callback_url` set |
| `callback.item_id` | present | |
| `callback.task_id` | present | |
| `dispatched_at` | REMOVED | was never in `build()` |

Zero remaining drift confirmed.

### DocsTest

Command: `vendor/bin/pest tests/Feature/Runner/DocsTest.php`

```
PASS  Tests\Feature\Runner\DocsTest
  ✓ it ships the runner contract docs and reference recipe   0.23s

  Tests:    1 passed (8 assertions)
  Duration: 0.33s
```

All four required literal strings (`runner_status`, `runner_result`, `X-Feedback-Signature`, `task_id`) are present in the final doc.

---

## Final-review fix wave

**Date:** 2026-06-30  
**Branch:** feature/ai-runner-contract-level-bc  

### FIX 1 — Lock the MANUAL re-triage path (CRITICAL)

**Problem:** `TriageDispatcher::dispatch()` did an unlocked full-column `ai_state` read-modify-write. A manual re-triage racing a runner write (which IS locked) would clobber `ai_state.runner` sub-key from its stale read — reverting `analysis_posted` (→ duplicate internal comment) or a terminal runner status.

**Fix:**
- `src/Triage/Support/TriageDispatcher.php` — refactored to `dispatch(FeedbackItem $item, bool $locked = false)`. Added private `writePending(int $id): bool` (unlocked, returns bool) and `lockedWritePending(int $id): bool` (wraps `writePending` in the `feedback-board:ai_state:{id}` cache lock mirroring `TriageFeedbackItem::writeTriage`). The listener path (`TriageNewFeedback`) stays unlocked; the manual path (`RequestTriage`) calls `dispatch($item, locked: true)`.
- `src/Actions/RequestTriage.php` — changed call to `TriageDispatcher::dispatch($item, locked: true)`.

**Regression test:** `tests/Feature/Runner/TriageDispatcherLockTest.php`  
Command: `vendor/bin/pest tests/Feature/Runner/TriageDispatcherLockTest.php`
```
PASS  Tests\Feature\Runner\TriageDispatcherLockTest
  ✓ it locked dispatch preserves ai_state.runner sub-key while writing…  0.06s
  Tests:    1 passed (5 assertions)
```
Covering tests also green: `vendor/bin/pest tests/Feature/Triage/TriageDispatcherTest.php tests/Feature/Triage/TriageActionsTest.php tests/Feature/Triage/TriageListenerTest.php` — 16 passed (27 assertions).

---

### FIX 2 — Monotonicity guard on `DispatchToRunnerJob::writeFailed` (Important)

**Problem:** `writeFailed()` only checked `task_id` then unconditionally set `status='failed'`. On queue-retry exhaustion this could regress a runner-advanced terminal (the runner started/completed while our HTTP layer perceived a transient failure), reverting `running` or `completed` back to `failed`.

**Fix:** `src/Jobs/DispatchToRunnerJob.php` — inside the locked closure in `writeFailed()`, after the existing `task_id` match check, added a guard:
```php
if (($fresh->ai_state['runner']['status'] ?? null) !== 'dispatched') {
    return; // the runner already advanced this task_id — do not regress
}
```

**Regression tests added to:** `tests/Feature/Runner/DispatchJobTest.php`  
Command: `vendor/bin/pest tests/Feature/Runner/DispatchJobTest.php`
```
PASS  Tests\Feature\Runner\DispatchJobTest
  ✓ it failed() does NOT overwrite status=running when the runner already advanced past dispatched
  ✓ it failed() does NOT overwrite status=completed when the runner already completed
  Tests:    8 passed (14 assertions)
```

---

### FIX 3 — Analysis comment BEFORE writePrRef; make writePrRef best-effort (Important)

**Problem:** `RunnerIngest::result()` called `writePrRef()` (which acquires the `feedback-board:github:{id}` lock, `->block(8)`) BEFORE creating the analysis comment. A contended github lock (>8s) threw `LockTimeoutException` → 503 → runner retries → `analysis_posted` already committed → comment permanently lost.

**Fix:** `src/Runner/Support/RunnerIngest.php`:
- (a) Reordered: `ReplyToFeedback::handle(...)` (analysis comment creation) now runs BEFORE `writePrRef()`.
- (b) Wrapped `writePrRef`'s `->block(8, ...)` in `try { ... } catch (\Illuminate\Contracts\Cache\LockTimeoutException) { /* best-effort */ }` — a contended github lock never 503s the request.

**Regression test added to:** `tests/Feature/Runner/IngestRunnerResultTest.php`  
Command: `vendor/bin/pest tests/Feature/Runner/IngestRunnerResultTest.php`
```
PASS  Tests\Feature\Runner\IngestRunnerResultTest
  ✓ it returns 200 and posts the analysis comment even when the github lock is held…  7.87s
  Tests:    8 passed (44 assertions)
```

---

### FIX 4 — Correct published config reference drift in docs (Important)

**Problem:** `docs/runner-contract.md` §8 and `README.md` runner section drifted from `config/feedback-board.php`:
- Enable var was `FEEDBACK_RUNNER_ENABLED` (not `FEEDBACK_BOARD_RUNNER_ENABLED`) — following the doc verbatim left the feature silently disabled.
- Spurious `runner.queue` / `FEEDBACK_RUNNER_QUEUE` row (no such config key).
- Spurious `runner.timeout` / `FEEDBACK_RUNNER_TIMEOUT` row (timeout hardcoded to `8`, no env binding).
- Timeout default documented as `10` (actual: `8`).
- `item.status` value `"open"` in worked examples — `"open"` is not a valid `FeedbackStatus` value.

**Fix:**
- `docs/runner-contract.md` §8: replaced `FEEDBACK_RUNNER_ENABLED` → `FEEDBACK_BOARD_RUNNER_ENABLED`; removed `runner.queue` and `runner.timeout` rows; added `runner.callback_url` / `FEEDBACK_RUNNER_CALLBACK_URL` row (which IS in config).
- `docs/runner-contract.md` §2 example: `"status": "open"` → `"status": "in_progress"`.
- `docs/runner-contract.md` §7 example: `"status": "open"` → `"status": "new"`.
- `README.md` runner table: corrected env var column; removed `runner.queue` row; added `runner.callback_url` row.

**DocsTest:** `vendor/bin/pest tests/Feature/Runner/DocsTest.php` — 1 passed (8 assertions). All four required literal strings remain.

---

### Full Suite Final Result

Command: `vendor/bin/pest`
```
Tests:    198 passed (538 assertions)
Duration: 21.80s
```

Prior count: 194. New total: 198 (+4 new regression tests). All green.

---

### Concerns

None. The FIX 3 contended-lock regression test legitimately takes ~7.9s (the array lock actually blocks for the full 8-second budget, then catches `LockTimeoutException`). All other tests run at their normal pace.

---

## Test-hardening pass

**Date:** 2026-06-30
**Branch:** feature/ai-runner-contract-level-bc

### FIX-1 — Discriminating lock test (manual re-triage path MUST acquire lock)

**Problem with old test:** The existing merge test proved that `ai_state.runner` was preserved after `dispatch($item, locked: true)`. But this was non-discriminating: the OLD unlocked code also did a fresh-read single-sub-key merge, so the test would have passed against it too. No test proved the manual path actually ACQUIRES the per-item lock.

**New test added:** `it('locked dispatch throws LockTimeoutException when the per-item lock is held')`

The test holds the `feedback-board:ai_state:{id}` lock from outside, then calls `TriageDispatcher::dispatch($item, locked: true)` and asserts it throws `LockTimeoutException`. Key details:

```php
it('locked dispatch throws LockTimeoutException when the per-item lock is held', function () {
    Sleep::fake(syncWithCarbon: true); // each 250ms fake sleep advances now() → 8s budget exhausts in ~32 tight iterations, not 8s real time
    Bus::fake();
    $item = triageLockItem();
    $originalAiState = $item->ai_state;
    $lock = Cache::store('array')->lock('feedback-board:ai_state:'.$item->id, 10);
    $held = $lock->get();
    expect($held)->toBeTrue();

    // DISCRIMINATING: old unlocked code had no lock → would write and NOT throw.
    // New locked path calls block(8) → lock is held → throws LockTimeoutException.
    expect(fn () => TriageDispatcher::dispatch($item, locked: true))
        ->toThrow(LockTimeoutException::class);

    expect($item->refresh()->ai_state)->toBe($originalAiState); // write never ran
    Bus::assertNotDispatched(TriageFeedbackItem::class);
    $lock->forceRelease();
});
```

**Complementary test added:** `it('unlocked dispatch does NOT throw even when the per-item lock is held')` — proves the listener/creation path is genuinely lock-free; calling `dispatch($item)` (unlocked) while the lock is held succeeds and writes triage pending.

**Why `Sleep::fake(syncWithCarbon: true)` and not just `Sleep::fake()`:** `Lock::block()` measures elapsed time via `now()->format('Uu')`. With plain `Sleep::fake()`, `Sleep::usleep()` is a no-op but `now()` returns real wall-clock time, so the loop still spins for 8 real seconds. `syncWithCarbon: true` makes each faked `Sleep::usleep(250ms)` advance Carbon's test clock by 250ms — so the timeout logic fires after ~32 tight iterations (250ms × 32 = 8s virtual) with no real sleep.

### FIX-3 — Sleep::fake(syncWithCarbon: true) eliminates real 8s wait

**File:** `tests/Feature/Runner/IngestRunnerResultTest.php`

Added `\Illuminate\Support\Sleep::fake(syncWithCarbon: true);` at the top of the `'returns 200 and posts the analysis comment even when the github lock is held by a concurrent writer'` test. The `writePrRef` method's `->block(8, ...)` call now exhausts its budget in ~32 tight iterations instead of 8 real seconds.

**Note:** Added to the specific test only (not `beforeEach`) to avoid interfering with `$this->travel(2)->seconds()` in the de-dupe test.

### Test commands + timings

```
vendor/bin/pest tests/Feature/Runner/TriageDispatcherLockTest.php tests/Feature/Runner/IngestRunnerResultTest.php --no-progress
```

```
PASS  Tests\Feature\Runner\TriageDispatcherLockTest
  ✓ it locked dispatch throws LockTimeoutException when the per-item lo… 0.07s
  ✓ it unlocked dispatch does NOT throw even when the per-item lock is…  0.05s
  ✓ it locked dispatch preserves ai_state.runner sub-key while writing…  0.05s

PASS  Tests\Feature\Runner\IngestRunnerResultTest
  ✓ it posts the analysis as an INTERNAL AI comment and never leaks it   0.07s
  ✓ it does not duplicate the analysis comment on a re-post              0.07s
  ✓ it stores the diff under the cap and advances a non-terminal status… 0.06s
  ✓ it accepts a valid https pr_url and advances to completed            0.06s
  ✓ it rejects dangerous pr_urls and stores nothing                      0.10s
  ✓ it rejects a result whose task_id mismatches                         0.06s
  ✓ it returns 200 and posts the analysis comment even when the github…  0.08s
  ✓ it heals a dispatch-failed item but rejects a runner-failed one      0.07s

Tests:    11 passed (55 assertions)
Duration: 1.17s
```

FIX-3 test: 0.08s (was 7.87s). FIX-1 discriminating test: new, 0.07s.

### Full suite result

```
vendor/bin/pest
Tests:    200 passed (544 assertions)
Duration: 13.27s
```

Prior total: 198 tests / 21.80s. New total: 200 tests (+2) / 13.27s (~8.5s saved from eliminating real lock waits).

### Concerns

None. All production `src/` files are unchanged. Only test files modified.
