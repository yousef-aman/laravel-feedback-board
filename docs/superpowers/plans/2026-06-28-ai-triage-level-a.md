# AI Triage (Level A, in-app) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an opt-in in-app AI layer that suggests a `type`/`priority` and a summary for each feedback item, stored as developer-reviewed suggestions in `ai_state`.

**Architecture:** A synchronous listener on `FeedbackItemCreated` (gated by configuration) calls a `TriageDispatcher` that marks the item `pending` and enqueues an after-commit job. The job sends **only the title+description** to a `TriageManager`-resolved driver (`fake`/`anthropic` via `Http`, no SDK), validates the result, and writes a terminal `completed`/`failed` state into `ai_state` under a per-item lock. Manual trigger and per-field apply are hard-authorized actions wired into Livewire and Filament.

**Tech Stack:** Laravel 11/12, Livewire 3, optional Filament v4/v5, `spatie/laravel-package-tools`, Pest 3 + Orchestra Testbench, Anthropic Messages API via `Illuminate\Support\Facades\Http`.

**Spec:** `docs/superpowers/specs/2026-06-28-ai-triage-level-a-design.md`

## Global Constraints

- `declare(strict_types=1)` on every new `src/` file (enforced by `tests/ArchTest.php` over the whole `YousefAman\FeedbackBoard` namespace).
- Opt-in: `ai.enabled` defaults `false`. The listener is also inert when the selected driver is unconfigured (e.g. `anthropic` without an API key).
- Data minimization is structural: the `TriageDriver` contract receives `(string $title, ?string $description)` only — never a `FeedbackItem`.
- Both title and description are length-bounded into a combined `ai.max_input_chars` budget (default 4000) before leaving the app.
- `ai_state` and `external_refs` are server-authored only — removed from the model `$fillable`; create/ingest payloads cannot set them.
- AI never writes canonical columns. Suggestions live in `ai_state['triage']` until a developer applies them.
- Untrusted `summary`/`model` are rendered escaped only — Blade `{{ }}` (never `{!! !!}`), Filament `TextEntry`/`TextColumn` never `->html()`/`->markdown()`.
- Secrets: API key from env only; never logged or stored. The driver converts every throwable to a `TriageException` carrying only a sanitized code and **no `$previous`**, so `(string) $exception` cannot reach the key. Failures store a sanitized `error_code` only.
- Transactional dispatch: the job sets `public bool $afterCommit = true;`.
- Reuse `Destinations\Support\TransientRetry`. Do **not** reuse `RetryAfter`/`release()` — the triage job is throw/fail-based and always reaches a terminal `ai_state`.
- Suggested enum values stored only when they pass `FeedbackType::tryFrom` / `FeedbackPriority::tryFrom`; otherwise `null`.
- Error vocabulary (closed set, default `unknown`): `connection_error | http_5xx | http_429 | auth_401 | validation_422 | not_found_404 | not_configured | invalid_output | unknown`.

---

### Task 1: Config block, server-authored columns, mass-assignment guard

**Files:**
- Modify: `config/feedback-board.php` (add the `ai` block after the `ingest` block)
- Modify: `src/Models/FeedbackItem.php:17-21` (remove `ai_state` and `external_refs` from `$fillable`)
- Modify: `tests/TestCase.php` (force an atomic `ai.lock_store` for the whole suite)
- Test: `tests/Feature/Triage/ConfigTest.php`

**Interfaces:**
- Produces: config keys `feedback-board.ai.{enabled,default,queue,lock_store,max_input_chars}` and `feedback-board.ai.anthropic.{api_key,model,timeout,max_tokens}`. `FeedbackItem` no longer mass-assigns `ai_state`/`external_refs`.

> **Suite-wide lock store:** once Task 9 subscribes the listener, every test that creates an item (with AI enabled) reaches `TriageDispatcher`. To prevent any AI-enabled test from silently falling through to a non-atomic `file` lock store, set the store once in `TestCase::defineEnvironment()` (Step 4 below). This removes the need for a per-file `ai.lock_store` `beforeEach` in later tasks.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Triage/ConfigTest.php

use YousefAman\FeedbackBoard\Models\FeedbackItem;

it('ships ai config defaults', function () {
    expect(config('feedback-board.ai.enabled'))->toBeFalse()
        ->and(config('feedback-board.ai.default'))->toBe('anthropic')
        ->and(config('feedback-board.ai.max_input_chars'))->toBe(4000)
        ->and(config('feedback-board.ai.anthropic.model'))->toBe('claude-haiku-4-5')
        ->and(config('feedback-board.ai.anthropic.max_tokens'))->toBe(512);
});

it('does not mass-assign server-authored columns but still allows server writes', function () {
    // Mass assignment (create/ingest path) cannot set them.
    $item = FeedbackItem::create([
        'title' => 'x',
        'ai_state' => ['triage' => ['status' => 'completed']],
        'external_refs' => ['github' => ['issue_number' => 1]],
    ]);
    expect($item->refresh()->ai_state)->toBeNull()
        ->and($item->external_refs)->toBeNull();

    // Positive control: direct property assignment (the server-authored path) still persists.
    $item->ai_state = ['triage' => ['status' => 'pending']];
    $item->save();
    expect($item->refresh()->ai_state)->toBe(['triage' => ['status' => 'pending']]);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Feature/Triage/ConfigTest.php`
Expected: FAIL — `ai` config key missing; `ai_state`/`external_refs` still mass-assigned.

- [ ] **Step 3: Add the `ai` config block** (after the `ingest` block in `config/feedback-board.php`, before the closing `];`)

```php
    'ai' => [
        // Opt-in. With this false — or the selected driver unconfigured — the
        // listener does not dispatch and the manual action returns 422.
        'enabled' => env('FEEDBACK_BOARD_AI_ENABLED', false),

        'default' => env('FEEDBACK_BOARD_AI_DRIVER', 'anthropic'),

        'queue' => env('FEEDBACK_BOARD_AI_QUEUE'), // null = default queue

        // Atomic cache store backing the per-item ai_state lock. MUST be a
        // LockProvider (redis|memcached|dynamodb|database). null = app default.
        // `array` is atomic but process-local (tests use it). Same caveat as
        // destinations.lock_store: a `file` store THROWS on lock().
        'lock_store' => env('FEEDBACK_BOARD_AI_LOCK_STORE'),

        // Combined title+description budget sent to the model (cost bound).
        'max_input_chars' => 4000,

        'anthropic' => [
            'api_key' => env('FEEDBACK_BOARD_ANTHROPIC_KEY'),
            'model' => env('FEEDBACK_BOARD_ANTHROPIC_MODEL', 'claude-haiku-4-5'),
            'timeout' => 30,
            'max_tokens' => 512,
        ],
    ],
```

- [ ] **Step 4: Remove server-authored columns from `$fillable`**

In `src/Models/FeedbackItem.php`, change the `$fillable` array from:

```php
    protected $fillable = [
        'project', 'reporter_type', 'reporter_id', 'reporter_name', 'reporter_email',
        'title', 'description', 'type', 'status', 'priority',
        'assignee_type', 'assignee_id', 'meta', 'external_refs', 'ai_state', 'resolved_at',
    ];
```

to (drop `'external_refs'` and `'ai_state'`):

```php
    protected $fillable = [
        'project', 'reporter_type', 'reporter_id', 'reporter_name', 'reporter_email',
        'title', 'description', 'type', 'status', 'priority',
        'assignee_type', 'assignee_id', 'meta', 'resolved_at',
    ];
```

Leave `$casts` unchanged (`ai_state`/`external_refs` stay cast to `array`). All writers use direct property assignment, and factories use `Model::unguarded`, so neither is affected.

- [ ] **Step 4b: Force an atomic triage lock store for the whole suite**

In `tests/TestCase.php`, inside `defineEnvironment($app)`, add (alongside the existing `$app['config']->set(...)` lines):

```php
        $app['config']->set('feedback-board.ai.lock_store', 'array');
```

This guarantees every AI-enabled test uses an atomic (process-local) lock store; no later task needs a per-file `ai.lock_store` `beforeEach`.

- [ ] **Step 5: Run the tests and make sure they pass**

Run: `vendor/bin/pest tests/Feature/Triage/ConfigTest.php`
Expected: PASS (2 passed).

- [ ] **Step 6: Run the full suite to confirm no regression** (the model change is repo-wide)

Run: `vendor/bin/pest`
Expected: PASS — all existing tests green (no code mass-assigns `ai_state`/`external_refs`).

- [ ] **Step 7: Commit**

```bash
git add config/feedback-board.php src/Models/FeedbackItem.php tests/TestCase.php tests/Feature/Triage/ConfigTest.php
git commit -m "feat(triage): ai config block; make ai_state/external_refs server-authored"
```

---

### Task 2: Driver abstraction — TriageResult, contract, manager, fake driver

**Files:**
- Create: `src/Triage/Support/TriageResult.php`
- Create: `src/Triage/Contracts/TriageDriver.php`
- Create: `src/Triage/TriageManager.php`
- Create: `src/Triage/Drivers/FakeTriageDriver.php`
- Test: `tests/Feature/Triage/TriageManagerTest.php`

**Interfaces:**
- Produces:
  - `TriageResult::__construct(?FeedbackType $type, ?FeedbackPriority $priority, string $summary, string $model)` with `public readonly` props.
  - `interface TriageDriver { public function triage(string $title, ?string $description): TriageResult; }`
  - `TriageManager extends Manager` with `getDefaultDriver(): string` and `createFakeDriver()/createAnthropicDriver(): TriageDriver`; resolvable via `app(TriageManager::class)`.
  - `FakeTriageDriver` returns `new TriageResult(FeedbackType::Bug, FeedbackPriority::Medium, Str::limit($title, 100), 'fake')`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Triage/TriageManagerTest.php

use YousefAman\FeedbackBoard\Enums\FeedbackPriority;
use YousefAman\FeedbackBoard\Enums\FeedbackType;
use YousefAman\FeedbackBoard\Triage\Contracts\TriageDriver;
use YousefAman\FeedbackBoard\Triage\Support\TriageResult;
use YousefAman\FeedbackBoard\Triage\TriageManager;

it('resolves the fake driver and returns a deterministic result', function () {
    $result = app(TriageManager::class)->driver('fake')->triage('Login button broken', 'It does nothing');

    expect($result)->toBeInstanceOf(TriageResult::class)
        ->and($result->type)->toBe(FeedbackType::Bug)
        ->and($result->priority)->toBe(FeedbackPriority::Medium)
        ->and($result->summary)->toBe('Login button broken')
        ->and($result->model)->toBe('fake');
});

it('lets tests register a spy driver resolved by name', function () {
    app(TriageManager::class)->extend('spy', fn () => new class implements TriageDriver {
        public function triage(string $title, ?string $description): TriageResult {
            return new TriageResult(null, null, "ok:{$title}", 'spy');
        }
    });

    $result = app(TriageManager::class)->driver('spy')->triage('t', 'd');
    expect($result->summary)->toBe('ok:t')->and($result->model)->toBe('spy');
});

it('reads the default driver from config', function () {
    config()->set('feedback-board.ai.default', 'fake');
    expect(app(TriageManager::class)->getDefaultDriver())->toBe('fake');
});
```

> The canonical spy pattern used by later tasks (Task 7) is a closure-backed spy that captures inputs; here we only need to prove name-resolution + `extend()`, so asserting the returned `TriageResult` suffices.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Feature/Triage/TriageManagerTest.php`
Expected: FAIL — classes do not exist.

- [ ] **Step 3: Create `TriageResult`**

```php
<?php
// src/Triage/Support/TriageResult.php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Triage\Support;

use YousefAman\FeedbackBoard\Enums\FeedbackPriority;
use YousefAman\FeedbackBoard\Enums\FeedbackType;

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

- [ ] **Step 4: Create the `TriageDriver` contract**

```php
<?php
// src/Triage/Contracts/TriageDriver.php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Triage\Contracts;

use YousefAman\FeedbackBoard\Triage\Support\TriageResult;

interface TriageDriver
{
    /** Receives text only (data minimization is enforced by this signature). */
    public function triage(string $title, ?string $description): TriageResult;
}
```

- [ ] **Step 5: Create the `TriageManager`**

```php
<?php
// src/Triage/TriageManager.php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Triage;

use Illuminate\Support\Manager;
use YousefAman\FeedbackBoard\Triage\Contracts\TriageDriver;
use YousefAman\FeedbackBoard\Triage\Drivers\AnthropicDriver;
use YousefAman\FeedbackBoard\Triage\Drivers\FakeTriageDriver;

class TriageManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('feedback-board.ai.default', 'anthropic');
    }

    protected function createFakeDriver(): TriageDriver
    {
        return new FakeTriageDriver();
    }

    protected function createAnthropicDriver(): TriageDriver
    {
        return new AnthropicDriver();
    }
}
```

- [ ] **Step 5b: Create a minimal `AnthropicDriver` stub** (fleshed out in Task 5)

`createAnthropicDriver()` references this class. Create it unconditionally now so resolving the default (`anthropic`) driver can never class-not-found while the rest of the plan lands:

```php
<?php
// src/Triage/Drivers/AnthropicDriver.php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Triage\Drivers;

use YousefAman\FeedbackBoard\Triage\Contracts\TriageDriver;
use YousefAman\FeedbackBoard\Triage\Exceptions\TriageException;
use YousefAman\FeedbackBoard\Triage\Support\TriageResult;

final class AnthropicDriver implements TriageDriver
{
    public function triage(string $title, ?string $description): TriageResult
    {
        // Replaced with the real implementation in Task 5.
        throw new TriageException('not_configured');
    }
}
```

> `TriageException` is created in Task 3. If you are implementing strictly in order, the stub can instead `throw new \RuntimeException('not implemented')` until Task 3 lands, then switch to `TriageException`. Either way the class must exist after Task 2.

- [ ] **Step 6: Create `FakeTriageDriver`**

```php
<?php
// src/Triage/Drivers/FakeTriageDriver.php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Triage\Drivers;

use Illuminate\Support\Str;
use YousefAman\FeedbackBoard\Enums\FeedbackPriority;
use YousefAman\FeedbackBoard\Enums\FeedbackType;
use YousefAman\FeedbackBoard\Triage\Contracts\TriageDriver;
use YousefAman\FeedbackBoard\Triage\Support\TriageResult;

final class FakeTriageDriver implements TriageDriver
{
    public function triage(string $title, ?string $description): TriageResult
    {
        return new TriageResult(
            FeedbackType::Bug,
            FeedbackPriority::Medium,
            Str::limit($title, 100),
            'fake',
        );
    }
}
```

- [ ] **Step 7: Bind the manager singleton** (so `app(TriageManager::class)` works in the test)

In `src/FeedbackBoardServiceProvider.php`, inside `packageRegistered()`, add below the existing `DestinationManager` binding:

```php
        $this->app->singleton(
            \YousefAman\FeedbackBoard\Triage\TriageManager::class,
            fn ($app) => new \YousefAman\FeedbackBoard\Triage\TriageManager($app),
        );
```

- [ ] **Step 8: Run the tests and make sure they pass**

Run: `vendor/bin/pest tests/Feature/Triage/TriageManagerTest.php`
Expected: PASS (2 passed).

- [ ] **Step 9: Commit**

```bash
git add src/Triage tests/Feature/Triage/TriageManagerTest.php src/FeedbackBoardServiceProvider.php
git commit -m "feat(triage): driver contract, manager, fake driver, TriageResult DTO"
```

---

### Task 3: Sanitized errors — TriageException + TriageError

**Files:**
- Create: `src/Triage/Exceptions/TriageException.php`
- Create: `src/Triage/Support/TriageError.php`
- Test: `tests/Unit/TriageErrorTest.php`

**Interfaces:**
- Produces:
  - `TriageException extends RuntimeException` with `public readonly string $code`; `__construct(string $code)` calls `parent::__construct($code)` and passes **no** previous.
  - `TriageError::code(Throwable $e): string` — for a `TriageException` returns its `code`; maps `ConnectionException`/`RequestException` to the closed vocabulary; default `unknown`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/TriageErrorTest.php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use YousefAman\FeedbackBoard\Triage\Exceptions\TriageException;
use YousefAman\FeedbackBoard\Triage\Support\TriageError;

function reqError(int $status): RequestException {
    return new RequestException(new Response(
        new \GuzzleHttp\Psr7\Response($status, [], 'body')
    ));
}

it('passes through a TriageException code', function () {
    expect(TriageError::code(new TriageException('not_configured')))->toBe('not_configured')
        ->and(TriageError::code(new TriageException('invalid_output')))->toBe('invalid_output');
});

it('maps connection and http errors to the closed vocabulary', function () {
    expect(TriageError::code(new ConnectionException('x')))->toBe('connection_error')
        ->and(TriageError::code(reqError(401)))->toBe('auth_401')
        ->and(TriageError::code(reqError(403)))->toBe('auth_401')
        ->and(TriageError::code(reqError(404)))->toBe('not_found_404')
        ->and(TriageError::code(reqError(422)))->toBe('validation_422')
        ->and(TriageError::code(reqError(429)))->toBe('http_429')
        ->and(TriageError::code(reqError(503)))->toBe('http_5xx')
        ->and(TriageError::code(new \RuntimeException('boom')))->toBe('unknown');
});

it('never includes the original message (no previous on TriageException)', function () {
    $e = new TriageException('auth_401');
    expect((string) $e)->not->toContain('secret')
        ->and($e->getPrevious())->toBeNull();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Unit/TriageErrorTest.php`
Expected: FAIL — classes do not exist.

- [ ] **Step 3: Create `TriageException`**

```php
<?php
// src/Triage/Exceptions/TriageException.php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Triage\Exceptions;

use RuntimeException;

/**
 * Carries ONLY a sanitized code from the closed vocabulary. Constructed with no
 * previous exception so `(string) $e` cannot walk back to a message/headers that
 * embed the API key.
 */
final class TriageException extends RuntimeException
{
    public function __construct(public readonly string $code)
    {
        parent::__construct($code);
    }
}
```

- [ ] **Step 4: Create `TriageError`**

```php
<?php
// src/Triage/Support/TriageError.php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Triage\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;
use YousefAman\FeedbackBoard\Triage\Exceptions\TriageException;

final class TriageError
{
    /** Map any throwable to a fixed, secret-free vocabulary. Default: 'unknown'. */
    public static function code(Throwable $e): string
    {
        if ($e instanceof TriageException) {
            return $e->code;
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

- [ ] **Step 5: Run the tests and make sure they pass**

Run: `vendor/bin/pest tests/Unit/TriageErrorTest.php`
Expected: PASS (3 passed).

- [ ] **Step 6: Commit**

```bash
git add src/Triage/Exceptions/TriageException.php src/Triage/Support/TriageError.php tests/Unit/TriageErrorTest.php
git commit -m "feat(triage): sanitized TriageException + TriageError code mapper"
```

---

### Task 4: TriagePrompt — input curation + prompt/tool builders

**Files:**
- Create: `src/Triage/Support/TriagePrompt.php`
- Test: `tests/Unit/TriagePromptTest.php`

**Interfaces:**
- Produces:
  - `TriagePrompt::system(): string` — includes the `type` and `priority` enum value vocabularies.
  - `TriagePrompt::user(string $title, ?string $description, int $maxChars): string` — title capped at 200 chars, description filling the remainder of `$maxChars`.
  - `TriagePrompt::tool(): array` — the `submit_triage` tool definition (name, description, `input_schema` with `type`/`priority` enums and required `summary`).

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/TriagePromptTest.php

use YousefAman\FeedbackBoard\Triage\Support\TriagePrompt;

it('lists the enum vocabularies in the system prompt', function () {
    $system = TriagePrompt::system();
    foreach (['bug', 'feature', 'question', 'other', 'low', 'medium', 'high', 'urgent'] as $token) {
        expect($system)->toContain($token);
    }
});

it('bounds the combined title and description to max chars', function () {
    $title = str_repeat('T', 500);
    $desc = str_repeat('D', 9000);

    $user = TriagePrompt::user($title, $desc, 4000);

    // Title capped at 200, remainder for description → total well under the raw 9500.
    expect(mb_strlen($user))->toBeLessThanOrEqual(4000)
        ->and($user)->toContain(str_repeat('T', 200))
        ->and($user)->not->toContain(str_repeat('T', 201));
});

it('builds a forced-tool schema requiring summary', function () {
    $tool = TriagePrompt::tool();
    expect($tool['name'])->toBe('submit_triage')
        ->and($tool['input_schema']['required'])->toBe(['summary'])
        ->and($tool['input_schema']['properties']['type']['enum'])->toBe(['bug', 'feature', 'question', 'other'])
        ->and($tool['input_schema']['properties']['priority']['enum'])->toBe(['low', 'medium', 'high', 'urgent']);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Unit/TriagePromptTest.php`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Create `TriagePrompt`**

```php
<?php
// src/Triage/Support/TriagePrompt.php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Triage\Support;

use Illuminate\Support\Str;
use YousefAman\FeedbackBoard\Enums\FeedbackPriority;
use YousefAman\FeedbackBoard\Enums\FeedbackType;

final class TriagePrompt
{
    private const TITLE_CAP = 200;

    /** @return list<string> */
    private static function typeValues(): array
    {
        return array_map(fn (FeedbackType $t) => $t->value, FeedbackType::cases());
    }

    /** @return list<string> */
    private static function priorityValues(): array
    {
        return array_map(fn (FeedbackPriority $p) => $p->value, FeedbackPriority::cases());
    }

    public static function system(): string
    {
        $types = implode(', ', self::typeValues());
        $priorities = implode(', ', self::priorityValues());

        return implode("\n", [
            'You triage user feedback for a software team.',
            "Choose the single best `type` from: {$types}.",
            "Choose the single best `priority` from: {$priorities}.",
            'If a field is genuinely unclear, omit it rather than guessing.',
            'Write a concise one-or-two sentence `summary` in the SAME language as the input.',
            'Always respond by calling the submit_triage tool.',
        ]);
    }

    public static function user(string $title, ?string $description, int $maxChars): string
    {
        $title = Str::limit(trim($title), self::TITLE_CAP, '');
        $titleLine = "Title: {$title}";

        $description = trim((string) $description);
        if ($description === '') {
            return $titleLine;
        }

        // Account for the framing labels INSIDE the budget so the final string
        // never exceeds $maxChars (the spec's combined budget).
        $descLabel = "\n\nDescription: ";
        $remaining = max(0, $maxChars - mb_strlen($titleLine) - mb_strlen($descLabel));
        $description = Str::limit($description, $remaining, '');

        return $titleLine.$descLabel.$description;
    }

    /** @return array<string, mixed> */
    public static function tool(): array
    {
        return [
            'name' => 'submit_triage',
            'description' => 'Submit the triage classification for one feedback item.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'type' => ['type' => 'string', 'enum' => self::typeValues()],
                    'priority' => ['type' => 'string', 'enum' => self::priorityValues()],
                    'summary' => ['type' => 'string', 'description' => 'Concise summary in the input language.'],
                ],
                'required' => ['summary'],
            ],
        ];
    }
}
```

> With the test inputs (title 500×`T`, description 9000×`D`, `maxChars` 4000): title caps to 200 → `titleLine` = `"Title: "` (7) + 200 = 207; `descLabel` = 15; `remaining` = 4000 − 207 − 15 = 3778; description caps to 3778. Total = 207 + 15 + 3778 = **4000** (≤ 4000 ✓). The 200-`T` run is present and the 201-`T` run is absent ✓.

- [ ] **Step 4: Run the tests and make sure they pass**

Run: `vendor/bin/pest tests/Unit/TriagePromptTest.php`
Expected: PASS (3 passed).

- [ ] **Step 5: Commit**

```bash
git add src/Triage/Support/TriagePrompt.php tests/Unit/TriagePromptTest.php
git commit -m "feat(triage): prompt builder with bounded input curation and forced-tool schema"
```

---

### Task 5: AnthropicDriver

**Files:**
- Create (or replace the Task 2 stub): `src/Triage/Drivers/AnthropicDriver.php`
- Test: `tests/Feature/Triage/AnthropicDriverTest.php`

**Interfaces:**
- Consumes: `TriageDriver`, `TriageResult`, `TriagePrompt`, `TriageError`, `TriageException`, `TransientRetry`, `Http`, `Response`.
- Produces: `AnthropicDriver implements TriageDriver`. Sends the documented body; surfaces non-2xx via a post-retry guard; converts every error to a sanitized `TriageException`; missing key → `TriageException('not_configured')`; bad/empty `summary` → `TriageException('invalid_output')`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Triage/AnthropicDriverTest.php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use YousefAman\FeedbackBoard\Enums\FeedbackPriority;
use YousefAman\FeedbackBoard\Enums\FeedbackType;
use YousefAman\FeedbackBoard\Triage\Drivers\AnthropicDriver;
use YousefAman\FeedbackBoard\Triage\Exceptions\TriageException;

beforeEach(function () {
    config()->set('feedback-board.ai.anthropic.api_key', 'sk-ant-secret');
    config()->set('feedback-board.ai.anthropic.model', 'claude-haiku-4-5');
    Sleep::fake();
});

function toolUse(array $input): array {
    return ['content' => [['type' => 'tool_use', 'name' => 'submit_triage', 'input' => $input]]];
}

it('sends the documented body and parses the tool_use result', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(
        toolUse(['type' => 'bug', 'priority' => 'high', 'summary' => 'Login fails']), 200
    )]);

    $result = (new AnthropicDriver())->triage('Login broken', 'It does nothing');

    expect($result->type)->toBe(FeedbackType::Bug)
        ->and($result->priority)->toBe(FeedbackPriority::High)
        ->and($result->summary)->toBe('Login fails')
        ->and($result->model)->toBe('claude-haiku-4-5');

    Http::assertSent(function ($request) {
        $b = $request->data();
        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->hasHeader('x-api-key', 'sk-ant-secret')
            && $request->hasHeader('anthropic-version', '2023-06-01')
            && $b['model'] === 'claude-haiku-4-5'
            && is_int($b['max_tokens']) && $b['max_tokens'] > 0
            && is_string($b['system'])
            && $b['tool_choice'] === ['type' => 'tool', 'name' => 'submit_triage']
            && str_contains($b['messages'][0]['content'], 'Login broken');
    });
    // The real data-minimization boundary (the job passes only title+description,
    // never the FeedbackItem) is proven at the job layer in Task 7.
});

it('coerces invalid enums to null', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(
        toolUse(['type' => 'banana', 'summary' => 'x']), 200
    )]);

    $result = (new AnthropicDriver())->triage('t', 'd');
    expect($result->type)->toBeNull()->and($result->priority)->toBeNull();
});

it('throws invalid_output when summary is missing', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(toolUse(['type' => 'bug']), 200)]);

    expect(fn () => (new AnthropicDriver())->triage('t', 'd'))
        ->toThrow(TriageException::class, 'invalid_output');
});

it('sanitizes a non-2xx response and never leaks the key', function () {
    \Illuminate\Support\Facades\Log::spy();
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'bad key'], 401)]);

    try {
        (new AnthropicDriver())->triage('t', 'd');
        $this->fail('expected TriageException');
    } catch (TriageException $e) {
        // The string form is what Laravel persists to failed_jobs — it must be key-free.
        expect($e->code)->toBe('auth_401')
            ->and((string) $e)->not->toContain('sk-ant-secret')
            ->and($e->getMessage())->not->toContain('sk-ant-secret')
            ->and($e->getPrevious())->toBeNull();
    }
    // The driver never logs the key (it does not log at all on this path).
    \Illuminate\Support\Facades\Log::shouldNotHaveReceived('error');
});

it('sanitizes a connection error without leaking the key', function () {
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 7 with x-api-key: sk-ant-secret'));

    try {
        (new AnthropicDriver())->triage('t', 'd');
        $this->fail('expected TriageException');
    } catch (TriageException $e) {
        expect($e->code)->toBe('connection_error')
            ->and((string) $e)->not->toContain('sk-ant-secret')
            ->and($e->getPrevious())->toBeNull();
    }
});

it('throws not_configured when the key is absent', function () {
    config()->set('feedback-board.ai.anthropic.api_key', null);

    expect(fn () => (new AnthropicDriver())->triage('t', 'd'))
        ->toThrow(TriageException::class, 'not_configured');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Feature/Triage/AnthropicDriverTest.php`
Expected: FAIL — driver not implemented.

- [ ] **Step 3: Implement `AnthropicDriver`**

```php
<?php
// src/Triage/Drivers/AnthropicDriver.php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Triage\Drivers;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;
use YousefAman\FeedbackBoard\Destinations\Support\TransientRetry;
use YousefAman\FeedbackBoard\Enums\FeedbackPriority;
use YousefAman\FeedbackBoard\Enums\FeedbackType;
use YousefAman\FeedbackBoard\Triage\Contracts\TriageDriver;
use YousefAman\FeedbackBoard\Triage\Exceptions\TriageException;
use YousefAman\FeedbackBoard\Triage\Support\TriageError;
use YousefAman\FeedbackBoard\Triage\Support\TriagePrompt;
use YousefAman\FeedbackBoard\Triage\Support\TriageResult;

final class AnthropicDriver implements TriageDriver
{
    public function triage(string $title, ?string $description): TriageResult
    {
        $key = (string) config('feedback-board.ai.anthropic.api_key');
        if ($key === '') {
            throw new TriageException('not_configured');
        }

        $model = (string) config('feedback-board.ai.anthropic.model', 'claude-haiku-4-5');
        $maxTokens = (int) config('feedback-board.ai.anthropic.max_tokens', 512);
        $timeout = (int) config('feedback-board.ai.anthropic.timeout', 30);
        $maxChars = (int) config('feedback-board.ai.max_input_chars', 4000);

        try {
            $resp = Http::withHeaders([
                    'x-api-key' => $key,
                    'anthropic-version' => '2023-06-01',
                ])
                ->timeout($timeout)
                // retry() narrows to connection/429/5xx via TransientRetry; throw:false
                // returns the final response so guardResponse() decides.
                ->retry(2, 200, TransientRetry::when(), throw: false)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $model,
                    'max_tokens' => $maxTokens,
                    'system' => TriagePrompt::system(),
                    'messages' => [[
                        'role' => 'user',
                        'content' => TriagePrompt::user($title, $description, $maxChars),
                    ]],
                    'tools' => [TriagePrompt::tool()],
                    'tool_choice' => ['type' => 'tool', 'name' => 'submit_triage'],
                ]);

            $this->guardResponse($resp);

            return $this->parse($resp, $model);
        } catch (TriageException $e) {
            throw $e; // already sanitized (e.g. invalid_output)
        } catch (Throwable $e) {
            // Convert every transport-layer throwable (whose message/trace can embed
            // the x-api-key header) into a sanitized code with NO previous.
            throw new TriageException(TriageError::code($e));
        }
    }

    /** Surface a non-2xx final response as a RequestException (sanitized by the caller). */
    private function guardResponse(Response $resp): void
    {
        if ($resp->successful()) {
            return;
        }
        $resp->throw();
    }

    private function parse(Response $resp, string $model): TriageResult
    {
        $block = collect((array) $resp->json('content', []))
            ->first(fn ($b) => is_array($b)
                && ($b['type'] ?? null) === 'tool_use'
                && ($b['name'] ?? null) === 'submit_triage');

        $input = is_array($block) ? ($block['input'] ?? null) : null;
        if (! is_array($input)) {
            throw new TriageException('invalid_output');
        }

        $summary = $input['summary'] ?? null;
        if (! is_string($summary) || trim($summary) === '') {
            throw new TriageException('invalid_output');
        }

        return new TriageResult(
            FeedbackType::tryFrom((string) ($input['type'] ?? '')),
            FeedbackPriority::tryFrom((string) ($input['priority'] ?? '')),
            $summary,
            $model,
        );
    }
}
```

- [ ] **Step 4: Run the tests and make sure they pass**

Run: `vendor/bin/pest tests/Feature/Triage/AnthropicDriverTest.php`
Expected: PASS (6 passed).

- [ ] **Step 5: Commit**

```bash
git add src/Triage/Drivers/AnthropicDriver.php tests/Feature/Triage/AnthropicDriverTest.php
git commit -m "feat(triage): Anthropic Messages API driver with sanitized errors and forced tool-use"
```

---

### Task 6: TriageGate — configuration gate

**Files:**
- Create: `src/Triage/Support/TriageGate.php`
- Test: `tests/Unit/TriageGateTest.php`

**Interfaces:**
- Produces: `TriageGate::configured(): bool` — `ai.enabled` true AND, for `anthropic`, a non-empty `api_key`; non-anthropic drivers need only `enabled`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/TriageGateTest.php

use YousefAman\FeedbackBoard\Triage\Support\TriageGate;

it('is false when disabled', function () {
    config()->set('feedback-board.ai.enabled', false);
    expect(TriageGate::configured())->toBeFalse();
});

it('is false when anthropic is selected without a key', function () {
    config()->set('feedback-board.ai.enabled', true);
    config()->set('feedback-board.ai.default', 'anthropic');
    config()->set('feedback-board.ai.anthropic.api_key', null);
    expect(TriageGate::configured())->toBeFalse();
});

it('is true for anthropic with a key', function () {
    config()->set('feedback-board.ai.enabled', true);
    config()->set('feedback-board.ai.default', 'anthropic');
    config()->set('feedback-board.ai.anthropic.api_key', 'sk-ant');
    expect(TriageGate::configured())->toBeTrue();
});

it('is true for the fake driver with no key', function () {
    config()->set('feedback-board.ai.enabled', true);
    config()->set('feedback-board.ai.default', 'fake');
    expect(TriageGate::configured())->toBeTrue();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Unit/TriageGateTest.php`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Create `TriageGate`**

```php
<?php
// src/Triage/Support/TriageGate.php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Triage\Support;

final class TriageGate
{
    public static function configured(): bool
    {
        if (! config('feedback-board.ai.enabled')) {
            return false;
        }

        $driver = (string) config('feedback-board.ai.default', 'anthropic');

        if ($driver === 'anthropic') {
            return (string) config('feedback-board.ai.anthropic.api_key') !== '';
        }

        return true;
    }
}
```

- [ ] **Step 4: Run the tests and make sure they pass**

Run: `vendor/bin/pest tests/Unit/TriageGateTest.php`
Expected: PASS (4 passed).

- [ ] **Step 5: Commit**

```bash
git add src/Triage/Support/TriageGate.php tests/Unit/TriageGateTest.php
git commit -m "feat(triage): TriageGate configuration check"
```

---

### Task 7: TriageFeedbackItem job

**Files:**
- Create: `src/Jobs/TriageFeedbackItem.php`
- Test: `tests/Feature/Triage/TriageFeedbackItemTest.php`

**Interfaces:**
- Consumes: `TriageManager`, `FeedbackItem`, `TriageException`, `TriageError`, `Cache`, `LockTimeoutException`.
- Produces: `TriageFeedbackItem implements ShouldQueue` with `public bool $afterCommit = true; public int $tries = 3;`, constructor `__construct(public int $itemId)`, `backoff(): array`, `handle(TriageManager $manager)`, `failed(Throwable $e)`. Writes `ai_state['triage']` under lock `feedback-board:ai_state:{id}` on store `feedback-board.ai.lock_store`. Resolves the driver from `feedback-board.ai.default`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Triage/TriageFeedbackItemTest.php

use Illuminate\Contracts\Cache\LockTimeoutException;
use YousefAman\FeedbackBoard\Enums\FeedbackPriority;
use YousefAman\FeedbackBoard\Enums\FeedbackType;
use YousefAman\FeedbackBoard\Jobs\TriageFeedbackItem;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Triage\Contracts\TriageDriver;
use YousefAman\FeedbackBoard\Triage\Exceptions\TriageException;
use YousefAman\FeedbackBoard\Triage\Support\TriageResult;
use YousefAman\FeedbackBoard\Triage\TriageManager;

beforeEach(function () {
    config()->set('feedback-board.ai.lock_store', 'array');
    config()->set('feedback-board.ai.default', 'spy');
});

function spyDriver(callable $triage): TriageDriver {
    return new class($triage) implements TriageDriver {
        public function __construct(private $triage) {}
        public function triage(string $title, ?string $description): TriageResult { return ($this->triage)($title, $description); }
    };
}

it('writes a completed triage block via the resolved driver', function () {
    app(TriageManager::class)->extend('spy', fn () => spyDriver(
        fn ($t, $d) => new TriageResult(FeedbackType::Feature, FeedbackPriority::High, 'A summary', 'spy-model')
    ));
    $item = FeedbackItem::factory()->create();

    (new TriageFeedbackItem($item->id))->handle(app(TriageManager::class));

    $triage = $item->refresh()->ai_state['triage'];
    expect($triage['status'])->toBe('completed')
        ->and($triage['suggested_type'])->toBe('feature')
        ->and($triage['suggested_priority'])->toBe('high')
        ->and($triage['summary'])->toBe('A summary')
        ->and($triage['model'])->toBe('spy-model')
        ->and($triage['error_code'])->toBeNull();
});

it('is a no-op for a deleted item', function () {
    app(TriageManager::class)->extend('spy', fn () => spyDriver(fn () => throw new RuntimeException('should not be called')));
    (new TriageFeedbackItem(999999))->handle(app(TriageManager::class));
    expect(true)->toBeTrue(); // no exception
});

it('writes a failed terminal state with a sanitized code', function () {
    $item = FeedbackItem::factory()->create(['external_refs' => null]);

    (new TriageFeedbackItem($item->id))->failed(new TriageException('http_5xx'));

    $triage = $item->refresh()->ai_state['triage'];
    expect($triage['status'])->toBe('failed')
        ->and($triage['error_code'])->toBe('http_5xx')
        ->and($triage['summary'])->toBeNull()
        ->and($triage['suggested_type'])->toBeNull();
});

it('writing ai_state leaves external_refs intact', function () {
    app(TriageManager::class)->extend('spy', fn () => spyDriver(
        fn () => new TriageResult(null, null, 's', 'spy')
    ));
    $item = FeedbackItem::factory()->create();
    $item->external_refs = ['github' => ['issue_number' => 7]];
    $item->save();

    (new TriageFeedbackItem($item->id))->handle(app(TriageManager::class));

    $fresh = $item->refresh();
    expect($fresh->ai_state['triage']['status'])->toBe('completed')
        ->and($fresh->external_refs['github']['issue_number'])->toBe(7);
});

it('writes a terminal failed state for a permanent error straight through handle()', function () {
    // $this->job is null here (direct invocation) — proves we do NOT rely on $this->fail().
    app(TriageManager::class)->extend('spy', fn () => spyDriver(
        fn () => throw new TriageException('auth_401')
    ));
    $item = FeedbackItem::factory()->create();

    (new TriageFeedbackItem($item->id))->handle(app(TriageManager::class));

    $triage = $item->refresh()->ai_state['triage'];
    expect($triage['status'])->toBe('failed')
        ->and($triage['error_code'])->toBe('auth_401');
});

it('rethrows a transient error so the queue can retry', function () {
    app(TriageManager::class)->extend('spy', fn () => spyDriver(
        fn () => throw new TriageException('http_5xx')
    ));
    $item = FeedbackItem::factory()->create();

    expect(fn () => (new TriageFeedbackItem($item->id))->handle(app(TriageManager::class)))
        ->toThrow(TriageException::class, 'http_5xx');
});

it('passes only the title and description to the driver — never reporter PII', function () {
    $seen = null;
    app(TriageManager::class)->extend('spy', fn () => spyDriver(function ($t, $d) use (&$seen) {
        $seen = [$t, $d];
        return new TriageResult(null, null, 's', 'spy');
    }));
    $item = FeedbackItem::factory()->create([
        'title' => 'Visible title',
        'description' => 'Visible description',
        'reporter_name' => 'Secret Reporter',
        'reporter_email' => 'secret@example.com',
    ]);

    (new TriageFeedbackItem($item->id))->handle(app(TriageManager::class));

    expect($seen)->toBe(['Visible title', 'Visible description']);
    // The driver received exactly two strings — the FeedbackItem (and its PII) never entered it.
});
```

> The `spyDriver` closures here capture by reference (`use (&$seen)`) — this is the canonical input-capturing spy referenced in Task 2. Define `spyDriver()` once at the top of the file as shown.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Feature/Triage/TriageFeedbackItemTest.php`
Expected: FAIL — job class does not exist.

- [ ] **Step 3: Create `TriageFeedbackItem`**

```php
<?php
// src/Jobs/TriageFeedbackItem.php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Throwable;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Triage\Exceptions\TriageException;
use YousefAman\FeedbackBoard\Triage\Support\TriageError;
use YousefAman\FeedbackBoard\Triage\TriageManager;

class TriageFeedbackItem implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Never run against an uncommitted/rolled-back row. */
    public bool $afterCommit = true;

    public int $tries = 3;

    public function __construct(public int $itemId) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(TriageManager $manager): void
    {
        $item = FeedbackItem::find($this->itemId);
        if ($item === null) {
            return; // deleted between enqueue and processing — no-op
        }

        try {
            $result = $manager->driver((string) config('feedback-board.ai.default'))
                ->triage($item->title, $item->description); // text only
        } catch (TriageException $e) {
            // Transient → rethrow so the job retries (tries/backoff); failed() writes
            // the terminal state on exhaustion.
            if (in_array($e->code, ['http_429', 'http_5xx', 'connection_error'], true)) {
                throw $e;
            }
            // Permanent → write the terminal failed state NOW. We do not use
            // $this->fail(): InteractsWithQueue::fail() is a no-op when $this->job is
            // null (direct/synchronous invocation), which would leave ai_state stuck
            // 'pending'. Writing directly guarantees the terminal state in every context.
            $this->writeFailed($this->itemId, $e->code);

            return;
        }

        $this->writeTriage($this->itemId, [
            'status' => 'completed',
            'model' => $result->model,
            'suggested_type' => $result->type?->value,
            'suggested_priority' => $result->priority?->value,
            'summary' => $result->summary,
            'generated_at' => now()->toIso8601String(),
            'error_code' => null,
        ]);
    }

    public function failed(Throwable $e): void
    {
        // Reached when a transient error exhausts $tries on a real queue.
        $this->writeFailed($this->itemId, TriageError::code($e));
    }

    /** Write the terminal failed state (best-effort lock; never rethrows a lock timeout). */
    private function writeFailed(int $id, string $code): void
    {
        $this->writeTriage($id, [
            'status' => 'failed',
            'model' => null,
            'suggested_type' => null,
            'suggested_priority' => null,
            'summary' => null,
            'generated_at' => now()->toIso8601String(),
            'error_code' => $code,
        ], swallowLockTimeout: true);
    }

    /**
     * Write ONLY the ai_state column under the per-item triage lock. Cross-column
     * safety comes from (a) only ai_state being dirty and (b) the fresh re-read —
     * not from the lock, which serializes triage-vs-triage writes. In failed() a
     * LockTimeoutException is swallowed so the failure record never crashes the worker.
     *
     * @param array<string, mixed> $triage
     */
    private function writeTriage(int $id, array $triage, bool $swallowLockTimeout = false): void
    {
        $store = config('feedback-board.ai.lock_store');

        try {
            Cache::store($store)->lock('feedback-board:ai_state:'.$id, 10)->block(8, function () use ($id, $triage) {
                $fresh = FeedbackItem::find($id);
                if ($fresh === null) {
                    return;
                }
                $ai = $fresh->ai_state ?? [];
                $ai['triage'] = $triage;
                $fresh->ai_state = $ai;
                $fresh->save();
            });
        } catch (LockTimeoutException $e) {
            if (! $swallowLockTimeout) {
                throw $e;
            }
        }
    }
}
```

- [ ] **Step 4: Run the tests and make sure they pass**

Run: `vendor/bin/pest tests/Feature/Triage/TriageFeedbackItemTest.php`
Expected: PASS (7 passed).

- [ ] **Step 5: Commit**

```bash
git add src/Jobs/TriageFeedbackItem.php tests/Feature/Triage/TriageFeedbackItemTest.php
git commit -m "feat(triage): after-commit job with always-terminal ai_state write"
```

---

### Task 8: TriageDispatcher — pending mark + after-commit enqueue + debounce

**Files:**
- Create: `src/Triage/Support/TriageDispatcher.php`
- Test: `tests/Feature/Triage/TriageDispatcherTest.php`

**Interfaces:**
- Consumes: `FeedbackItem`, `TriageFeedbackItem`.
- Produces: `TriageDispatcher::dispatch(FeedbackItem $item): void` — best-effort debounce (skip if already `pending`), else write `ai_state.triage = ['status'=>'pending','generated_at'=>...]` and dispatch `TriageFeedbackItem`.

> **No lock here, deliberately.** The listener path runs this method *inside* `CreateFeedbackItem`'s open `DB::transaction`. Acquiring a cache lock there is a footgun: with a `database` lock store the lock row joins the transaction (breaking lock semantics) and `block()` holds the transaction open. The pending mark is a benign read-check-write on a brand-new row (no concurrent triage to race), so it needs no lock. The job's **terminal** write keeps its lock (Task 7) — that runs after commit, off-transaction. Debounce is best-effort: the worst case from a lost race is one extra paid API call, which is acceptable.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Triage/TriageDispatcherTest.php

use Illuminate\Support\Facades\Bus;
use YousefAman\FeedbackBoard\Jobs\TriageFeedbackItem;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Triage\Support\TriageDispatcher;

beforeEach(function () {
    config()->set('feedback-board.ai.lock_store', 'array');
});

it('marks pending and enqueues the job', function () {
    Bus::fake();
    $item = FeedbackItem::factory()->create();

    TriageDispatcher::dispatch($item);

    expect($item->refresh()->ai_state['triage']['status'])->toBe('pending');
    Bus::assertDispatched(TriageFeedbackItem::class, fn ($job) => $job->itemId === $item->id);
});

it('debounces a second dispatch while pending', function () {
    Bus::fake();
    $item = FeedbackItem::factory()->create();

    TriageDispatcher::dispatch($item);
    TriageDispatcher::dispatch($item->refresh());

    Bus::assertDispatchedTimes(TriageFeedbackItem::class, 1);
});

it('re-dispatches after a terminal state', function () {
    Bus::fake();
    $item = FeedbackItem::factory()->create();
    $item->ai_state = ['triage' => ['status' => 'completed', 'summary' => 'old']];
    $item->save();

    TriageDispatcher::dispatch($item->refresh());

    expect($item->refresh()->ai_state['triage']['status'])->toBe('pending');
    Bus::assertDispatchedTimes(TriageFeedbackItem::class, 1);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Feature/Triage/TriageDispatcherTest.php`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Create `TriageDispatcher`**

```php
<?php
// src/Triage/Support/TriageDispatcher.php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Triage\Support;

use YousefAman\FeedbackBoard\Jobs\TriageFeedbackItem;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

final class TriageDispatcher
{
    /**
     * Mark the item pending (best-effort debounce) and enqueue the after-commit job.
     * No lock: this may run inside the item-creation transaction, where a cache lock
     * would be a footgun. The pending write is a simple read-check-write on a fresh
     * row; the job's terminal write (off-transaction) is the one that locks.
     */
    public static function dispatch(FeedbackItem $item): void
    {
        $fresh = FeedbackItem::find($item->id);
        if ($fresh === null) {
            return;
        }

        $triage = $fresh->ai_state['triage'] ?? null;
        if (is_array($triage) && ($triage['status'] ?? null) === 'pending') {
            return; // debounce — a run is already in flight
        }

        $ai = $fresh->ai_state ?? [];
        $ai['triage'] = ['status' => 'pending', 'generated_at' => now()->toIso8601String()];
        $fresh->ai_state = $ai;
        $fresh->save();

        TriageFeedbackItem::dispatch($item->id); // $afterCommit = true on the job
    }
}
```

- [ ] **Step 4: Run the tests and make sure they pass**

Run: `vendor/bin/pest tests/Feature/Triage/TriageDispatcherTest.php`
Expected: PASS (3 passed).

- [ ] **Step 5: Commit**

```bash
git add src/Triage/Support/TriageDispatcher.php tests/Feature/Triage/TriageDispatcherTest.php
git commit -m "feat(triage): dispatcher marks pending, debounces, enqueues after commit"
```

---

### Task 9: Listener + service-provider wiring

**Files:**
- Create: `src/Listeners/TriageNewFeedback.php`
- Modify: `src/FeedbackBoardServiceProvider.php` (`packageBooted()`: subscribe the listener)
- Test: `tests/Feature/Triage/TriageListenerTest.php`

**Interfaces:**
- Consumes: `FeedbackItemCreated`, `TriageGate`, `TriageDispatcher`, `Dispatcher`.
- Produces: `TriageNewFeedback` (NOT `ShouldQueue`) with `handle(FeedbackItemCreated $event)` and `subscribe(Dispatcher $events): array`. Subscribed in `packageBooted()`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Triage/TriageListenerTest.php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use YousefAman\FeedbackBoard\Actions\CreateFeedbackItem;
use YousefAman\FeedbackBoard\Jobs\TriageFeedbackItem;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Triage\Contracts\TriageDriver;
use YousefAman\FeedbackBoard\Triage\Support\TriageResult;
use YousefAman\FeedbackBoard\Triage\TriageManager;

it('dispatches triage on creation when configured', function () {
    config()->set('feedback-board.ai.enabled', true);
    config()->set('feedback-board.ai.default', 'fake');
    Bus::fake();

    (new CreateFeedbackItem())->handle(['title' => 'Broken'], null);

    Bus::assertDispatched(TriageFeedbackItem::class);
});

it('is inert when disabled', function () {
    config()->set('feedback-board.ai.enabled', false);
    Bus::fake();

    (new CreateFeedbackItem())->handle(['title' => 'Broken'], null);

    Bus::assertNotDispatched(TriageFeedbackItem::class);
});

it('is inert when anthropic is enabled without a key', function () {
    config()->set('feedback-board.ai.enabled', true);
    config()->set('feedback-board.ai.default', 'anthropic');
    config()->set('feedback-board.ai.anthropic.api_key', null);
    Bus::fake();

    (new CreateFeedbackItem())->handle(['title' => 'Broken'], null);

    Bus::assertNotDispatched(TriageFeedbackItem::class);
});

it('marks the new item pending during creation', function () {
    config()->set('feedback-board.ai.enabled', true);
    config()->set('feedback-board.ai.default', 'fake');
    Bus::fake(); // intercept the after-commit job so the pending mark is observable

    $item = (new CreateFeedbackItem())->handle(['title' => 'Broken'], null);

    // The synchronous in-transaction dispatcher wrote pending (proves the listener path ran).
    expect($item->refresh()->ai_state['triage']['status'])->toBe('pending');
});

it('runs end-to-end to a terminal completed state on the sync queue (after commit)', function () {
    config()->set('queue.default', 'sync');
    config()->set('feedback-board.ai.enabled', true);
    config()->set('feedback-board.ai.default', 'fake');
    // NO Bus::fake — the after-commit job must actually run once the transaction commits.

    $item = (new CreateFeedbackItem())->handle(['title' => 'Login broken'], null);

    expect($item->refresh()->ai_state['triage']['status'])->toBe('completed')
        ->and($item->ai_state['triage']['summary'])->toBe('Login broken'); // FakeTriageDriver summary
});

it('does not triage when the surrounding transaction rolls back (after-commit guarantee)', function () {
    config()->set('queue.default', 'sync');
    config()->set('feedback-board.ai.enabled', true);
    config()->set('feedback-board.ai.default', 'spy');

    $spy = new class implements TriageDriver {
        public bool $called = false;
        public function triage(string $title, ?string $description): TriageResult {
            $this->called = true;
            return new TriageResult(null, null, 's', 'spy');
        }
    };
    app(TriageManager::class)->extend('spy', fn () => $spy); // same instance every resolution

    try {
        DB::transaction(function () {
            (new CreateFeedbackItem())->handle(['title' => 'Rolled back'], null);
            throw new RuntimeException('force rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(FeedbackItem::count())->toBe(0)  // item rolled back
        ->and($spy->called)->toBeFalse();   // after-commit job never released → driver never ran
});
```

> The spy is a single captured instance (the `extend` closure returns the same `$spy` every time), so reading `$spy->called` after the run needs no references.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Feature/Triage/TriageListenerTest.php`
Expected: FAIL — listener not registered.

- [ ] **Step 3: Create `TriageNewFeedback`**

```php
<?php
// src/Listeners/TriageNewFeedback.php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Listeners;

use Illuminate\Events\Dispatcher;
use YousefAman\FeedbackBoard\Events\FeedbackItemCreated;
use YousefAman\FeedbackBoard\Triage\Support\TriageDispatcher;
use YousefAman\FeedbackBoard\Triage\Support\TriageGate;

/**
 * Synchronous listener (intentionally NOT ShouldQueue — the only queued unit is
 * TriageFeedbackItem, which carries the scalar id). Dispatches nothing unless
 * triage is fully configured, so enabling AI without a key marks nothing failed.
 */
class TriageNewFeedback
{
    public function handle(FeedbackItemCreated $event): void
    {
        if (! TriageGate::configured()) {
            return;
        }
        TriageDispatcher::dispatch($event->item);
    }

    public function subscribe(Dispatcher $events): array
    {
        return [FeedbackItemCreated::class => 'handle'];
    }
}
```

- [ ] **Step 4: Subscribe the listener**

In `src/FeedbackBoardServiceProvider.php`, inside `packageBooted()`, add below the existing `DispatchToDestinations` subscribe line:

```php
        \Illuminate\Support\Facades\Event::subscribe(\YousefAman\FeedbackBoard\Listeners\TriageNewFeedback::class);
```

- [ ] **Step 5: Run the tests and make sure they pass**

Run: `vendor/bin/pest tests/Feature/Triage/TriageListenerTest.php`
Expected: PASS (6 passed).

- [ ] **Step 6: Commit**

```bash
git add src/Listeners/TriageNewFeedback.php src/FeedbackBoardServiceProvider.php tests/Feature/Triage/TriageListenerTest.php
git commit -m "feat(triage): listener fan-in on FeedbackItemCreated, gated by configuration"
```

---

### Task 10: Manual trigger + apply actions

**Files:**
- Create: `src/Actions/RequestTriage.php`
- Create: `src/Actions/ApplyTriageSuggestion.php`
- Test: `tests/Feature/Triage/TriageActionsTest.php`

**Interfaces:**
- Consumes: `FeedbackItemPolicy::isDeveloper(?Model)`, `TriageGate`, `TriageDispatcher`, `FeedbackType`/`FeedbackPriority`.
- Produces:
  - `RequestTriage::handle(FeedbackItem $item, ?Model $actor): void` — 403 non-developer, 422 unconfigured, else dispatch.
  - `ApplyTriageSuggestion::handle(FeedbackItem $item, ?Model $actor, array $fields): void` — 403 non-developer; 422 unless a `completed` triage exists; applies whitelisted `type`/`priority` (validated via `tryFrom`) to canonical columns.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Triage/TriageActionsTest.php

use Illuminate\Support\Facades\Bus;
use Symfony\Component\HttpKernel\Exception\HttpException;
use YousefAman\FeedbackBoard\Actions\ApplyTriageSuggestion;
use YousefAman\FeedbackBoard\Actions\RequestTriage;
use YousefAman\FeedbackBoard\Enums\FeedbackPriority;
use YousefAman\FeedbackBoard\Enums\FeedbackType;
use YousefAman\FeedbackBoard\Jobs\TriageFeedbackItem;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    config()->set('feedback-board.ai.enabled', true);
    config()->set('feedback-board.ai.default', 'fake');
    // Mirror the repo convention (tests/Feature/AuthorizationTest.php): the users table
    // in TestCase has only id/name/email, so authorize by name — NOT a non-existent column.
    config()->set('feedback-board.authorization.developer_resolver', fn ($u) => ($u->name ?? null) === 'Dev');
});

function developer(): TestUser { return TestUser::create(['name' => 'Dev']); }
function regular(): TestUser { return TestUser::create(['name' => 'User']); }

it('RequestTriage forbids non-developers', function () {
    $item = FeedbackItem::factory()->create();
    expect(fn () => (new RequestTriage())->handle($item, regular()))
        ->toThrow(HttpException::class);
});

it('RequestTriage 422s when unconfigured', function () {
    config()->set('feedback-board.ai.enabled', false);
    Bus::fake();
    $item = FeedbackItem::factory()->create();

    try {
        (new RequestTriage())->handle($item, developer());
        $this->fail('expected 422');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(422);
    }
    Bus::assertNotDispatched(TriageFeedbackItem::class);
});

it('RequestTriage dispatches for a configured developer', function () {
    Bus::fake();
    $item = FeedbackItem::factory()->create();

    (new RequestTriage())->handle($item, developer());

    Bus::assertDispatched(TriageFeedbackItem::class);
});

it('ApplyTriageSuggestion applies whitelisted suggestions', function () {
    $item = FeedbackItem::factory()->create(['type' => FeedbackType::Other, 'priority' => FeedbackPriority::Low]);
    $item->ai_state = ['triage' => [
        'status' => 'completed',
        'suggested_type' => 'feature',
        'suggested_priority' => 'high',
        'summary' => 's', 'model' => 'fake',
    ]];
    $item->save();

    (new ApplyTriageSuggestion())->handle($item, developer(), ['type', 'priority']);

    $fresh = $item->refresh();
    expect($fresh->type)->toBe(FeedbackType::Feature)
        ->and($fresh->priority)->toBe(FeedbackPriority::High);
});

it('ApplyTriageSuggestion ignores non-whitelisted fields and invalid values', function () {
    $item = FeedbackItem::factory()->create(['type' => FeedbackType::Bug, 'status' => \YousefAman\FeedbackBoard\Enums\FeedbackStatus::New]);
    $item->ai_state = ['triage' => [
        'status' => 'completed',
        'suggested_type' => 'not-a-type',
        'summary' => 's', 'model' => 'fake',
    ]];
    $item->save();

    (new ApplyTriageSuggestion())->handle($item, developer(), ['type', 'status']);

    $fresh = $item->refresh();
    expect($fresh->type)->toBe(FeedbackType::Bug) // invalid suggestion ignored
        ->and($fresh->status)->toBe(\YousefAman\FeedbackBoard\Enums\FeedbackStatus::New); // status not whitelisted
});

it('ApplyTriageSuggestion 422s without a completed triage', function () {
    $item = FeedbackItem::factory()->create();
    $item->ai_state = ['triage' => ['status' => 'pending']];
    $item->save();

    try {
        (new ApplyTriageSuggestion())->handle($item, developer(), ['type']);
        $this->fail('expected 422');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(422);
    }
});
```

> The `users` table created in `tests/TestCase.php` has only `id`, `name`, `email`. Authorizing by `name === 'Dev'` matches the existing repo tests (e.g. `tests/Feature/AuthorizationTest.php`); inserting an `is_admin` column would throw `table users has no column named is_admin` on SQLite. `RequestTriage`/`ApplyTriageSuggestion` use `abort()`, which surfaces as a `Symfony\Component\HttpKernel\Exception\HttpException` here.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Feature/Triage/TriageActionsTest.php`
Expected: FAIL — action classes do not exist.

- [ ] **Step 3: Create `RequestTriage`**

```php
<?php
// src/Actions/RequestTriage.php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Actions;

use Illuminate\Database\Eloquent\Model;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy;
use YousefAman\FeedbackBoard\Triage\Support\TriageDispatcher;
use YousefAman\FeedbackBoard\Triage\Support\TriageGate;

class RequestTriage
{
    public function handle(FeedbackItem $item, ?Model $actor): void
    {
        abort_unless(FeedbackItemPolicy::isDeveloper($actor), 403);
        abort_unless(TriageGate::configured(), 422);

        TriageDispatcher::dispatch($item);
    }
}
```

- [ ] **Step 4: Create `ApplyTriageSuggestion`**

```php
<?php
// src/Actions/ApplyTriageSuggestion.php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Actions;

use Illuminate\Database\Eloquent\Model;
use YousefAman\FeedbackBoard\Enums\FeedbackPriority;
use YousefAman\FeedbackBoard\Enums\FeedbackType;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy;

class ApplyTriageSuggestion
{
    /**
     * Apply a suggestion to canonical columns. Whitelists type/priority and only
     * applies values that pass tryFrom. Writes type/priority only — no ai_state —
     * so it is disjoint from concurrent triage writes (no lock needed).
     *
     * @param array<int, string> $fields  subset of ['type', 'priority']
     */
    public function handle(FeedbackItem $item, ?Model $actor, array $fields): void
    {
        abort_unless(FeedbackItemPolicy::isDeveloper($actor), 403);

        $fresh = FeedbackItem::findOrFail($item->id);
        $triage = $fresh->ai_state['triage'] ?? null;
        abort_unless(is_array($triage) && ($triage['status'] ?? null) === 'completed', 422);

        $update = [];
        foreach (array_intersect($fields, ['type', 'priority']) as $field) {
            $suggested = (string) ($triage["suggested_{$field}"] ?? '');
            $enum = $field === 'type'
                ? FeedbackType::tryFrom($suggested)
                : FeedbackPriority::tryFrom($suggested);
            if ($enum !== null) {
                $update[$field] = $enum;
            }
        }

        if ($update !== []) {
            $fresh->forceFill($update)->save();
        }
    }
}
```

- [ ] **Step 5: Run the tests and make sure they pass**

Run: `vendor/bin/pest tests/Feature/Triage/TriageActionsTest.php`
Expected: PASS (6 passed).

- [ ] **Step 6: Commit**

```bash
git add src/Actions/RequestTriage.php src/Actions/ApplyTriageSuggestion.php tests/Feature/Triage/TriageActionsTest.php
git commit -m "feat(triage): hard-authorized manual trigger and per-field apply actions"
```

---

### Task 11: Translation strings (en + ar)

**Files:**
- Modify: `resources/lang/en/feedback-board.php` (add a `triage` block)
- Modify: `resources/lang/ar/feedback-board.php` (add a `triage` block)
- Test: `tests/Feature/Triage/TriageLangTest.php`

**Interfaces:**
- Produces: `feedback-board::feedback-board.triage.*` keys used by the Livewire and Filament UIs.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Triage/TriageLangTest.php

it('exposes triage UI strings in both locales', function () {
    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);
        foreach (['analyzing', 'failed', 'summary', 'apply_type', 'apply_priority', 'triage', 'retriage', 'view_suggestion'] as $key) {
            expect(__("feedback-board::feedback-board.triage.{$key}"))
                ->not->toBe("feedback-board::feedback-board.triage.{$key}");
        }
    }
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Feature/Triage/TriageLangTest.php`
Expected: FAIL — `triage` block missing.

- [ ] **Step 3: Add the `triage` block to `resources/lang/en/feedback-board.php`** (before the closing `];`, after the `destinations` block)

```php
    'triage' => [
        'analyzing' => 'AI is analyzing…',
        'failed' => 'AI analysis failed',
        'summary' => 'AI summary',
        'suggested_type' => 'Suggested type',
        'suggested_priority' => 'Suggested priority',
        'apply_type' => 'Apply type',
        'apply_priority' => 'Apply priority',
        'triage' => 'Triage with AI',
        'retriage' => 'Re-run AI triage',
        'view_suggestion' => 'View AI suggestion',
    ],
```

- [ ] **Step 4: Add the `triage` block to `resources/lang/ar/feedback-board.php`** (matching placement)

```php
    'triage' => [
        'analyzing' => 'يحلّل الذكاء الاصطناعي…',
        'failed' => 'فشل تحليل الذكاء الاصطناعي',
        'summary' => 'ملخّص الذكاء الاصطناعي',
        'suggested_type' => 'النوع المقترح',
        'suggested_priority' => 'الأولوية المقترحة',
        'apply_type' => 'تطبيق النوع',
        'apply_priority' => 'تطبيق الأولوية',
        'triage' => 'تصنيف بالذكاء الاصطناعي',
        'retriage' => 'إعادة التصنيف',
        'view_suggestion' => 'عرض اقتراح الذكاء الاصطناعي',
    ],
```

- [ ] **Step 5: Run the tests and make sure they pass**

Run: `vendor/bin/pest tests/Feature/Triage/TriageLangTest.php`
Expected: PASS (1 passed).

- [ ] **Step 6: Commit**

```bash
git add resources/lang/en/feedback-board.php resources/lang/ar/feedback-board.php tests/Feature/Triage/TriageLangTest.php
git commit -m "feat(triage): en/ar UI strings"
```

---

### Task 12: Livewire FeedbackDetail integration

**Files:**
- Modify: `src/Livewire/FeedbackDetail.php` (add `requestTriage`, `applyTriage` methods)
- Modify: `resources/views/livewire/feedback-detail.blade.php` (add the triage panel)
- Test: `tests/Feature/Triage/TriageLivewireTest.php`

**Interfaces:**
- Consumes: `RequestTriage`, `ApplyTriageSuggestion`.
- Produces: Livewire methods `requestTriage(RequestTriage $action)` and `applyTriage(string $field, ApplyTriageSuggestion $action)`; a Blade panel that branches on `ai_state['triage']['status']`, renders the **escaped** summary, and shows per-field apply buttons only when the suggestion differs from the live column.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Triage/TriageLivewireTest.php

use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use YousefAman\FeedbackBoard\Enums\FeedbackPriority;
use YousefAman\FeedbackBoard\Enums\FeedbackType;
use YousefAman\FeedbackBoard\Jobs\TriageFeedbackItem;
use YousefAman\FeedbackBoard\Livewire\FeedbackDetail;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    config()->set('feedback-board.ai.enabled', true);
    config()->set('feedback-board.ai.default', 'fake');
    config()->set('feedback-board.authorization.developer_resolver', fn ($u) => ($u->name ?? null) === 'Dev');
});

it('requestTriage dispatches the job for a developer', function () {
    Bus::fake();
    $dev = TestUser::create(['name' => 'Dev']);
    $item = FeedbackItem::factory()->create();

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->call('requestTriage');

    Bus::assertDispatched(TriageFeedbackItem::class);
});

it('applyTriage sets the canonical column and then hides the apply button', function () {
    $dev = TestUser::create(['name' => 'Dev']);
    $item = FeedbackItem::factory()->create(['priority' => FeedbackPriority::Low]);
    $item->ai_state = ['triage' => ['status' => 'completed', 'suggested_priority' => 'urgent', 'summary' => 's', 'model' => 'fake']];
    $item->save();

    $component = Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->assertSee(__('feedback-board::feedback-board.triage.apply_priority')) // shown: suggestion differs
        ->call('applyTriage', 'priority');

    // Canonical column updated, and the difference-gated button is now gone (suggestion == column).
    expect($item->refresh()->priority)->toBe(FeedbackPriority::Urgent);
    $component->assertDontSee(__('feedback-board::feedback-board.triage.apply_priority'));
});

it('escapes a malicious summary in the rendered panel', function () {
    $dev = TestUser::create(['name' => 'Dev']);
    $item = FeedbackItem::factory()->create();
    $item->ai_state = ['triage' => ['status' => 'completed', 'suggested_type' => 'bug', 'summary' => '<script>alert(1)</script>', 'model' => 'fake']];
    $item->save();

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->assertDontSee('<script>alert(1)</script>', false)   // raw markup absent
        ->assertSee('alert(1)');                              // escaped text present
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Feature/Triage/TriageLivewireTest.php`
Expected: FAIL — methods/panel do not exist.

- [ ] **Step 3: Add the methods to `src/Livewire/FeedbackDetail.php`**

Add these two methods to the `FeedbackDetail` class (next to `sendToGithub`). Use the existing import style (the class already imports `Model` indirectly via `auth()->user()` usage):

```php
    public function requestTriage(\YousefAman\FeedbackBoard\Actions\RequestTriage $action): void
    {
        $action->handle($this->item, auth()->user());
        $this->item->refresh();
        $this->dispatch('feedback-triage-requested');
    }

    public function applyTriage(string $field, \YousefAman\FeedbackBoard\Actions\ApplyTriageSuggestion $action): void
    {
        $action->handle($this->item, auth()->user(), [$field]);
        $this->item->refresh();
    }
```

- [ ] **Step 4: Add the triage panel to `resources/views/livewire/feedback-detail.blade.php`**

Insert this panel where the detail body renders (e.g. above the thread). It branches on status and escapes all model output with `{{ }}`:

```blade
@php($triage = $item->ai_state['triage'] ?? null)
@if ($triage)
    <div class="fb-triage" wire:key="triage-{{ $item->id }}"
         @if(($triage['status'] ?? null) === 'pending') wire:poll.5s @endif>
        @if (($triage['status'] ?? null) === 'pending')
            <p>{{ __('feedback-board::feedback-board.triage.analyzing') }}</p>
        @elseif (($triage['status'] ?? null) === 'failed')
            <p>{{ __('feedback-board::feedback-board.triage.failed') }}</p>
            <button type="button" wire:click="requestTriage">{{ __('feedback-board::feedback-board.triage.retriage') }}</button>
        @elseif (($triage['status'] ?? null) === 'completed')
            <p><strong>{{ __('feedback-board::feedback-board.triage.summary') }}:</strong> {{ $triage['summary'] }}</p>

            @if (($triage['suggested_type'] ?? null) && $triage['suggested_type'] !== ($item->type?->value))
                <button type="button" wire:click="applyTriage('type')">
                    {{ __('feedback-board::feedback-board.triage.apply_type') }}: {{ $triage['suggested_type'] }}
                </button>
            @endif

            @if (($triage['suggested_priority'] ?? null) && $triage['suggested_priority'] !== ($item->priority?->value))
                <button type="button" wire:click="applyTriage('priority')">
                    {{ __('feedback-board::feedback-board.triage.apply_priority') }}: {{ $triage['suggested_priority'] }}
                </button>
            @endif

            <button type="button" wire:click="requestTriage">{{ __('feedback-board::feedback-board.triage.retriage') }}</button>
        @endif
    </div>
@endif
```

- [ ] **Step 5: Run the tests and make sure they pass**

Run: `vendor/bin/pest tests/Feature/Triage/TriageLivewireTest.php`
Expected: PASS (3 passed).

- [ ] **Step 6: Commit**

```bash
git add src/Livewire/FeedbackDetail.php resources/views/livewire/feedback-detail.blade.php tests/Feature/Triage/TriageLivewireTest.php
git commit -m "feat(triage): Livewire detail panel with poll, escaped summary, difference-gated apply"
```

---

### Task 13: Filament resource integration

**Files:**
- Create: `resources/views/filament/triage-suggestion.blade.php` (escaped modal body)
- Modify: `src/Filament/Resources/FeedbackItemResource.php` (add triage record actions)
- Test: `tests/Feature/Triage/TriageFilamentTest.php`

**Interfaces:**
- Consumes: `RequestTriage`, `ApplyTriageSuggestion`, `TriageGate`.
- Produces: `recordActions` entries `triage` (→ `RequestTriage`), `applyType` and `applyPriority` (→ `ApplyTriageSuggestion` with a fixed single-element `$fields`), and a `viewSuggestion` modal action whose body is the `feedback-board::filament.triage-suggestion` view (auto-escaped). UI `->visible(...)` gates for UX; hard auth lives inside the shared actions (proven in Task 10).

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Triage/TriageFilamentTest.php

use YousefAman\FeedbackBoard\Filament\Resources\FeedbackItemResource;

it('registers the triage record actions on the resource table', function () {
    if (! class_exists(\Filament\Actions\Action::class)) {
        $this->markTestSkipped('Filament not installed');
    }

    // Table::make() requires a HasTable livewire instance. A bare Component throws a
    // TypeError, so the double implements HasTable via InteractsWithTable. We never
    // mount/render — table() only configures the Table object, so the trait methods
    // are not invoked.
    $livewire = new class extends \Livewire\Component implements \Filament\Tables\Contracts\HasTable {
        use \Filament\Tables\Concerns\InteractsWithTable;
        public function render() { return view('feedback-board::filament.triage-suggestion', ['triage' => []]); }
    };

    $table = FeedbackItemResource::table(\Filament\Tables\Table::make($livewire));
    $names = array_map(fn ($a) => $a->getName(), $table->getRecordActions());

    expect($names)->toContain('triage')
        ->and($names)->toContain('applyType')
        ->and($names)->toContain('applyPriority')
        ->and($names)->toContain('viewSuggestion');
});

it('renders the suggestion modal body with the summary HTML-escaped', function () {
    $html = view('feedback-board::filament.triage-suggestion', [
        'triage' => [
            'status' => 'completed',
            'suggested_type' => 'bug',
            'suggested_priority' => 'high',
            'summary' => '<script>alert(1)</script>',
        ],
    ])->render();

    expect($html)->not->toContain('<script>alert(1)</script>') // raw markup escaped away
        ->and($html)->toContain('alert(1)');                   // escaped text present
});
```

> If `Table::make()` is still awkward in your Filament point release, fall back to a reflection/source assertion that `table()` defines the four action names — but the `HasTable` double above is the version-robust path and should be tried first. The escaping test stands on its own (pure `view()->render()`, no Filament internals) and covers the spec's "both UI layers render escaped" requirement for this layer.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Feature/Triage/TriageFilamentTest.php`
Expected: FAIL — actions not present.

- [ ] **Step 3: Create the escaped modal partial** `resources/views/filament/triage-suggestion.blade.php`

```blade
<div class="fb-triage-suggestion">
    @if (($triage['summary'] ?? null) !== null)
        <p><strong>{{ __('feedback-board::feedback-board.triage.summary') }}:</strong> {{ $triage['summary'] }}</p>
    @endif
    @if (($triage['suggested_type'] ?? null) !== null)
        <p>{{ __('feedback-board::feedback-board.triage.suggested_type') }}: {{ $triage['suggested_type'] }}</p>
    @endif
    @if (($triage['suggested_priority'] ?? null) !== null)
        <p>{{ __('feedback-board::feedback-board.triage.suggested_priority') }}: {{ $triage['suggested_priority'] }}</p>
    @endif
</div>
```

All values use `{{ }}` (auto-escaped). The package already registers this view namespace via `->hasViews()` in the service provider, so `feedback-board::filament.triage-suggestion` resolves with no extra wiring.

- [ ] **Step 4: Add the record actions** in `src/Filament/Resources/FeedbackItemResource.php`

Add the imports:

```php
use YousefAman\FeedbackBoard\Actions\ApplyTriageSuggestion;
use YousefAman\FeedbackBoard\Actions\RequestTriage;
use YousefAman\FeedbackBoard\Triage\Support\TriageGate;
```

Extend the `->recordActions([...])` array (after the existing `sendToGithub` action) with:

```php
            Action::make('triage')
                ->label(__('feedback-board::feedback-board.triage.triage'))
                ->visible(fn (): bool => FeedbackItemPolicy::isDeveloper(auth()->user()) && TriageGate::configured())
                ->action(fn (FeedbackItem $record) =>
                    app(RequestTriage::class)->handle($record, auth()->user())),

            Action::make('applyType')
                ->label(__('feedback-board::feedback-board.triage.apply_type'))
                ->visible(fn (FeedbackItem $record): bool =>
                    FeedbackItemPolicy::isDeveloper(auth()->user())
                    && ($record->ai_state['triage']['status'] ?? null) === 'completed'
                    && ($t = $record->ai_state['triage']['suggested_type'] ?? null) !== null
                    && $t !== $record->type?->value)
                ->action(fn (FeedbackItem $record) =>
                    app(ApplyTriageSuggestion::class)->handle($record, auth()->user(), ['type'])),

            Action::make('applyPriority')
                ->label(__('feedback-board::feedback-board.triage.apply_priority'))
                ->visible(fn (FeedbackItem $record): bool =>
                    FeedbackItemPolicy::isDeveloper(auth()->user())
                    && ($record->ai_state['triage']['status'] ?? null) === 'completed'
                    && ($p = $record->ai_state['triage']['suggested_priority'] ?? null) !== null
                    && $p !== $record->priority?->value)
                ->action(fn (FeedbackItem $record) =>
                    app(ApplyTriageSuggestion::class)->handle($record, auth()->user(), ['priority'])),

            Action::make('viewSuggestion')
                ->label(__('feedback-board::feedback-board.triage.view_suggestion'))
                ->visible(fn (FeedbackItem $record): bool =>
                    FeedbackItemPolicy::isDeveloper(auth()->user())
                    && ($record->ai_state['triage']['status'] ?? null) === 'completed')
                ->modalContent(fn (FeedbackItem $record) => view(
                    'feedback-board::filament.triage-suggestion',
                    ['triage' => $record->ai_state['triage'] ?? []],
                ))
                ->modalSubmitAction(false),
```

> The `viewSuggestion` modal body is a Blade view that renders `summary` with `{{ }}` (auto-escaped). Never use `->html()`/`->markdown()` on this field.

- [ ] **Step 5: Run the tests and make sure they pass**

Run: `vendor/bin/pest tests/Feature/Triage/TriageFilamentTest.php`
Expected: PASS (2 passed). The action-presence test is skipped only if Filament is genuinely absent; the escaping test always runs.

- [ ] **Step 6: Run the full suite**

Run: `vendor/bin/pest`
Expected: PASS — entire suite green.

- [ ] **Step 7: Commit**

```bash
git add src/Filament/Resources/FeedbackItemResource.php resources/views/filament/triage-suggestion.blade.php tests/Feature/Triage/TriageFilamentTest.php
git commit -m "feat(triage): Filament record actions for triage, per-field apply, escaped suggestion modal"
```

---

### Task 14: README documentation

**Files:**
- Modify: `README.md` (add an "AI Triage" section)
- Test: none (documentation) — verified by a manual read.

**Interfaces:**
- Produces: operator-facing docs covering opt-in, what data leaves the app, provider/model/key setup, and the cost note.

- [ ] **Step 1: Add an "AI Triage (Level A)" section to `README.md`**

```markdown
## AI Triage (Level A)

Optional, in-app AI triage that suggests a **type** and **priority** and a short
**summary** for each new report. Suggestions are stored on the item; a developer
reviews and applies them — the AI never changes a report's fields on its own.

**Opt-in.** Disabled by default. Enable it and configure a provider:

```env
FEEDBACK_BOARD_AI_ENABLED=true
FEEDBACK_BOARD_AI_DRIVER=anthropic
FEEDBACK_BOARD_ANTHROPIC_KEY=sk-ant-...
# optional:
FEEDBACK_BOARD_ANTHROPIC_MODEL=claude-haiku-4-5
FEEDBACK_BOARD_AI_QUEUE=
FEEDBACK_BOARD_AI_LOCK_STORE=redis
```

When AI is enabled but its driver is unconfigured (e.g. no API key), triage stays
inert — nothing is dispatched and the manual button returns 422.

**What leaves your app.** Only the report's **title and description** are sent to
the model, jointly truncated to `ai.max_input_chars` (default 4000) to bound cost.
Reporter identity, email, attachments, and all internal fields are never sent. The
API key is read from the environment, never logged, and never stored; failures
record only a short sanitized error code.

**Triggering.** Triage runs automatically on new reports (after the creating
transaction commits) and can be re-run from the "Triage with AI" button in the
Livewire detail panel or the Filament resource.

**Lock store.** Like destinations, the per-item `ai_state` lock needs an atomic
cache store (`redis`/`memcached`/`database`/`dynamodb`). Set
`FEEDBACK_BOARD_AI_LOCK_STORE` if your default cache is `file`.
```

- [ ] **Step 2: Verify the full suite is still green**

Run: `vendor/bin/pest`
Expected: PASS — entire suite green.

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "docs(triage): AI Triage configuration, privacy, and provider setup"
```

---

## Self-Review

**1. Spec coverage:**
- Section 1 (architecture/components) → Tasks 2–13 create every listed component.
- Section 2 (`ai_state` schema, pending/completed/failed, no `applied`, difference-gating, lock rationale) → Tasks 7, 8, 12, 13.
- Section 3 (contract/manager/fake, spy substitution) → Task 2; spy pattern used in Tasks 7+.
- Section 4 (Anthropic body, forced tool, post-retry guard, sanitized errors, not_configured) → Task 5.
- Section 5 (listener not-ShouldQueue, gate, dispatcher, after-commit, throw-based terminal retry) → Tasks 6–9. After-commit is proven by Task 9's sync end-to-end test and rollback test (run without `Bus::fake`); the permanent-error terminal state by Task 7's "straight through handle()" test (does not rely on `$this->fail()`).
- Section 6 (RequestTriage/ApplyTriageSuggestion, per-field, difference-gated UI, Filament actions not Infolist) → Tasks 10, 12, 13. Difference-gating proven by Task 12's "hides the apply button" assertion.
- Section 7 (config block) → Task 1.
- Section 8 (security: server-authored columns, escaping, key safety, hard auth, mass-assignment) → Tasks 1, 3, 5, 10, 12, 13. Data minimization is proven at the real boundary by Task 7's "passes only title and description" test; key-safety by Task 5's 401 + connection-error key-leak tests; escaping in both UI layers by Task 12 (Livewire) and Task 13 (Filament view render); mass-assignment by Task 1's positive-control test.
- Section 9 (tests) → each task’s test file; key-leak, escaping (both layers), after-commit + rollback, data minimization, and mass-assignment are all covered by concrete tests (not assertion-free placeholders).

**2. Placeholder scan:** No "TBD"/"handle edge cases"/"similar to". The only "stub" note is the Task 2 `AnthropicDriver` reference, resolved concretely in Task 5; an explicit instruction handles eager-loading tooling.

**3. Type consistency:** `triage(string, ?string): TriageResult`, `TriageResult(?FeedbackType, ?FeedbackPriority, string, string)`, `TriageError::code(Throwable): string`, `TriageGate::configured(): bool`, `TriageDispatcher::dispatch(FeedbackItem): void`, `RequestTriage::handle(FeedbackItem, ?Model): void`, `ApplyTriageSuggestion::handle(FeedbackItem, ?Model, array): void`, job `__construct(int $itemId)` — consistent across all tasks. Only the job (Task 7) locks `feedback-board:ai_state:{id}` on `feedback-board.ai.lock_store` for its terminal write; the dispatcher (Task 8) is intentionally lock-free because it may run inside the create transaction.

**Open implementation note (not a blocker):** the default model id `claude-haiku-4-5` is configurable; confirm the exact current Messages API id at implementation time (tests use `Http::fake`, so they are id-agnostic).
