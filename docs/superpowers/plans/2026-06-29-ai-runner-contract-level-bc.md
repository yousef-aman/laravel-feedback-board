# AI Runner Contract (Level B/C) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an opt-in, manual, in-app contract that dispatches a feedback item to the developer's own external coding-agent runner and ingests its Level B/C results (analysis / diff / PR link) as developer-reviewed suggestions — the package never runs the agent and never merges.

**Architecture:** A dedicated `runner` channel (mirrors the `Triage`/`Destinations` layers) signs a curated task envelope with HMAC and POSTs it to a configured endpoint; the runner posts results back through the existing signed `ingest` endpoint. All runner state lives in `ai_state.runner` (no new table), guarded by the existing per-item `ai_state` lock with a per-dispatch `task_id` for correlation and monotonic, idempotent updates.

**Tech Stack:** PHP 8.2+ (runs on 8.4), Laravel 11/12, Livewire 3, optional Filament v4, Pest 3 + Orchestra Testbench. Reuses `SignsPayloads`, `TransientRetry`, `VerifyIngestSignature`, `FeedbackItemPolicy`, `ReplyToFeedback`.

**Spec:** `docs/superpowers/specs/2026-06-29-ai-runner-contract-level-bc-design.md` (read it for rationale; this plan is self-contained for implementation).

## Global Constraints

- `declare(strict_types=1);` at the top of every `src/` file.
- The package **never** runs the agent, writes to a repo, or merges. Level C stores a PR *link* only.
- **Manual dispatch only** (no auto-trigger). Runner results are **suggestions** (never auto-applied beyond server-authored `ai_state.runner` + `external_refs.pr_url`).
- `ai_state` and `external_refs` are **server-authored** (already outside `$fillable`); write only via explicit assignment of a single sub-key.
- **No secret leaves env or is logged.** **Structural data minimization:** the envelope is a curated whitelist (no `reporter_*`/assignee/`meta`/attachments/comment-thread); `title`/`description`/`triage.summary` are sent verbatim.
- **Untrusted runner output rendered escaped** (`{{ }}` only — never `{!! !!}`/`->html()`/`->markdown()`). The runner **analysis comment MUST be `is_internal=true`**.
- **Sanitized errors only** — closed `RunnerError` vocabulary; runner-supplied `error_code` constrained to `^[a-z0-9_]{1,40}$`.
- **Hard authorization in action bodies** via static `FeedbackItemPolicy::isDeveloper(?Model $actor)` (never UI-only hiding). Action handlers take `?Model $actor`; call sites pass `auth()->user()`.
- Namespace mirrors Triage: `YousefAman\FeedbackBoard\Runner\…`.
- HMAC formula (from `SignsPayloads`): `hash_hmac('sha256', "{ts}.{rawBody}", $secret)` — sign the exact bytes sent.
- All `ai_state.runner` reads/writes go through the per-item lock `feedback-board:ai_state:{id}` (store `feedback-board.ai.lock_store`) with a fresh re-read; **synchronous** paths surface a `LockTimeoutException` as HTTP `503` (never swallow); the queued terminal write swallows it.
- Status rank: `dispatched=0, running=1, completed=2, failed=2`.

---

### Task 1: Config block + `RunnerGate`

**Files:**
- Modify: `config/feedback-board.php` (after the `ai` block)
- Create: `src/Runner/Support/RunnerGate.php`
- Test: `tests/Feature/Runner/ConfigTest.php`, `tests/Unit/RunnerGateTest.php`

**Interfaces:**
- Produces: `RunnerGate::configured(): bool`; config keys under `feedback-board.runner.*`.

- [ ] **Step 1: Write the failing gate test**

`tests/Unit/RunnerGateTest.php`:
```php
<?php

use YousefAman\FeedbackBoard\Runner\Support\RunnerGate;

function configureRunner(bool $enabled, ?string $endpoint, ?string $secret): void {
    config()->set('feedback-board.runner.enabled', $enabled);
    config()->set('feedback-board.runner.endpoint', $endpoint);
    config()->set('feedback-board.runner.secret', $secret);
}

it('is configured only when enabled + endpoint + secret are all present', function () {
    configureRunner(true, 'https://runner.test/task', 'shh');
    expect(RunnerGate::configured())->toBeTrue();
});

it('is not configured when disabled', function () {
    configureRunner(false, 'https://runner.test/task', 'shh');
    expect(RunnerGate::configured())->toBeFalse();
});

it('is not configured when endpoint or secret is missing', function () {
    configureRunner(true, '', 'shh');
    expect(RunnerGate::configured())->toBeFalse();
    configureRunner(true, 'https://runner.test/task', '');
    expect(RunnerGate::configured())->toBeFalse();
    configureRunner(true, null, null);
    expect(RunnerGate::configured())->toBeFalse();
});

it('treats a truthy enabled flag (=1) as enabled, like TriageGate', function () {
    configureRunner(true, 'https://runner.test/task', 'shh');
    config()->set('feedback-board.runner.enabled', 1);
    expect(RunnerGate::configured())->toBeTrue();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Unit/RunnerGateTest.php`
Expected: FAIL — `Class "YousefAman\FeedbackBoard\Runner\Support\RunnerGate" not found`.

- [ ] **Step 3: Add the config block**

In `config/feedback-board.php`, immediately after the `ai` array's closing `],` (line ~91), add:
```php
    'runner' => [
        // Opt-in. With this false — or endpoint/secret missing — the manual
        // "Send to agent" action returns 422 and the UI panel is hidden.
        'enabled' => env('FEEDBACK_BOARD_RUNNER_ENABLED', false),

        'endpoint' => env('FEEDBACK_RUNNER_ENDPOINT'),   // runner task-receive URL
        'secret' => env('FEEDBACK_RUNNER_SECRET'),       // HMAC secret for the outbound task

        'timeout' => 8,
        'connect_timeout' => 4,

        'max_analysis_chars' => 10000,   // cap on the analysis comment body
        'max_diff_chars' => 20000,       // cap on the stored suggested diff

        // Optional explicit ingest URL placed in the envelope's callback. If null,
        // the envelope omits it (the runner is pre-configured with its ingest URL).
        'callback_url' => env('FEEDBACK_RUNNER_CALLBACK_URL'),

        // UI-only "may be stale" hint after this many seconds. No background reaper.
        'stale_after' => 1800,

        // NOTE: all ai_state.runner writes reuse feedback-board.ai.lock_store, which
        // MUST be a LockProvider (redis|memcached|dynamodb|database|array) whenever
        // runner is enabled — a `file` store THROWS on lock(). The first runner write
        // happens synchronously inside the dispatch action.
    ],
```

- [ ] **Step 4: Create `RunnerGate`**

`src/Runner/Support/RunnerGate.php`:
```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Runner\Support;

final class RunnerGate
{
    public static function configured(): bool
    {
        if (! config('feedback-board.runner.enabled')) {
            return false;
        }

        $endpoint = config('feedback-board.runner.endpoint');
        $secret = config('feedback-board.runner.secret');

        return is_string($endpoint) && $endpoint !== ''
            && is_string($secret) && $secret !== '';
    }
}
```

- [ ] **Step 5: Add the config defaults test**

`tests/Feature/Runner/ConfigTest.php`:
```php
<?php

it('ships a disabled runner block with sane defaults', function () {
    expect(config('feedback-board.runner.enabled'))->toBeFalse()
        ->and(config('feedback-board.runner.timeout'))->toBe(8)
        ->and(config('feedback-board.runner.max_analysis_chars'))->toBe(10000)
        ->and(config('feedback-board.runner.max_diff_chars'))->toBe(20000)
        ->and(config('feedback-board.runner.stale_after'))->toBe(1800);
});
```

- [ ] **Step 6: Run both tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/RunnerGateTest.php tests/Feature/Runner/ConfigTest.php`
Expected: PASS (all green).

- [ ] **Step 7: Commit**

```bash
git add config/feedback-board.php src/Runner/Support/RunnerGate.php tests/Unit/RunnerGateTest.php tests/Feature/Runner/ConfigTest.php
git commit -m "feat(runner): config block + RunnerGate"
```

---

### Task 2: `RunnerException` + `RunnerError`

**Files:**
- Create: `src/Runner/Exceptions/RunnerException.php`, `src/Runner/Support/RunnerError.php`
- Test: `tests/Unit/RunnerErrorTest.php`

**Interfaces:**
- Produces: `RunnerException(public readonly string $errorCode)`; `RunnerError::code(\Throwable): string` from the closed vocabulary `connection_error|http_5xx|http_429|auth_401|not_found_404|validation_422|not_configured|unknown`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/RunnerErrorTest.php`:
```php
<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use YousefAman\FeedbackBoard\Runner\Exceptions\RunnerException;
use YousefAman\FeedbackBoard\Runner\Support\RunnerError;

function requestExceptionWith(int $status): RequestException {
    return new RequestException(new Response(
        new \GuzzleHttp\Psr7\Response($status, [], 'body')
    ));
}

it('passes a RunnerException through by its errorCode', function () {
    expect(RunnerError::code(new RunnerException('not_configured')))->toBe('not_configured');
});

it('maps a connection error', function () {
    expect(RunnerError::code(new ConnectionException('boom')))->toBe('connection_error');
});

it('maps HTTP statuses to the closed vocabulary', function () {
    expect(RunnerError::code(requestExceptionWith(401)))->toBe('auth_401')
        ->and(RunnerError::code(requestExceptionWith(403)))->toBe('auth_401')   // the non-obvious collapse
        ->and(RunnerError::code(requestExceptionWith(404)))->toBe('not_found_404')
        ->and(RunnerError::code(requestExceptionWith(422)))->toBe('validation_422')
        ->and(RunnerError::code(requestExceptionWith(429)))->toBe('http_429')
        ->and(RunnerError::code(requestExceptionWith(503)))->toBe('http_5xx');
});

it('defaults unknown for anything else', function () {
    expect(RunnerError::code(new RuntimeException('x')))->toBe('unknown');
});

it('never leaks the original message (no $previous on RunnerException)', function () {
    $e = new RunnerException('auth_401');
    expect((string) $e)->not->toContain('secret')
        ->and($e->getPrevious())->toBeNull();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Unit/RunnerErrorTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Create `RunnerException`**

`src/Runner/Exceptions/RunnerException.php`:
```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Runner\Exceptions;

use RuntimeException;

/**
 * Carries ONLY a sanitized code from the closed vocabulary. Constructed with no
 * previous exception so `(string) $e` cannot walk back to a message/headers/body
 * that embed the HMAC secret or the runner endpoint.
 */
final class RunnerException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
```

- [ ] **Step 4: Create `RunnerError`**

`src/Runner/Support/RunnerError.php`:
```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Runner\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;
use YousefAman\FeedbackBoard\Runner\Exceptions\RunnerException;

final class RunnerError
{
    /** Map any throwable to a fixed, secret-free vocabulary. Default: 'unknown'. */
    public static function code(Throwable $e): string
    {
        if ($e instanceof RunnerException) {
            return $e->errorCode;
        }

        if ($e instanceof ConnectionException) {
            return 'connection_error';
        }

        if ($e instanceof RequestException) {
            $status = $e->response?->status() ?? 0;

            return match (true) {
                $status === 401 || $status === 403 => 'auth_401',
                $status === 404 => 'not_found_404',
                $status === 422 => 'validation_422',
                $status === 429 => 'http_429',
                $status >= 500 => 'http_5xx',
                default => 'unknown',
            };
        }

        return 'unknown';
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Unit/RunnerErrorTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Runner/Exceptions/RunnerException.php src/Runner/Support/RunnerError.php tests/Unit/RunnerErrorTest.php
git commit -m "feat(runner): sanitized RunnerException + RunnerError vocabulary"
```

---

### Task 3: `RunnerTaskPayload`

**Files:**
- Create: `src/Runner/Support/RunnerTaskPayload.php`
- Test: `tests/Unit/RunnerTaskPayloadTest.php`

**Interfaces:**
- Produces: `RunnerTaskPayload::build(FeedbackItem $item, string $taskId, ?string $callbackUrl): array`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/RunnerTaskPayloadTest.php`:
```php
<?php

use YousefAman\FeedbackBoard\Enums\{FeedbackPriority, FeedbackStatus, FeedbackType};
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Runner\Support\RunnerTaskPayload;

it('builds a whitelist envelope with no reporter PII', function () {
    $item = FeedbackItem::factory()->create([
        'title' => 'Crash on save',
        'description' => 'Steps...',
        'type' => FeedbackType::Bug,
        'status' => FeedbackStatus::New,
        'priority' => FeedbackPriority::High,
        'reporter_name' => 'Secret Reporter',          // non-null so the assertion is meaningful
        'reporter_email' => 'secret@example.com',
    ]);

    $env = RunnerTaskPayload::build($item, 'task-123', 'https://app.test/feedback-board/ingest');

    expect($env['task_id'])->toBe('task-123')
        ->and($env['event'])->toBe('runner.task')
        ->and($env['item']['id'])->toBe($item->id)
        ->and($env['item']['title'])->toBe('Crash on save')
        ->and($env['item']['type'])->toBe('bug')
        ->and($env['callback'])->toBe(['ingest_url' => 'https://app.test/feedback-board/ingest', 'item_id' => $item->id, 'task_id' => 'task-123']);

    $flat = json_encode($env);
    expect($flat)->not->toContain('Secret Reporter')
        ->and($flat)->not->toContain('secret@example.com')
        ->and($flat)->not->toContain('reporter_');
});

it('includes the triage suggestion only when triage is completed', function () {
    $item = FeedbackItem::factory()->create();
    expect(RunnerTaskPayload::build($item, 't', null))->not->toHaveKey('triage');

    $item->ai_state = ['triage' => ['status' => 'completed', 'suggested_type' => 'bug', 'suggested_priority' => 'high', 'summary' => 'S']];
    $item->save();
    $env = RunnerTaskPayload::build($item->refresh(), 't', null);
    expect($env['triage'])->toBe(['suggested_type' => 'bug', 'suggested_priority' => 'high', 'summary' => 'S']);
});

it('omits callback.ingest_url when no callback_url is given', function () {
    $item = FeedbackItem::factory()->create();
    $env = RunnerTaskPayload::build($item, 't', null);
    expect($env['callback'])->toBe(['item_id' => $item->id, 'task_id' => 't']);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Unit/RunnerTaskPayloadTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Create `RunnerTaskPayload`**

`src/Runner/Support/RunnerTaskPayload.php`:
```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Runner\Support;

use YousefAman\FeedbackBoard\Models\FeedbackItem;

final class RunnerTaskPayload
{
    /**
     * Curated whitelist — the raw model is never serialized. Unlike WebhookPayload,
     * this excludes ALL reporter_* fields (a code runner has no need for reporter PII).
     * title/description/triage.summary are reporter-authored free text sent verbatim.
     *
     * @return array<string, mixed>
     */
    public static function build(FeedbackItem $item, string $taskId, ?string $callbackUrl): array
    {
        $env = [
            'task_id' => $taskId,
            'event' => 'runner.task',
            'item' => [
                'id' => $item->id,
                'type' => $item->type?->value,
                'status' => $item->status?->value,
                'priority' => $item->priority?->value,
                'title' => $item->title,
                'description' => $item->description,
                'project' => $item->project,
                'created_at' => $item->created_at?->toIso8601String(),
            ],
        ];

        $triage = $item->ai_state['triage'] ?? null;
        if (is_array($triage) && ($triage['status'] ?? null) === 'completed') {
            $env['triage'] = [
                'suggested_type' => $triage['suggested_type'] ?? null,
                'suggested_priority' => $triage['suggested_priority'] ?? null,
                'summary' => $triage['summary'] ?? null,
            ];
        }

        $callback = ['item_id' => $item->id, 'task_id' => $taskId];
        if (is_string($callbackUrl) && $callbackUrl !== '') {
            $callback = ['ingest_url' => $callbackUrl] + $callback;
        }
        $env['callback'] = $callback;

        return $env;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Unit/RunnerTaskPayloadTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Runner/Support/RunnerTaskPayload.php tests/Unit/RunnerTaskPayloadTest.php
git commit -m "feat(runner): curated RunnerTaskPayload envelope"
```

---

### Task 4: `RunnerState` lock helper

**Files:**
- Create: `src/Runner/Support/RunnerState.php`
- Test: `tests/Feature/Runner/RunnerStateTest.php`

**Interfaces:**
- Produces:
  - `RunnerState::lock(int $id, Closure $fn, bool $swallowLockTimeout = false): void` — runs `$fn(FeedbackItem $fresh)` inside the per-item `ai_state` lock with a fresh re-read; no-op if the item is gone; rethrows `LockTimeoutException` unless `$swallowLockTimeout`.
  - `RunnerState::merge(FeedbackItem $item, array $patch): void` — merges `$patch` into `ai_state.runner` (+ `updated_at`) and saves only the `ai_state` column.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Runner/RunnerStateTest.php`:
```php
<?php

use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Runner\Support\RunnerState;

beforeEach(fn () => config()->set('feedback-board.ai.lock_store', 'array'));

it('merges into ai_state.runner under the lock without touching triage or external_refs', function () {
    $item = FeedbackItem::factory()->create();
    $item->ai_state = ['triage' => ['status' => 'completed']];
    $item->external_refs = ['github' => ['issue_number' => 7]];
    $item->save();

    RunnerState::lock($item->id, fn (FeedbackItem $fresh) => RunnerState::merge($fresh, ['status' => 'dispatched', 'task_id' => 'abc']));

    $fresh = $item->refresh();
    expect($fresh->ai_state['runner']['status'])->toBe('dispatched')
        ->and($fresh->ai_state['runner']['task_id'])->toBe('abc')
        ->and($fresh->ai_state['runner']['updated_at'])->not->toBeNull()
        ->and($fresh->ai_state['triage']['status'])->toBe('completed')   // untouched
        ->and($fresh->external_refs['github']['issue_number'])->toBe(7); // untouched
});

it('is a no-op when the item is gone', function () {
    RunnerState::lock(999999, fn (FeedbackItem $fresh) => RunnerState::merge($fresh, ['status' => 'x']));
    expect(true)->toBeTrue(); // no exception
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Feature/Runner/RunnerStateTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Create `RunnerState`**

`src/Runner/Support/RunnerState.php`:
```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Runner\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

final class RunnerState
{
    /**
     * Run $fn(FeedbackItem $fresh) inside the per-item ai_state lock with a fresh
     * re-read. No-op if the row is gone. On a synchronous path a LockTimeoutException
     * bubbles (the caller converts it to 503); the queued terminal write passes
     * $swallowLockTimeout = true.
     */
    public static function lock(int $id, Closure $fn, bool $swallowLockTimeout = false): void
    {
        $store = config('feedback-board.ai.lock_store');

        try {
            Cache::store($store)->lock('feedback-board:ai_state:'.$id, 10)->block(8, function () use ($id, $fn) {
                $fresh = FeedbackItem::find($id);
                if ($fresh === null) {
                    return;
                }
                $fn($fresh);
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            if (! $swallowLockTimeout) {
                throw $e;
            }
        }
    }

    /**
     * Merge $patch into ai_state.runner (+ updated_at) and save. Only the ai_state
     * column is dirtied, so a concurrent external_refs write is never clobbered.
     *
     * @param array<string, mixed> $patch
     */
    public static function merge(FeedbackItem $item, array $patch): void
    {
        $ai = $item->ai_state ?? [];
        $runner = $ai['runner'] ?? [];
        $ai['runner'] = array_merge($runner, $patch, ['updated_at' => now()->toIso8601String()]);
        $item->ai_state = $ai;
        $item->save();
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Runner/RunnerStateTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Runner/Support/RunnerState.php tests/Feature/Runner/RunnerStateTest.php
git commit -m "feat(runner): RunnerState per-item lock + merge helper"
```

---

### Task 5: `RunnerDispatcher` (signed sender)

**Files:**
- Create: `src/Runner/RunnerDispatcher.php`
- Test: `tests/Feature/Runner/DispatcherTest.php`

**Interfaces:**
- Consumes: `RunnerTaskPayload::build`, `RunnerException`, `RunnerError`, `SignsPayloads`, `TransientRetry`.
- Produces: `RunnerDispatcher::send(FeedbackItem $item, string $taskId): void` (throws `RunnerException` on failure).

- [ ] **Step 1: Write the failing test**

`tests/Feature/Runner/DispatcherTest.php`:
```php
<?php

use Illuminate\Support\Facades\Http;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Runner\Exceptions\RunnerException;
use YousefAman\FeedbackBoard\Runner\RunnerDispatcher;

beforeEach(function () {
    config()->set('feedback-board.runner.endpoint', 'https://runner.test/task');
    config()->set('feedback-board.runner.secret', 'rsecret');
    config()->set('feedback-board.runner.callback_url', 'https://app.test/feedback-board/ingest');
});

it('signs and POSTs the envelope with the runner headers', function () {
    Http::fake(['https://runner.test/*' => Http::response(['ok' => true], 200)]);
    $item = FeedbackItem::factory()->create(['title' => 'T', 'description' => 'D']);

    (new RunnerDispatcher())->send($item, 'task-9');

    Http::assertSent(function ($request) {
        $ts = $request->header('X-Feedback-Timestamp')[0];
        $expected = hash_hmac('sha256', $ts.'.'.$request->body(), 'rsecret');
        return $request->url() === 'https://runner.test/task'
            && $request->header('X-Feedback-Signature')[0] === $expected
            && $request->header('X-Feedback-Event')[0] === 'runner.task'
            && $request->header('X-Feedback-Delivery')[0] === 'task-9'
            && str_contains($request->body(), '"task_id":"task-9"');
    });
});

it('throws a sanitized RunnerException on a permanent 4xx', function () {
    Http::fake(['https://runner.test/*' => Http::response('nope', 404)]);
    $item = FeedbackItem::factory()->create();

    expect(fn () => (new RunnerDispatcher())->send($item, 't'))
        ->toThrow(RunnerException::class, 'not_found_404');
});

it('throws not_configured when endpoint/secret are missing', function () {
    config()->set('feedback-board.runner.endpoint', '');
    $item = FeedbackItem::factory()->create();

    expect(fn () => (new RunnerDispatcher())->send($item, 't'))
        ->toThrow(RunnerException::class, 'not_configured');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Feature/Runner/DispatcherTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Create `RunnerDispatcher`**

`src/Runner/RunnerDispatcher.php`:
```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Runner;

use Illuminate\Support\Facades\Http;
use Throwable;
use YousefAman\FeedbackBoard\Destinations\Support\SignsPayloads;
use YousefAman\FeedbackBoard\Destinations\Support\TransientRetry;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Runner\Exceptions\RunnerException;
use YousefAman\FeedbackBoard\Runner\Support\RunnerError;
use YousefAman\FeedbackBoard\Runner\Support\RunnerTaskPayload;

class RunnerDispatcher
{
    use SignsPayloads;

    public function send(FeedbackItem $item, string $taskId): void
    {
        $endpoint = config('feedback-board.runner.endpoint');
        $secret = config('feedback-board.runner.secret');
        if (! is_string($endpoint) || $endpoint === '' || ! is_string($secret) || $secret === '') {
            throw new RunnerException('not_configured');
        }

        $callbackUrl = config('feedback-board.runner.callback_url');
        // json_encode ONCE; sign those exact bytes; send the same bytes via withBody.
        $raw = json_encode(
            RunnerTaskPayload::build($item, $taskId, is_string($callbackUrl) ? $callbackUrl : null),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $ts = (string) now()->timestamp;
        $sig = $this->sign($ts, $raw, $secret);

        try {
            Http::timeout((int) config('feedback-board.runner.timeout', 8))
                ->connectTimeout((int) config('feedback-board.runner.connect_timeout', 4))
                ->retry(2, 200, TransientRetry::when(), throw: false)
                ->withHeaders([
                    'X-Feedback-Timestamp' => $ts,
                    'X-Feedback-Signature' => $sig,
                    'X-Feedback-Event' => 'runner.task',
                    'X-Feedback-Delivery' => $taskId,
                ])
                ->withBody($raw, 'application/json')
                ->post($endpoint)
                ->throw();
        } catch (RunnerException $e) {
            throw $e;
        } catch (Throwable $e) {
            // Sanitize: no $previous, so (string) cannot leak the secret/body/endpoint.
            throw new RunnerException(RunnerError::code($e));
        }
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Runner/DispatcherTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Runner/RunnerDispatcher.php tests/Feature/Runner/DispatcherTest.php
git commit -m "feat(runner): signed RunnerDispatcher with sanitized failures"
```

---

### Task 6: `DispatchToRunnerJob` (queued, after-commit)

**Files:**
- Create: `src/Jobs/DispatchToRunnerJob.php`
- Test: `tests/Feature/Runner/DispatchJobTest.php`

**Interfaces:**
- Consumes: `RunnerDispatcher`, `RunnerException`, `RunnerError`, `RunnerState`.
- Produces: `new DispatchToRunnerJob(int $itemId, string $taskId)`; `handle(RunnerDispatcher $dispatcher): void`; `failed(\Throwable $e): void`. (The action in Task 7 dispatches this job.)

- [ ] **Step 1: Write the failing test**

`tests/Feature/Runner/DispatchJobTest.php`:
```php
<?php

use Illuminate\Support\Facades\Http;
use YousefAman\FeedbackBoard\Jobs\DispatchToRunnerJob;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Runner\Exceptions\RunnerException;

beforeEach(function () {
    config()->set('feedback-board.ai.lock_store', 'array');
    config()->set('feedback-board.runner.endpoint', 'https://runner.test/task');
    config()->set('feedback-board.runner.secret', 'rsecret');
});

function dispatchedItem(): FeedbackItem {
    $item = FeedbackItem::factory()->create();
    $item->ai_state = ['runner' => ['status' => 'dispatched', 'task_id' => 'live']];
    $item->save();
    return $item;
}

it('sends the signed envelope and leaves status dispatched on success', function () {
    Http::fake(['https://runner.test/*' => Http::response(['ok' => true], 200)]);
    $item = dispatchedItem();

    (new DispatchToRunnerJob($item->id, 'live'))->handle(app(\YousefAman\FeedbackBoard\Runner\RunnerDispatcher::class));

    Http::assertSent(fn ($r) => $r->header('X-Feedback-Delivery')[0] === 'live');
    expect($item->refresh()->ai_state['runner']['status'])->toBe('dispatched');
});

it('does NOT send when the task_id was superseded (stale dispatch)', function () {
    Http::fake();
    $item = dispatchedItem(); // task_id = 'live'

    (new DispatchToRunnerJob($item->id, 'OLD'))->handle(app(\YousefAman\FeedbackBoard\Runner\RunnerDispatcher::class));

    Http::assertNothingSent();
});

it('writes terminal failed with a sanitized code on a permanent error', function () {
    Http::fake(['https://runner.test/*' => Http::response('no', 404)]);
    $item = dispatchedItem();

    (new DispatchToRunnerJob($item->id, 'live'))->handle(app(\YousefAman\FeedbackBoard\Runner\RunnerDispatcher::class));

    $runner = $item->refresh()->ai_state['runner'];
    expect($runner['status'])->toBe('failed')
        ->and($runner['failed_origin'])->toBe('dispatch')
        ->and($runner['error_code'])->toBe('not_found_404');
});

it('rethrows a transient error so the queue retries', function () {
    Http::fake(['https://runner.test/*' => Http::response('boom', 503)]);
    $item = dispatchedItem();

    expect(fn () => (new DispatchToRunnerJob($item->id, 'live'))->handle(app(\YousefAman\FeedbackBoard\Runner\RunnerDispatcher::class)))
        ->toThrow(RunnerException::class, 'http_5xx');
    expect($item->refresh()->ai_state['runner']['status'])->toBe('dispatched'); // not terminal yet
});

it('failed() writes the terminal failed state', function () {
    $item = dispatchedItem();

    (new DispatchToRunnerJob($item->id, 'live'))->failed(new RunnerException('http_5xx'));

    expect($item->refresh()->ai_state['runner']['status'])->toBe('failed');
});

it('sets afterCommit on the job', function () {
    expect((new DispatchToRunnerJob(1, 't'))->afterCommit)->toBeTrue();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Feature/Runner/DispatchJobTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Create `DispatchToRunnerJob`**

`src/Jobs/DispatchToRunnerJob.php`:
```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Runner\Exceptions\RunnerException;
use YousefAman\FeedbackBoard\Runner\RunnerDispatcher;
use YousefAman\FeedbackBoard\Runner\Support\RunnerError;
use YousefAman\FeedbackBoard\Runner\Support\RunnerState;

class DispatchToRunnerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $itemId, public string $taskId)
    {
        // Never POST a task for an uncommitted/rolled-back dispatch write.
        $this->afterCommit = true;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(RunnerDispatcher $dispatcher): void
    {
        $item = FeedbackItem::find($this->itemId);
        if ($item === null) {
            return; // deleted between enqueue and processing
        }

        // Best-effort, unlocked stale-dispatch guard: a newer dispatch superseded us.
        if (($item->ai_state['runner']['task_id'] ?? null) !== $this->taskId) {
            return;
        }

        try {
            $dispatcher->send($item, $this->taskId); // leaves status 'dispatched'; runner advances it
        } catch (RunnerException $e) {
            if (in_array($e->errorCode, ['http_429', 'http_5xx', 'connection_error'], true)) {
                throw $e; // transient → retry; failed() writes terminal on exhaustion
            }
            $this->writeFailed($e->errorCode);
        } catch (Throwable $e) {
            $this->writeFailed(RunnerError::code($e));
        }
    }

    public function failed(Throwable $e): void
    {
        $this->writeFailed(RunnerError::code($e));
    }

    /** Terminal dispatch-side failure; only if our task_id is still live; lock swallowed. */
    private function writeFailed(string $code): void
    {
        RunnerState::lock($this->itemId, function (FeedbackItem $fresh) use ($code) {
            if (($fresh->ai_state['runner']['task_id'] ?? null) !== $this->taskId) {
                return; // a newer dispatch owns the slot now
            }
            RunnerState::merge($fresh, [
                'status' => 'failed',
                'failed_origin' => 'dispatch',
                'error_code' => $code,
            ]);
        }, swallowLockTimeout: true);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Runner/DispatchJobTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Jobs/DispatchToRunnerJob.php tests/Feature/Runner/DispatchJobTest.php
git commit -m "feat(runner): DispatchToRunnerJob (after-commit, stale guard, terminal)"
```

---

### Task 7: `DispatchToRunner` action

**Files:**
- Create: `src/Actions/DispatchToRunner.php`
- Test: `tests/Feature/Runner/DispatchActionTest.php`

**Interfaces:**
- Consumes: `FeedbackItemPolicy::isDeveloper`, `RunnerGate`, `RunnerState`, `DispatchToRunnerJob` (Task 6, already exists).
- Produces: `DispatchToRunner::handle(FeedbackItem $item, ?Model $actor): void`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Runner/DispatchActionTest.php`:
```php
<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use YousefAman\FeedbackBoard\Actions\DispatchToRunner;
use YousefAman\FeedbackBoard\Jobs\DispatchToRunnerJob;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    config()->set('feedback-board.ai.lock_store', 'array');
    config()->set('feedback-board.runner.enabled', true);
    config()->set('feedback-board.runner.endpoint', 'https://runner.test/task');
    config()->set('feedback-board.runner.secret', 'rsecret');
    config()->set('feedback-board.ingest.enabled', true);
    config()->set('feedback-board.ingest.secret', 'ingsecret');
    config()->set('feedback-board.authorization.developer_resolver', fn ($u) => ($u->name ?? null) === 'Dev');
});

function rDeveloper(): TestUser { return TestUser::create(['name' => 'Dev']); }
function rRegular(): TestUser { return TestUser::create(['name' => 'User']); }

it('forbids non-developers and sends nothing', function () {
    Bus::fake(); Http::fake();
    $item = FeedbackItem::factory()->create();

    expect(fn () => (new DispatchToRunner())->handle($item, rRegular()))->toThrow(HttpException::class);

    Bus::assertNotDispatched(DispatchToRunnerJob::class);
    Http::assertNothingSent();
    expect($item->refresh()->ai_state['runner'] ?? null)->toBeNull();
});

it('422s when runner is not configured', function () {
    config()->set('feedback-board.runner.enabled', false);
    Bus::fake();
    $item = FeedbackItem::factory()->create();

    try { (new DispatchToRunner())->handle($item, rDeveloper()); $this->fail('expected 422'); }
    catch (HttpException $e) { expect($e->getStatusCode())->toBe(422); }
    Bus::assertNotDispatched(DispatchToRunnerJob::class);
});

it('422s when ingest is disabled (post-back could never land)', function () {
    config()->set('feedback-board.ingest.enabled', false);
    Bus::fake();
    $item = FeedbackItem::factory()->create();

    try { (new DispatchToRunner())->handle($item, rDeveloper()); $this->fail('expected 422'); }
    catch (HttpException $e) { expect($e->getStatusCode())->toBe(422); }
    Bus::assertNotDispatched(DispatchToRunnerJob::class);
    expect($item->refresh()->ai_state['runner'] ?? null)->toBeNull();
});

it('writes dispatched + dispatches the job for a configured developer', function () {
    Bus::fake();
    $item = FeedbackItem::factory()->create();

    (new DispatchToRunner())->handle($item, rDeveloper());

    $runner = $item->refresh()->ai_state['runner'];
    expect($runner['status'])->toBe('dispatched')
        ->and($runner['task_id'])->not->toBeNull()
        ->and($runner['analysis_posted'])->toBeFalse();
    Bus::assertDispatched(DispatchToRunnerJob::class);
});

it('is a silent no-op (no exception, no new job) while a dispatch is in flight', function () {
    Bus::fake();
    $item = FeedbackItem::factory()->create();
    $item->ai_state = ['runner' => ['status' => 'running', 'task_id' => 'live']];
    $item->save();

    (new DispatchToRunner())->handle($item, rDeveloper()); // must NOT throw

    expect($item->refresh()->ai_state['runner']['task_id'])->toBe('live'); // unchanged
    Bus::assertNotDispatched(DispatchToRunnerJob::class);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Feature/Runner/DispatchActionTest.php`
Expected: FAIL — `DispatchToRunner` / `DispatchToRunnerJob` not found.

- [ ] **Step 3: Create `DispatchToRunner`**

`src/Actions/DispatchToRunner.php`:
```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Actions;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use YousefAman\FeedbackBoard\Jobs\DispatchToRunnerJob;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy;
use YousefAman\FeedbackBoard\Runner\Support\RunnerGate;
use YousefAman\FeedbackBoard\Runner\Support\RunnerState;

class DispatchToRunner
{
    public function handle(FeedbackItem $item, ?Model $actor): void
    {
        abort_unless(FeedbackItemPolicy::isDeveloper($actor), 403);
        abort_unless(RunnerGate::configured(), 422);
        abort_unless((bool) config('feedback-board.ingest.enabled'), 422);

        $taskId = null;

        try {
            RunnerState::lock($item->id, function (FeedbackItem $fresh) use (&$taskId) {
                $runner = $fresh->ai_state['runner'] ?? [];
                if (in_array($runner['status'] ?? null, ['dispatched', 'running'], true)) {
                    return; // silent debounce — leaves $taskId null
                }
                $taskId = (string) Str::uuid();
                RunnerState::merge($fresh, [
                    'status' => 'dispatched',
                    'task_id' => $taskId,
                    'dispatched_at' => now()->toIso8601String(),
                    'model' => null,
                    'analysis_posted' => false,
                    'diff' => null,
                    'pr_url' => null,
                    'failed_origin' => null,
                    'error_code' => null,
                ]);
            });
        } catch (LockTimeoutException $e) {
            abort(503); // synchronous path: do not swallow — let the caller retry
        }

        if ($taskId === null) {
            return; // in-flight: silent no-op
        }

        DispatchToRunnerJob::dispatch($item->id, $taskId);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Runner/DispatchActionTest.php`
Expected: PASS (`DispatchToRunnerJob` already exists from Task 6).

- [ ] **Step 5: Commit**

```bash
git add src/Actions/DispatchToRunner.php tests/Feature/Runner/DispatchActionTest.php
git commit -m "feat(runner): DispatchToRunner action (auth + gate + debounce)"
```

---

### Task 8: Ingest `runner_status` (correlation + monotonicity)

**Files:**
- Create: `src/Runner/Support/RunnerIngest.php`
- Modify: `src/Http/Controllers/IngestController.php`
- Test: `tests/Feature/Runner/IngestRunnerStatusTest.php`

**Interfaces:**
- Produces: `RunnerIngest::status(FeedbackItem $item, array $data): void`; status `rank()` ordering; `failed_origin='runner'`.
- The controller validator gains `runner_status,runner_result` and two `match` arms delegating to `RunnerIngest`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Runner/IngestRunnerStatusTest.php`:
```php
<?php

use YousefAman\FeedbackBoard\Models\FeedbackItem;

function signedRunner(array $payload): array {
    $body = json_encode($payload);
    $ts = (string) now()->timestamp;
    $sig = hash_hmac('sha256', $ts.'.'.$body, 'shhh');
    return [$body, ['X-Feedback-Timestamp' => $ts, 'X-Feedback-Signature' => $sig, 'Content-Type' => 'application/json']];
}

function postRunner(array $payload) {
    [$body, $headers] = signedRunner($payload);
    return test()->call('POST', route('feedback-board.ingest'), [], [], [], test()->transformHeadersToServerVars($headers), $body);
}

function itemWithRunner(string $status, string $taskId, array $extra = []): FeedbackItem {
    $item = FeedbackItem::factory()->create();
    $item->ai_state = ['runner' => array_merge(['status' => $status, 'task_id' => $taskId], $extra)];
    $item->save();
    return $item;
}

beforeEach(function () {
    config()->set('feedback-board.ai.lock_store', 'array');
    config()->set('feedback-board.ingest.enabled', true);
    config()->set('feedback-board.ingest.secret', 'shhh');
});

it('advances dispatched -> running -> completed for a matching task_id', function () {
    $item = itemWithRunner('dispatched', 'T1');
    postRunner(['item_id' => $item->id, 'action' => 'runner_status', 'task_id' => 'T1', 'status' => 'running'])->assertOk();
    expect($item->refresh()->ai_state['runner']['status'])->toBe('running');
    postRunner(['item_id' => $item->id, 'action' => 'runner_status', 'task_id' => 'T1', 'status' => 'completed'])->assertOk();
    expect($item->refresh()->ai_state['runner']['status'])->toBe('completed');
});

it('rejects a post-back whose task_id does not match', function () {
    $item = itemWithRunner('dispatched', 'T1');
    postRunner(['item_id' => $item->id, 'action' => 'runner_status', 'task_id' => 'STALE', 'status' => 'running'])->assertStatus(409);
    expect($item->refresh()->ai_state['runner']['status'])->toBe('dispatched');
});

it('does not regress a terminal status (late running after completed is a no-op)', function () {
    $item = itemWithRunner('completed', 'T1');
    postRunner(['item_id' => $item->id, 'action' => 'runner_status', 'task_id' => 'T1', 'status' => 'running'])->assertOk();
    expect($item->refresh()->ai_state['runner']['status'])->toBe('completed');
});

it('does not flip completed to failed (equal rank no-op)', function () {
    $item = itemWithRunner('completed', 'T1');
    postRunner(['item_id' => $item->id, 'action' => 'runner_status', 'task_id' => 'T1', 'status' => 'failed'])->assertOk();
    expect($item->refresh()->ai_state['runner']['status'])->toBe('completed');
});

it('stores a runner-reported failed with origin and the safe slug', function () {
    $item = itemWithRunner('running', 'T1');
    postRunner(['item_id' => $item->id, 'action' => 'runner_status', 'task_id' => 'T1', 'status' => 'failed', 'error_code' => 'build_broke'])->assertOk();
    $runner = $item->refresh()->ai_state['runner'];
    expect($runner['status'])->toBe('failed')
        ->and($runner['failed_origin'])->toBe('runner')
        ->and($runner['error_code'])->toBe('build_broke');
});

it('defaults a missing failed error_code to unknown', function () {
    $item = itemWithRunner('running', 'T1');
    postRunner(['item_id' => $item->id, 'action' => 'runner_status', 'task_id' => 'T1', 'status' => 'failed'])->assertOk();
    expect($item->refresh()->ai_state['runner']['error_code'])->toBe('unknown');
});

it('still rejects an unsigned runner_status', function () {
    $item = itemWithRunner('dispatched', 'T1');
    test()->postJson(route('feedback-board.ingest'), ['item_id' => $item->id, 'action' => 'runner_status', 'task_id' => 'T1', 'status' => 'running'])
        ->assertStatus(403);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Feature/Runner/IngestRunnerStatusTest.php`
Expected: FAIL — the validator rejects `runner_status` (422) / `RunnerIngest` missing.

- [ ] **Step 3: Create `RunnerIngest` with `status()` + `rank()`**

`src/Runner/Support/RunnerIngest.php`:
```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Runner\Support;

use YousefAman\FeedbackBoard\Models\FeedbackItem;

final class RunnerIngest
{
    private const RANK = ['dispatched' => 0, 'running' => 1, 'completed' => 2, 'failed' => 2];

    public static function rank(?string $status): int
    {
        return self::RANK[$status] ?? -1;
    }

    /**
     * Apply a runner_status post-back under the per-item lock. Correlation (task_id)
     * and monotonicity are enforced inside the lock. Aborts 409 on a task_id mismatch.
     *
     * @param array<string, mixed> $data validated payload
     */
    public static function status(FeedbackItem $item, array $data): void
    {
        $taskId = (string) $data['task_id'];
        $posted = (string) $data['status'];
        $code = isset($data['error_code']) && $data['error_code'] !== '' ? (string) $data['error_code'] : 'unknown';

        $mismatch = false;
        RunnerState::lock($item->id, function (FeedbackItem $fresh) use ($taskId, $posted, $code, &$mismatch) {
            $runner = $fresh->ai_state['runner'] ?? [];
            if (($runner['task_id'] ?? null) !== $taskId) {
                $mismatch = true;
                return;
            }
            if (self::rank($posted) <= self::rank($runner['status'] ?? null)) {
                return; // idempotent / no regression
            }
            $patch = ['status' => $posted];
            if ($posted === 'failed') {
                $patch['failed_origin'] = 'runner';
                $patch['error_code'] = $code;
            }
            RunnerState::merge($fresh, $patch);
        });

        abort_if($mismatch, 409);
    }
}
```

- [ ] **Step 4: Wire the controller**

Branch the two runner actions into a dedicated private `runner()` method so the original
base validator stays **unchanged** (this avoids the `status` rule collision — the base
`status` rule's `in:new,in_review,…` list would otherwise reject the runner `status=running`).

In `src/Http/Controllers/IngestController.php`, add imports:
```php
use Illuminate\Contracts\Cache\LockTimeoutException;
use YousefAman\FeedbackBoard\Runner\Support\RunnerIngest;
```

Change `__invoke` to branch BEFORE the base validator, then add the `runner()` method.
The base validator and its three existing arms are **unchanged**:
```php
    public function __invoke(Request $request, TransitionStatus $transition, ReplyToFeedback $reply): JsonResponse
    {
        if (in_array($request->input('action'), ['runner_status', 'runner_result'], true)) {
            return $this->runner($request);
        }

        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            'action' => ['required', 'in:comment,status,external_ref'],
            'body' => ['required_if:action,comment', 'string'],
            'status' => ['required_if:action,status', 'string', 'in:new,in_review,in_progress,resolved,closed,declined'],
            'ref' => ['required_if:action,external_ref', 'array'],
            'ref.key' => ['required_with:ref', 'string'],
            'ref.value' => ['required_with:ref', 'string'],
        ]);

        $item = FeedbackItem::findOrFail($data['item_id']);

        match ($data['action']) {
            'comment' => $reply->handle($item, $data['body'] ?? '', null, AuthorRole::Ai),
            'status' => $transition->handle($item, FeedbackStatus::from($data['status']), null),
            'external_ref' => $item->forceFill([
                'external_refs' => array_merge($item->external_refs ?? [], [$data['ref']['key'] => $data['ref']['value']]),
            ])->save(),
        };

        return response()->json(['ok' => true]);
    }

    private function runner(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            'action' => ['required', 'in:runner_status,runner_result'],
            'task_id' => ['required', 'string', 'max:64'],
            'status' => ['required_if:action,runner_status', 'in:running,completed,failed'],
            'error_code' => ['nullable', 'string', 'regex:/^[a-z0-9_]{1,40}$/'],
            'analysis' => ['nullable', 'string', 'max:'.(int) config('feedback-board.runner.max_analysis_chars', 10000)],
            'diff' => ['nullable', 'string', 'max:'.(int) config('feedback-board.runner.max_diff_chars', 20000)],
            'pr_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $item = FeedbackItem::findOrFail($data['item_id']);

        try {
            // Synchronous path: a LockTimeoutException becomes a 503 so the runner retries
            // with a fresh signature (a new timestamp ⇒ new nonce key — not blocked as replay).
            match ($data['action']) {
                'runner_status' => RunnerIngest::status($item, $data),
                'runner_result' => RunnerIngest::result($item, $data),
            };
        } catch (LockTimeoutException) {
            abort(503);
        }

        return response()->json(['ok' => true]);
    }
```

> **Note:** `RunnerIngest::result` is implemented in Task 9 (which only *adds* the method to `RunnerIngest`; no further controller change). The status tests never post `runner_result`, and PHP resolves the `result` match arm only when `action === 'runner_result'`, so Task 8 compiles and passes with `result` not yet defined.

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Runner/IngestRunnerStatusTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Runner/Support/RunnerIngest.php src/Http/Controllers/IngestController.php tests/Feature/Runner/IngestRunnerStatusTest.php
git commit -m "feat(runner): ingest runner_status with correlation + monotonicity"
```

---

### Task 9: Ingest `runner_result` (analysis/diff/pr_url + terminal gate)

**Files:**
- Modify: `src/Runner/Support/RunnerIngest.php` (add `result()`)
- Test: `tests/Feature/Runner/IngestRunnerResultTest.php`

**Interfaces:**
- Consumes: `ReplyToFeedback`, `RunnerState`, `Cache` (github lock), `RunnerIngest::rank`.
- Produces: `RunnerIngest::result(FeedbackItem $item, array $data): void`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Runner/IngestRunnerResultTest.php`:
```php
<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use YousefAman\FeedbackBoard\Jobs\DeliverToDestination;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

// reuse the helpers from IngestRunnerStatusTest via a local copy
function signedR(array $payload): array {
    $body = json_encode($payload);
    $ts = (string) now()->timestamp;
    $sig = hash_hmac('sha256', $ts.'.'.$body, 'shhh');
    return [$body, ['X-Feedback-Timestamp' => $ts, 'X-Feedback-Signature' => $sig, 'Content-Type' => 'application/json']];
}
function postR(array $payload) {
    [$body, $headers] = signedR($payload);
    return test()->call('POST', route('feedback-board.ingest'), [], [], [], test()->transformHeadersToServerVars($headers), $body);
}
function runnerItem(string $status, string $taskId, array $extra = []): FeedbackItem {
    $item = FeedbackItem::factory()->create();
    $item->ai_state = ['runner' => array_merge(['status' => $status, 'task_id' => $taskId], $extra)];
    $item->save();
    return $item;
}

beforeEach(function () {
    config()->set('feedback-board.ai.lock_store', 'array');
    config()->set('feedback-board.destinations.lock_store', 'array'); // backs the github lock used by writePrRef
    config()->set('feedback-board.ingest.enabled', true);
    config()->set('feedback-board.ingest.secret', 'shhh');
});

it('posts the analysis as an INTERNAL AI comment and never leaks it', function () {
    Notification::fake();
    Bus::fake();
    $item = runnerItem('running', 'T1');

    postR(['item_id' => $item->id, 'action' => 'runner_result', 'task_id' => 'T1', 'analysis' => 'AGENT NOTES'])->assertOk();

    $comment = $item->comments()->latest('id')->first();
    expect($comment->body)->toBe('AGENT NOTES')
        ->and($comment->is_internal)->toBeTrue()                        // CRITICAL: internal only
        ->and($item->refresh()->ai_state['runner']['analysis_posted'])->toBeTrue();
    Notification::assertNothingSent();                                  // reporter not emailed
    Bus::assertNotDispatched(DeliverToDestination::class);              // not fanned out to destinations
});

it('does not duplicate the analysis comment on a re-post', function () {
    $item = runnerItem('running', 'T1');
    postR(['item_id' => $item->id, 'action' => 'runner_result', 'task_id' => 'T1', 'analysis' => 'ONCE'])->assertOk();
    postR(['item_id' => $item->id, 'action' => 'runner_result', 'task_id' => 'T1', 'analysis' => 'ONCE'])->assertOk();
    expect($item->comments()->where('body', 'ONCE')->count())->toBe(1);
});

it('stores the diff under the cap', function () {
    $item = runnerItem('running', 'T1');
    postR(['item_id' => $item->id, 'action' => 'runner_result', 'task_id' => 'T1', 'diff' => '--- a/x'])->assertOk();
    expect($item->refresh()->ai_state['runner']['diff'])->toBe('--- a/x');
});

it('accepts a valid https pr_url and advances to completed', function () {
    $item = runnerItem('running', 'T1');
    postR(['item_id' => $item->id, 'action' => 'runner_result', 'task_id' => 'T1', 'pr_url' => 'https://github.com/o/r/pull/5'])->assertOk();
    $fresh = $item->refresh();
    expect($fresh->external_refs['pr_url'])->toBe('https://github.com/o/r/pull/5')
        ->and($fresh->ai_state['runner']['pr_url'])->toBe('https://github.com/o/r/pull/5')
        ->and($fresh->ai_state['runner']['status'])->toBe('completed'); // opportunistic advance
});

it('rejects dangerous pr_urls and stores nothing', function () {
    foreach (['https://github.com@evil.com/x', 'http://github.com/o/r', 'javascript:alert(1)', 'ftp://h/x', 'https:///path'] as $bad) {
        $item = runnerItem('running', 'T'.md5($bad));
        postR(['item_id' => $item->id, 'action' => 'runner_result', 'task_id' => 'T'.md5($bad), 'pr_url' => $bad])->assertOk();
        $fresh = $item->refresh();
        expect($fresh->external_refs['pr_url'] ?? null)->toBeNull()
            ->and($fresh->ai_state['runner']['pr_url'] ?? null)->toBeNull();
    }
});

it('rejects a result whose task_id mismatches', function () {
    $item = runnerItem('running', 'T1');
    postR(['item_id' => $item->id, 'action' => 'runner_result', 'task_id' => 'STALE', 'diff' => 'x'])->assertStatus(409);
    expect($item->refresh()->ai_state['runner']['diff'] ?? null)->toBeNull();
});

it('heals a dispatch-failed item but rejects a runner-failed one', function () {
    $heal = runnerItem('failed', 'T1', ['failed_origin' => 'dispatch']);
    postR(['item_id' => $heal->id, 'action' => 'runner_result', 'task_id' => 'T1', 'pr_url' => 'https://github.com/o/r/pull/9'])->assertOk();
    expect($heal->refresh()->ai_state['runner']['status'])->toBe('completed');

    $reject = runnerItem('failed', 'T2', ['failed_origin' => 'runner']);
    postR(['item_id' => $reject->id, 'action' => 'runner_result', 'task_id' => 'T2', 'pr_url' => 'https://github.com/o/r/pull/9'])->assertOk();
    $fresh = $reject->refresh();
    expect($fresh->ai_state['runner']['status'])->toBe('failed')
        ->and($fresh->ai_state['runner']['pr_url'] ?? null)->toBeNull();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Feature/Runner/IngestRunnerResultTest.php`
Expected: FAIL — `RunnerIngest::result` not defined.

- [ ] **Step 3: Implement `RunnerIngest::result` (and a private `pr_url` validator)**

Add to `src/Runner/Support/RunnerIngest.php`:
```php
    /**
     * Apply a runner_result post-back. task_id correlation + terminal-state gate inside
     * the lock; analysis is written as an INTERNAL comment via a flag-before-comment
     * compare-and-set (at-most-once, never duplicated). Aborts 409 on task_id mismatch.
     *
     * @param array<string, mixed> $data validated payload
     */
    public static function result(FeedbackItem $item, array $data): void
    {
        $taskId = (string) $data['task_id'];
        $analysis = isset($data['analysis']) && $data['analysis'] !== '' ? (string) $data['analysis'] : null;
        $diff = isset($data['diff']) && $data['diff'] !== '' ? (string) $data['diff'] : null;
        $prUrl = self::safePrUrl($data['pr_url'] ?? null);

        $mismatch = false;
        $rejected = false;       // runner-reported failed: no content write
        $shouldPostAnalysis = false;

        RunnerState::lock($item->id, function (FeedbackItem $fresh) use ($taskId, $analysis, $diff, $prUrl, &$mismatch, &$rejected, &$shouldPostAnalysis) {
            $runner = $fresh->ai_state['runner'] ?? [];
            if (($runner['task_id'] ?? null) !== $taskId) {
                $mismatch = true;
                return;
            }
            if (($runner['status'] ?? null) === 'failed' && ($runner['failed_origin'] ?? null) === 'runner') {
                $rejected = true; // contradictory result after a reported failure
                return;
            }

            $current = $runner['status'] ?? null;
            $patch = [];
            if ($diff !== null) {
                $patch['diff'] = $diff;
            }
            if ($prUrl !== null) {
                $patch['pr_url'] = $prUrl;
            }
            // Advance to completed when (a) a dispatch-failed item heals — our send errored
            // but the runner actually ran (equal rank, so the rank check below misses it) —
            // or (b) a non-terminal status moves forward. Only when content arrived.
            $heal = $current === 'failed' && (($runner['failed_origin'] ?? null) === 'dispatch');
            $advance = $heal || self::rank('completed') > self::rank($current);
            if ($advance && ($prUrl !== null || $diff !== null || $analysis !== null)) {
                $patch['status'] = 'completed';
                $patch['failed_origin'] = null;
                $patch['error_code'] = null;
            }
            // Compare-and-set: claim the analysis slot BEFORE creating the comment.
            if ($analysis !== null && ! ($runner['analysis_posted'] ?? false)) {
                $patch['analysis_posted'] = true;
                $shouldPostAnalysis = true;
            }
            if ($patch !== []) {
                RunnerState::merge($fresh, $patch);
            }
        });

        abort_if($mismatch, 409);

        if ($rejected) {
            return; // 200 no-op
        }

        // external_refs.pr_url under the github lock (mirrors DeliverToDestination::failed).
        if ($prUrl !== null) {
            self::writePrRef($item->id, $prUrl);
        }

        // Comment created AFTER the flag is persisted: a crash here yields a rare missing
        // comment, never a duplicate. is_internal=true is mandatory (no reporter leak).
        if ($shouldPostAnalysis) {
            app(\YousefAman\FeedbackBoard\Actions\ReplyToFeedback::class)
                ->handle($item->fresh(), (string) $analysis, null, \YousefAman\FeedbackBoard\Enums\AuthorRole::Ai, isInternal: true);
        }
    }

    /** Validate a PR URL: https only, real host, NO userinfo. Returns null if unsafe. */
    private static function safePrUrl(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        $parts = parse_url($value);
        if ($parts === false) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';
        if ($scheme !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $value;
    }

    private static function writePrRef(int $id, string $prUrl): void
    {
        $store = config('feedback-board.destinations.lock_store');
        \Illuminate\Support\Facades\Cache::store($store)->lock('feedback-board:github:'.$id, 10)->block(8, function () use ($id, $prUrl) {
            $fresh = FeedbackItem::find($id);
            if ($fresh === null) {
                return;
            }
            $fresh->forceFill([
                'external_refs' => array_merge($fresh->external_refs ?? [], ['pr_url' => $prUrl]),
            ])->save();
        });
    }
```

Add the imports at the top of the file if your style requires them (the code uses fully-qualified `ReplyToFeedback`, `AuthorRole`, `Cache` inline, so no new `use` is strictly required). `writePrRef` uses `feedback-board.destinations.lock_store` — the result test's `beforeEach` already sets it to `array` (Step 1).

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Runner/IngestRunnerResultTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Runner/Support/RunnerIngest.php tests/Feature/Runner/IngestRunnerResultTest.php
git commit -m "feat(runner): ingest runner_result (internal analysis, diff, validated pr_url, terminal gate)"
```

---

### Task 10: Livewire panel

**Files:**
- Modify: `src/Livewire/FeedbackDetail.php` (add `dispatchToRunner`)
- Modify: `resources/views/livewire/feedback-detail.blade.php` (add the Runner panel)
- Test: `tests/Feature/Runner/RunnerLivewireTest.php`

**Interfaces:**
- Consumes: `DispatchToRunner`, `RunnerGate`.
- Produces: Livewire method `dispatchToRunner(DispatchToRunner $action)`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Runner/RunnerLivewireTest.php`:
```php
<?php

use Livewire\Livewire;
use YousefAman\FeedbackBoard\Livewire\FeedbackDetail;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    config()->set('feedback-board.ai.lock_store', 'array');
    config()->set('feedback-board.runner.enabled', true);
    config()->set('feedback-board.runner.endpoint', 'https://runner.test/task');
    config()->set('feedback-board.runner.secret', 'rsecret');
    config()->set('feedback-board.ingest.enabled', true);
    config()->set('feedback-board.ingest.secret', 'ingsecret');
    config()->set('feedback-board.authorization.developer_resolver', fn ($u) => ($u->name ?? null) === 'Dev');
    $this->actingAs(TestUser::create(['name' => 'Dev']));
});

it('renders the completed runner panel and escapes the diff and pr_url', function () {
    $item = FeedbackItem::factory()->create();
    $item->ai_state = ['runner' => [
        'status' => 'completed', 'task_id' => 'T1',
        'diff' => '<script>x</script>', 'pr_url' => 'https://github.com/o/r/pull/3',
    ]];
    $item->save();

    Livewire::test(FeedbackDetail::class, ['item' => $item])
        ->assertSee('https://github.com/o/r/pull/3')
        ->assertDontSee('<script>x</script>', false);   // escaped, not raw
});

it('shows diff/pr_url even while status is running', function () {
    $item = FeedbackItem::factory()->create();
    $item->ai_state = ['runner' => ['status' => 'running', 'task_id' => 'T1', 'pr_url' => 'https://github.com/o/r/pull/4']];
    $item->save();

    Livewire::test(FeedbackDetail::class, ['item' => $item])->assertSee('https://github.com/o/r/pull/4');
});

it('dispatchToRunner is a no-op while in flight (no error)', function () {
    $item = FeedbackItem::factory()->create();
    $item->ai_state = ['runner' => ['status' => 'running', 'task_id' => 'live']];
    $item->save();

    Livewire::test(FeedbackDetail::class, ['item' => $item])
        ->call('dispatchToRunner')
        ->assertOk();
    expect($item->refresh()->ai_state['runner']['task_id'])->toBe('live');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Feature/Runner/RunnerLivewireTest.php`
Expected: FAIL — `dispatchToRunner` / panel missing.

- [ ] **Step 3: Add the Livewire method**

In `src/Livewire/FeedbackDetail.php`, after `applyTriage(...)` (line ~101) add:
```php
    public function dispatchToRunner(\YousefAman\FeedbackBoard\Actions\DispatchToRunner $action): void
    {
        $this->guard();
        $action->handle($this->item, auth()->user());
        $this->item->refresh();
        $this->dispatch('feedback-runner-dispatched');
    }
```

- [ ] **Step 4: Add the Runner panel to the blade**

In `resources/views/livewire/feedback-detail.blade.php`, after the triage panel's closing `@endif` (the block starting at line 31), add:
```blade
    @php($runner = $item->ai_state['runner'] ?? null)
    @php($runnerConfigured = (bool) \YousefAman\FeedbackBoard\Runner\Support\RunnerGate::configured())
    @if ($runnerConfigured)
        <div class="fb-runner" wire:key="runner-{{ $item->id }}"
             @if(in_array(($runner['status'] ?? null), ['dispatched','running'], true)) wire:poll.5s @endif>
            @if (! config('feedback-board.ingest.enabled'))
                <p class="fb-runner-warn">{{ __('feedback-board::feedback-board.runner.ingest_disabled') }}</p>
            @elseif (($runner['status'] ?? null) === null)
                <button type="button" wire:click="dispatchToRunner">{{ __('feedback-board::feedback-board.runner.send') }}</button>
            @else
                @if (in_array($runner['status'], ['dispatched','running'], true))
                    <p>{{ __('feedback-board::feedback-board.runner.'.$runner['status']) }} — {{ $runner['dispatched_at'] ?? '' }}</p>
                @elseif ($runner['status'] === 'failed')
                    <p>{{ __('feedback-board::feedback-board.runner.failed') }}: {{ $runner['error_code'] ?? '' }}</p>
                @elseif ($runner['status'] === 'completed')
                    <p>{{ __('feedback-board::feedback-board.runner.completed') }}</p>
                @endif

                @if (($runner['diff'] ?? null) !== null)
                    <details><summary>{{ __('feedback-board::feedback-board.runner.diff') }}</summary><pre>{{ $runner['diff'] }}</pre></details>
                @endif
                @if (($runner['pr_url'] ?? null) !== null)
                    <a href="{{ $runner['pr_url'] }}" target="_blank" rel="noopener noreferrer">{{ $runner['pr_url'] }}</a>
                @endif

                @if (! in_array($runner['status'], ['dispatched','running'], true))
                    <button type="button" wire:click="dispatchToRunner">{{ __('feedback-board::feedback-board.runner.resend') }}</button>
                @endif
            @endif
        </div>
    @endif
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Runner/RunnerLivewireTest.php`
Expected: PASS. (Lang keys are added in Task 12; until then `__()` returns the key string, which the `assertSee` for the PR URL does not depend on. If an assertion needs a key, run Task 12 first.)

- [ ] **Step 6: Commit**

```bash
git add src/Livewire/FeedbackDetail.php resources/views/livewire/feedback-detail.blade.php tests/Feature/Runner/RunnerLivewireTest.php
git commit -m "feat(runner): Livewire runner panel + dispatch method"
```

---

### Task 11: Filament record actions

**Files:**
- Modify: `src/Filament/Resources/FeedbackItemResource.php` (add `sendToRunner`, `viewRunnerResult`)
- Create: `resources/views/filament/runner-result.blade.php`
- Test: `tests/Feature/Runner/RunnerFilamentTest.php`

**Interfaces:**
- Consumes: `DispatchToRunner`, `RunnerGate`, `FeedbackItemPolicy`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Runner/RunnerFilamentTest.php`:
```php
<?php

use Symfony\Component\HttpKernel\Exception\HttpException;
use YousefAman\FeedbackBoard\Actions\DispatchToRunner;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    config()->set('feedback-board.ai.lock_store', 'array');
    config()->set('feedback-board.runner.enabled', true);
    config()->set('feedback-board.runner.endpoint', 'https://runner.test/task');
    config()->set('feedback-board.runner.secret', 'rsecret');
    config()->set('feedback-board.ingest.enabled', true);
    config()->set('feedback-board.ingest.secret', 'ingsecret');
    config()->set('feedback-board.authorization.developer_resolver', fn ($u) => ($u->name ?? null) === 'Dev');
});

it('hard-authorizes sendToRunner inside the action body (not just UI hiding)', function () {
    // Invoke the same action the Filament button calls, with a non-developer.
    $item = FeedbackItem::factory()->create();
    expect(fn () => app(DispatchToRunner::class)->handle($item, TestUser::create(['name' => 'User'])))
        ->toThrow(HttpException::class);
});

it('renders the runner-result modal view escaped', function () {
    $view = view('feedback-board::filament.runner-result', ['runner' => [
        'status' => 'completed', 'diff' => '<script>x</script>', 'pr_url' => 'https://github.com/o/r/pull/1', 'error_code' => null,
    ]])->render();
    expect($view)->toContain('https://github.com/o/r/pull/1')
        ->and($view)->toContain('&lt;script&gt;')        // escaped
        ->and($view)->not->toContain('<script>x</script>');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Feature/Runner/RunnerFilamentTest.php`
Expected: FAIL — the modal view does not exist (first test passes already because `DispatchToRunner` aborts; keep both).

- [ ] **Step 3: Create the modal view**

`resources/views/filament/runner-result.blade.php`:
```blade
<div class="fb-runner-result">
    <p><strong>{{ __('feedback-board::feedback-board.runner.status') }}:</strong> {{ $runner['status'] ?? '' }}</p>
    @if (($runner['error_code'] ?? null) !== null)
        <p>{{ __('feedback-board::feedback-board.runner.failed') }}: {{ $runner['error_code'] }}</p>
    @endif
    @if (($runner['diff'] ?? null) !== null)
        <pre>{{ $runner['diff'] }}</pre>
    @endif
    @if (($runner['pr_url'] ?? null) !== null)
        <a href="{{ $runner['pr_url'] }}" target="_blank" rel="noopener noreferrer">{{ $runner['pr_url'] }}</a>
    @endif
</div>
```

- [ ] **Step 4: Add the Filament record actions**

In `src/Filament/Resources/FeedbackItemResource.php`, add imports:
```php
use YousefAman\FeedbackBoard\Actions\DispatchToRunner;
use YousefAman\FeedbackBoard\Runner\Support\RunnerGate;
```
and inside `recordActions([ ... ])`, after `viewSuggestion`, add:
```php
            Action::make('sendToRunner')
                ->label(__('feedback-board::feedback-board.runner.send'))
                ->visible(fn (FeedbackItem $record): bool =>
                    FeedbackItemPolicy::isDeveloper(auth()->user())
                    && RunnerGate::configured()
                    && (bool) config('feedback-board.ingest.enabled')
                    && ! in_array(($record->ai_state['runner']['status'] ?? null), ['dispatched', 'running'], true))
                ->action(fn (FeedbackItem $record) =>
                    // Hard authorization lives inside DispatchToRunner::handle() (abort_unless).
                    app(DispatchToRunner::class)->handle($record, auth()->user())),

            Action::make('viewRunnerResult')
                ->label(__('feedback-board::feedback-board.runner.view_result'))
                ->visible(fn (FeedbackItem $record): bool =>
                    FeedbackItemPolicy::isDeveloper(auth()->user())
                    && ($record->ai_state['runner']['status'] ?? null) !== null)
                ->modalContent(fn (FeedbackItem $record) => view(
                    'feedback-board::filament.runner-result',
                    ['runner' => $record->ai_state['runner'] ?? []],
                ))
                ->modalSubmitAction(false),
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Runner/RunnerFilamentTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Filament/Resources/FeedbackItemResource.php resources/views/filament/runner-result.blade.php tests/Feature/Runner/RunnerFilamentTest.php
git commit -m "feat(runner): Filament sendToRunner + viewRunnerResult actions"
```

---

### Task 12: Lang ar/en parity

**Files:**
- Modify: `resources/lang/en/feedback-board.php`, `resources/lang/ar/feedback-board.php`
- Test: `tests/Feature/Runner/RunnerLangTest.php`

**Interfaces:**
- Produces: a `runner` translation block with these keys: `send, resend, status, dispatched, running, completed, failed, diff, view_result, ingest_disabled`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Runner/RunnerLangTest.php`:
```php
<?php

it('has full runner key parity between en and ar', function () {
    $en = trans('feedback-board::feedback-board.runner', [], 'en');
    $ar = trans('feedback-board::feedback-board.runner', [], 'ar');

    expect($en)->toBeArray()->toHaveKeys(['send', 'resend', 'status', 'dispatched', 'running', 'completed', 'failed', 'diff', 'view_result', 'ingest_disabled'])
        ->and(array_keys($ar))->toEqualCanonicalizing(array_keys($en));
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Feature/Runner/RunnerLangTest.php`
Expected: FAIL — `runner` key missing.

- [ ] **Step 3: Add the `runner` block to `resources/lang/en/feedback-board.php`**

After the `triage` block, add:
```php
    'runner' => [
        'send' => 'Send to agent',
        'resend' => 'Re-send to agent',
        'status' => 'Status',
        'dispatched' => 'Dispatched to agent',
        'running' => 'Agent is working…',
        'completed' => 'Agent finished',
        'failed' => 'Agent run failed',
        'diff' => 'Suggested diff',
        'view_result' => 'View agent result',
        'ingest_disabled' => 'Ingest is disabled — agent post-back will fail. Enable feedback-board ingest.',
    ],
```

- [ ] **Step 4: Add the matching `runner` block to `resources/lang/ar/feedback-board.php`**

After the `triage` block, add:
```php
    'runner' => [
        'send' => 'أرسِل للوكيل',
        'resend' => 'إعادة الإرسال للوكيل',
        'status' => 'الحالة',
        'dispatched' => 'أُرسل إلى الوكيل',
        'running' => 'الوكيل يعمل…',
        'completed' => 'أنهى الوكيل',
        'failed' => 'فشل تشغيل الوكيل',
        'diff' => 'التعديل المقترح',
        'view_result' => 'عرض نتيجة الوكيل',
        'ingest_disabled' => 'الاستقبال (ingest) معطّل — سيفشل ردّ الوكيل. فعّل ingest في feedback-board.',
    ],
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Runner/RunnerLangTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add resources/lang/en/feedback-board.php resources/lang/ar/feedback-board.php tests/Feature/Runner/RunnerLangTest.php
git commit -m "feat(runner): ar/en runner translation parity"
```

---

### Task 13: Reference recipe + docs

**Files:**
- Create: `docs/examples/runner/receiver.example.php`, `docs/examples/runner/github-actions-feedback-runner.example.yml`, `docs/runner-contract.md`
- Modify: `README.md` (add "AI Runner Contract (Level B/C)" section)
- Test: `tests/Feature/Runner/DocsTest.php` (presence + contract-shape sanity)

**Interfaces:** none (documentation).

- [ ] **Step 1: Write the failing test**

`tests/Feature/Runner/DocsTest.php`:
```php
<?php

it('ships the runner contract docs and reference recipe', function () {
    $root = __DIR__.'/../../..';
    expect(file_exists($root.'/docs/runner-contract.md'))->toBeTrue()
        ->and(file_exists($root.'/docs/examples/runner/receiver.example.php'))->toBeTrue()
        ->and(file_exists($root.'/docs/examples/runner/github-actions-feedback-runner.example.yml'))->toBeTrue();

    $contract = file_get_contents($root.'/docs/runner-contract.md');
    expect($contract)->toContain('runner_status')
        ->and($contract)->toContain('runner_result')
        ->and($contract)->toContain('X-Feedback-Signature')
        ->and($contract)->toContain('task_id');

    expect(file_get_contents($root.'/README.md'))->toContain('AI Runner Contract');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Feature/Runner/DocsTest.php`
Expected: FAIL — files missing.

- [ ] **Step 3: Create `docs/runner-contract.md`**

Write a complete contract document with these sections (full prose, not placeholders):
- **Overview** — manual, opt-in; package never runs the agent or merges; results are suggestions.
- **What leaves the app** — the exact envelope schema (copy the §3.1 JSON), and an operator privacy note: *"the runner endpoint receives feedback body text — treat it as confidential reporter data; do not point it at an untrusted third-party receiver."*
- **Outbound headers** — `X-Feedback-Timestamp`, `X-Feedback-Signature` (`hash_hmac('sha256', "{ts}.{rawBody}", FEEDBACK_RUNNER_SECRET)`), `X-Feedback-Event: runner.task`, `X-Feedback-Delivery: {task_id}`.
- **Post-back** — `POST {ingest_url}` signed with `FEEDBACK_BOARD_INGEST_SECRET` using the SAME HMAC formula; two actions:
  - `runner_status`: `{item_id, task_id, status: running|completed|failed, error_code?}` (`error_code` matches `^[a-z0-9_]{1,40}$`).
  - `runner_result`: `{item_id, task_id, analysis?, diff?, pr_url?}` (`pr_url` MUST be https with no userinfo).
- **Rules** — echo the `task_id`; status is monotonic (terminals sticky); the runner SHOULD retry the terminal `runner_status` POST; a result-bearing post also advances status. `ingest.enabled` must be true.
- **Security** — runner runs in CI/dev with scoped credentials, NEVER production; no auto-merge (PR link only); secrets never placed in a feedback item.
- A worked end-to-end example.

- [ ] **Step 4: Create `docs/examples/runner/receiver.example.php`**

A ~30-line PHP receiver that verifies the HMAC and triggers `repository_dispatch`:
```php
<?php
// Minimal HMAC-verifying receiver. Deploy on YOUR infra. It bridges the package's
// signed task POST to a GitHub repository_dispatch that runs your CI agent.
// NOT part of the package runtime — runs with YOUR GitHub token, never in production app context.

$secret = getenv('FEEDBACK_RUNNER_SECRET');
$ghToken = getenv('GITHUB_TOKEN');           // scoped, repo dispatch only
$repo = getenv('GITHUB_REPO');               // "owner/name"

$raw = file_get_contents('php://input');
$ts = $_SERVER['HTTP_X_FEEDBACK_TIMESTAMP'] ?? '';
$sig = $_SERVER['HTTP_X_FEEDBACK_SIGNATURE'] ?? '';
$expected = hash_hmac('sha256', $ts.'.'.$raw, $secret);
if (! hash_equals($expected, $sig) || abs(time() - (int) $ts) > 300) {
    http_response_code(403); exit('bad signature');
}

$envelope = json_decode($raw, true);
$ch = curl_init("https://api.github.com/repos/{$repo}/dispatches");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ["Authorization: Bearer {$ghToken}", 'Accept: application/vnd.github+json', 'User-Agent: feedback-runner'],
    CURLOPT_POSTFIELDS => json_encode(['event_type' => 'feedback_task', 'client_payload' => $envelope]),
    CURLOPT_RETURNTRANSFER => true,
]);
curl_exec($ch);
http_response_code(202); echo 'queued';
```

- [ ] **Step 5: Create `docs/examples/runner/github-actions-feedback-runner.example.yml`**

A `repository_dispatch` workflow that runs the agent and posts back (full, runnable example):
```yaml
name: Feedback Runner
on:
  repository_dispatch:
    types: [feedback_task]

jobs:
  run-agent:
    runs-on: ubuntu-latest
    permissions:
      contents: write
      pull-requests: write
    steps:
      - uses: actions/checkout@v4

      - name: Run the coding agent (Claude Code Action or your tool of choice)
        id: agent
        env:
          ITEM_TITLE: ${{ github.event.client_payload.item.title }}
          ITEM_DESC: ${{ github.event.client_payload.item.description }}
        run: |
          # ... run your agent here; produce $GITHUB_OUTPUT: analysis, diff, pr_url ...
          echo "analysis=Investigated; root cause in X." >> "$GITHUB_OUTPUT"

      - name: Post results back to the feedback board (signed ingest)
        env:
          INGEST_URL: ${{ secrets.FEEDBACK_INGEST_URL }}
          INGEST_SECRET: ${{ secrets.FEEDBACK_BOARD_INGEST_SECRET }}
          ITEM_ID: ${{ github.event.client_payload.item.id }}
          TASK_ID: ${{ github.event.client_payload.task_id }}
          ANALYSIS: ${{ steps.agent.outputs.analysis }}
          PR_URL: ${{ steps.agent.outputs.pr_url }}
        run: |
          post() {
            BODY="$1"
            TS=$(date +%s)
            SIG=$(printf '%s.%s' "$TS" "$BODY" | openssl dgst -sha256 -hmac "$INGEST_SECRET" | awk '{print $2}')
            curl -sS -X POST "$INGEST_URL" \
              -H "Content-Type: application/json" \
              -H "X-Feedback-Timestamp: $TS" \
              -H "X-Feedback-Signature: $SIG" \
              --data "$BODY"
          }
          post "{\"item_id\":$ITEM_ID,\"action\":\"runner_result\",\"task_id\":\"$TASK_ID\",\"analysis\":\"$ANALYSIS\",\"pr_url\":\"$PR_URL\"}"
          post "{\"item_id\":$ITEM_ID,\"action\":\"runner_status\",\"task_id\":\"$TASK_ID\",\"status\":\"completed\"}"
```

- [ ] **Step 6: Add the README section**

Add an "## AI Runner Contract (Level B/C)" section to `README.md` summarizing: opt-in/manual; the `runner` config keys; that the runner runs in the developer's CI/dev (never production); no auto-merge; and a pointer to `docs/runner-contract.md`.

- [ ] **Step 7: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Runner/DocsTest.php`
Expected: PASS.

- [ ] **Step 8: Run the FULL suite**

Run: `vendor/bin/pest`
Expected: PASS (all prior 142 tests + the new runner tests green).

- [ ] **Step 9: Commit**

```bash
git add docs/runner-contract.md docs/examples/runner/ README.md tests/Feature/Runner/DocsTest.php
git commit -m "docs(runner): contract docs + reference receiver/GitHub Actions recipe"
```

---

## Final verification

After Task 13, run the whole suite and the broad review:
```bash
vendor/bin/pest
```
Expected: all green. Then proceed to the whole-branch review per subagent-driven-development.
