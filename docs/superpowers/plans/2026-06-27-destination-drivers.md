# Destination Drivers (Phase 2-A) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add outbound destination drivers (Webhook, GitHub Issues, Slack) that mirror feedback events to external systems, with a hybrid trigger (auto for webhook/slack, manual-create for GitHub) and per-destination failure isolation.

**Architecture:** A thin synchronous listener (`DispatchToDestinations`) is the sole event-name filter; it enqueues one `DeliverToDestination` job per `(event, driver, deliveryId)`. Each job resolves a driver from `DestinationManager` and calls `send($item, $event, $deliveryId)`. Drivers are pure (no queue coupling); they signal rate-limit/contention backoff by throwing `RetryAfter`, which the job translates to `release()`. GitHub creation is idempotent via an atomic per-item cache lock plus a stable `deliveryId` marker.

**Tech Stack:** Laravel 11/12, Livewire 3, optional Filament v4, Pest 3 + Orchestra Testbench 10, `Illuminate\Support\Facades\Http`, `Cache` locks.

**Reference spec:** `docs/superpowers/specs/2026-06-27-destination-drivers-design.md` — read §2–§8 before starting.

## Global Constraints

- `declare(strict_types=1)` at the top of **every** new `src/` file (the existing `tests/ArchTest.php` enforces this — a missing declaration fails the suite).
- Namespace root `YousefAman\FeedbackBoard\`; PSR-4 maps `src/` → that namespace.
- **Webhook signature is byte-for-byte identical to ingest:** `hash_hmac('sha256', $ts.'.'.$rawBody, $secret)` — bare lowercase hex, no `sha256=` prefix; verify with `hash_equals`.
- **Sign the exact bytes sent:** `json_encode` once into `$rawBody`, sign `$ts.'.'.$rawBody`, send via `Http::withBody($rawBody, 'application/json')`. Never `Http::post($url, $array)` after signing.
- **Curated webhook payload only** — whitelist: `id, type, status, priority, title, description, project, reporter_name, created_at` (+ a `change` block). NEVER emit `ai_state`, `external_refs`, `meta`, `reporter_email`, `reporter_id`, `assignee_*`, or any other column. The text column is `description` (no `body` column exists on `FeedbackItem`).
- **Internal notes never leave the app:** a `replied` event whose comment `is_internal === true` is dropped at the listener before enqueue, and drivers guard defensively.
- **Secrets are never logged and never persisted in error state:** not the GitHub PAT, not the Slack webhook URL, not the webhook secret. `*_last_error` stores only a fixed-vocabulary `code`.
- **Hard authorization** on the manual "Send to GitHub" action: `abort_unless(FeedbackItemPolicy::isDeveloper(auth()->user()), 403)` inside the action body (UI hiding is insufficient).
- **Event-name filtering lives only in `DispatchToDestinations`.** Drivers never filter on event name; their only content-level drop is `is_internal`.
- Events use only the `Dispatchable` trait family already present; this plan adds `SerializesModels` to two events (additive, safe).
- `$deliveryId` is a UUID generated once at dispatch and is stable across retries.
- Config reads use the fully-qualified `feedback-board.destinations.*` path. **One deliberate exception:** the failure-notification path resolves recipients via the existing `Support\DeveloperRecipients`, which owns the pre-existing `feedback-board.notifications.developers` key — do not rewrite that to a `destinations.*` path.
- Run the suite with `vendor/bin/pest`. Run a single file with `vendor/bin/pest tests/Feature/Destinations/XTest.php`.

**Existing symbols this plan builds on (verified):**
- `Destinations\Contracts\Destination::send(FeedbackItem $item, object $event): void` — contract changes in Task 4.
- `Destinations\DestinationManager extends Illuminate\Support\Manager`; `getDefaultDriver()` returns `config('feedback-board.destinations.default','mail')`; `createMailDriver()` exists.
- `Destinations\Drivers\MailDriver` — intentional no-op.
- `Listeners\DispatchToDestinations` — currently `implements ShouldQueue`, subscribes the 3 events, foreach over `destinations.enabled`.
- `Http\Middleware\VerifyIngestSignature` — `hash_hmac('sha256', $ts.'.'.$request->getContent(), $secret)` + `hash_equals` + `Cache::add` replay nonce.
- `Models\FeedbackItem` — `$fillable`/`$casts` include `external_refs` (array), text column is `description`; `reporter_name`/`reporter_email` exist.
- `Models\FeedbackComment` — `is_internal` (bool cast), `->item()` BelongsTo, `body`.
- Events: `FeedbackItemCreated(public FeedbackItem $item)` (already has `SerializesModels`); `FeedbackReplied(public FeedbackComment $comment)` (Dispatchable only); `FeedbackStatusChanged(public FeedbackItem $item, public FeedbackStatus $from, public FeedbackStatus $to, public ?Model $actor)` (Dispatchable only).
- `Enums\FeedbackStatus::Resolved` (`'resolved'`).
- `Policies\FeedbackItemPolicy::isDeveloper(?Model $user): bool` (static, Gate `feedback-board.manage`).
- `Livewire\FeedbackDetail` — has `mount()` + `guard()` (both `abort_unless(isDeveloper,403)`), `$item` property.
- `Filament\Resources\FeedbackItemResource` — `table()`, `canViewAny()`.
- Tests: Pest, `tests/TestCase.php` (sqlite `:memory:`, users table, migrations loaded); `FeedbackItem::factory()`, `FeedbackComment::factory()`; gate granted in tests via `Gate::define('feedback-board.manage', ...)` (see `AuthorizationTest.php`). The `array` cache store is available in Testbench and supports atomic locks.

**Existing tests this plan must update (call out in the relevant task):** `tests/Feature/DestinationsTest.php` asserts the listener `implements ShouldQueue` (two tests) and calls `MailDriver::send($item, $event)` with two args. Task 4 updates the `send()` call; Task 10 replaces the two `ShouldQueue` assertions.

---

### Task 1: `SignsPayloads` trait + refactor `VerifyIngestSignature`

**Files:**
- Create: `src/Destinations/Support/SignsPayloads.php`
- Modify: `src/Http/Middleware/VerifyIngestSignature.php`
- Test: `tests/Unit/SignsPayloadsTest.php`

**Interfaces:**
- Produces: trait `SignsPayloads` with `sign(string $ts, string $body, string $secret): string` (returns bare hex) and `verifySignature(string $expected, string $actual): bool` (wraps `hash_equals`).

- [ ] **Step 1: Write the failing test**

`tests/Unit/SignsPayloadsTest.php`:
```php
<?php

use YousefAman\FeedbackBoard\Destinations\Support\SignsPayloads;

$signer = fn () => new class { use SignsPayloads; public function s($t,$b,$k){return $this->sign($t,$b,$k);} public function v($e,$a){return $this->verifySignature($e,$a);} };

it('produces a bare hex hmac identical to the ingest formula', function () use ($signer) {
    $ts = '1700000000'; $body = '{"a":1}'; $secret = 'shhh';
    $expected = hash_hmac('sha256', $ts.'.'.$body, $secret);
    expect($signer()->s($ts, $body, $secret))->toBe($expected)
        ->and($signer()->s($ts, $body, $secret))->not->toStartWith('sha256=');
});

it('verifies with constant-time comparison', function () use ($signer) {
    $sig = $signer()->s('1', 'x', 'k');
    expect($signer()->v($sig, $sig))->toBeTrue()
        ->and($signer()->v($sig, 'deadbeef'))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/SignsPayloadsTest.php`
Expected: FAIL ("Trait ... not found").

- [ ] **Step 3: Write the trait**

`src/Destinations/Support/SignsPayloads.php`:
```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Destinations\Support;

trait SignsPayloads
{
    /**
     * HMAC-SHA256 over "{timestamp}.{rawBody}" as bare lowercase hex.
     * Identical to the inbound ingest verification formula.
     */
    protected function sign(string $ts, string $body, string $secret): string
    {
        return hash_hmac('sha256', $ts.'.'.$body, $secret);
    }

    protected function verifySignature(string $expected, string $actual): bool
    {
        return hash_equals($expected, $actual);
    }
}
```

- [ ] **Step 4: Refactor the middleware onto the trait (behavior unchanged)**

`src/Http/Middleware/VerifyIngestSignature.php` — add the trait and replace only the two crypto lines; keep the enabled-gate, tolerance, and replay-nonce logic exactly:
```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use YousefAman\FeedbackBoard\Destinations\Support\SignsPayloads;

class VerifyIngestSignature
{
    use SignsPayloads;

    public function handle(Request $request, Closure $next): mixed
    {
        abort_unless(config('feedback-board.ingest.enabled'), 404);

        $secret = (string) config('feedback-board.ingest.secret');
        $ts = (string) $request->header('X-Feedback-Timestamp', '');
        $sig = (string) $request->header('X-Feedback-Signature', '');
        abort_if($secret === '' || $ts === '' || $sig === '', 403);

        $tolerance = (int) config('feedback-board.ingest.tolerance', 300);
        abort_if(abs(now()->timestamp - (int) $ts) > $tolerance, 403, 'Stale timestamp');

        $expected = $this->sign($ts, $request->getContent(), $secret);
        abort_unless($this->verifySignature($expected, $sig), 403, 'Bad signature');

        $nonceKey = 'feedback-board:ingest:'.$sig;
        abort_unless(Cache::add($nonceKey, true, $tolerance), 403, 'Replay');

        return $next($request);
    }
}
```

- [ ] **Step 5: Run tests (trait + ingest parity)**

Run: `vendor/bin/pest tests/Unit/SignsPayloadsTest.php tests/Feature/IngestTest.php`
Expected: PASS (the existing 4 ingest tests still pass — proves the refactor preserved behavior).

- [ ] **Step 6: Commit**

```bash
git add src/Destinations/Support/SignsPayloads.php src/Http/Middleware/VerifyIngestSignature.php tests/Unit/SignsPayloadsTest.php
git commit -m "feat(destinations): shared SignsPayloads trait; refactor ingest signing onto it"
```

---

### Task 2: `DestinationEvent` resolver (event → name, event → nullable item)

**Files:**
- Create: `src/Destinations/Support/DestinationEvent.php`
- Test: `tests/Unit/DestinationEventTest.php`

**Interfaces:**
- Produces: `DestinationEvent::name(object $event): string` → `feedback.created|feedback.replied|feedback.status_changed`; `DestinationEvent::item(object $event): ?FeedbackItem` (null when the underlying item/comment row is gone).

- [ ] **Step 1: Write the failing test**

`tests/Unit/DestinationEventTest.php`:
```php
<?php

use YousefAman\FeedbackBoard\Destinations\Support\DestinationEvent;
use YousefAman\FeedbackBoard\Enums\FeedbackStatus;
use YousefAman\FeedbackBoard\Events\{FeedbackItemCreated, FeedbackReplied, FeedbackStatusChanged};
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};

it('maps each event to its canonical name', function () {
    $item = FeedbackItem::factory()->create();
    $comment = FeedbackComment::factory()->create(['feedback_item_id' => $item->id]);

    expect(DestinationEvent::name(new FeedbackItemCreated($item)))->toBe('feedback.created')
        ->and(DestinationEvent::name(new FeedbackReplied($comment)))->toBe('feedback.replied')
        ->and(DestinationEvent::name(new FeedbackStatusChanged($item, FeedbackStatus::New, FeedbackStatus::Resolved, null)))
            ->toBe('feedback.status_changed');
});

it('resolves the item from each event', function () {
    $item = FeedbackItem::factory()->create();
    $comment = FeedbackComment::factory()->create(['feedback_item_id' => $item->id]);

    expect(DestinationEvent::item(new FeedbackItemCreated($item))->id)->toBe($item->id)
        ->and(DestinationEvent::item(new FeedbackReplied($comment))->id)->toBe($item->id)
        ->and(DestinationEvent::item(new FeedbackStatusChanged($item, FeedbackStatus::New, FeedbackStatus::Resolved, null))->id)
            ->toBe($item->id);
});

it('returns null item when the underlying row was deleted', function () {
    $item = FeedbackItem::factory()->create();
    $event = new FeedbackItemCreated($item);
    $item->delete();
    expect(DestinationEvent::item($event))->toBeNull();
});

it('throws on an unknown event type', function () {
    DestinationEvent::name(new stdClass());
})->throws(InvalidArgumentException::class);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/DestinationEventTest.php`
Expected: FAIL ("Class ... DestinationEvent not found").

- [ ] **Step 3: Write the resolver**

`src/Destinations/Support/DestinationEvent.php`:
```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Destinations\Support;

use InvalidArgumentException;
use YousefAman\FeedbackBoard\Events\FeedbackItemCreated;
use YousefAman\FeedbackBoard\Events\FeedbackReplied;
use YousefAman\FeedbackBoard\Events\FeedbackStatusChanged;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

final class DestinationEvent
{
    public static function name(object $event): string
    {
        return match (true) {
            $event instanceof FeedbackItemCreated => 'feedback.created',
            $event instanceof FeedbackReplied => 'feedback.replied',
            $event instanceof FeedbackStatusChanged => 'feedback.status_changed',
            default => throw new InvalidArgumentException('Unknown feedback event: '.$event::class),
        };
    }

    /**
     * Resolve the item fresh; null if the item (or the comment's parent) was deleted.
     */
    public static function item(object $event): ?FeedbackItem
    {
        $id = match (true) {
            $event instanceof FeedbackItemCreated => $event->item->id,
            $event instanceof FeedbackStatusChanged => $event->item->id,
            $event instanceof FeedbackReplied => $event->comment->feedback_item_id,
            default => throw new InvalidArgumentException('Unknown feedback event: '.$event::class),
        };

        return $id === null ? null : FeedbackItem::find($id);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/DestinationEventTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Destinations/Support/DestinationEvent.php tests/Unit/DestinationEventTest.php
git commit -m "feat(destinations): DestinationEvent resolver (event name + nullable item)"
```

---

### Task 3: Destinations config block (`default`, `enabled`, `lock_store`, per-driver)

**Files:**
- Modify: `config/feedback-board.php:27-32` (the `destinations` array)
- Test: `tests/Feature/Destinations/ConfigTest.php`

**Interfaces:**
- Produces: config keys `feedback-board.destinations.{default,enabled,lock_store,webhook.*,slack.*,github.*}`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Destinations/ConfigTest.php`:
```php
<?php

it('exposes the destinations config shape with safe defaults', function () {
    expect(config('feedback-board.destinations.default'))->toBe('mail')
        ->and(config('feedback-board.destinations.enabled'))->toBe([])
        ->and(config('feedback-board.destinations.webhook.events'))
            ->toBe(['feedback.created', 'feedback.replied', 'feedback.status_changed'])
        ->and(config('feedback-board.destinations.slack.events'))
            ->toBe(['feedback.created', 'feedback.status_changed'])
        ->and(config('feedback-board.destinations.github.events'))
            ->toBe(['feedback.replied', 'feedback.status_changed'])
        ->and(config('feedback-board.destinations.github.close_on_resolved'))->toBeFalse()
        ->and(array_key_exists('lock_store', config('feedback-board.destinations')))->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Destinations/ConfigTest.php`
Expected: FAIL (webhook/slack/github keys are null).

- [ ] **Step 3: Replace the `destinations` block**

`config/feedback-board.php` — replace lines 27–32 (`'destinations' => [ ... ],`) with:
```php
    'destinations' => [
        'default' => 'mail',        // retained for contract parity (MailDriver no-op)

        // Fully-automatic drivers only. GitHub is intentionally absent:
        // its first issue is created by the manual developer action.
        'enabled' => [],            // e.g. ['webhook', 'slack']

        // Atomic cache store backing the per-item GitHub lock and the *_last_error
        // write. MUST be one of redis|memcached|dynamodb|database. null = app default
        // store. WARNING: if the resolved store is not a LockProvider (e.g. the `file`
        // driver), Cache::store(...)->lock() THROWS on the first GitHub delivery and the
        // first failure write — set this to an atomic store in any app whose default
        // cache is `file`. `array` is atomic but process-local (tests use it).
        'lock_store' => env('FEEDBACK_DESTINATIONS_LOCK_STORE'),

        'webhook' => [
            'url' => env('FEEDBACK_WEBHOOK_URL'),
            'secret' => env('FEEDBACK_WEBHOOK_SECRET'),
            'events' => ['feedback.created', 'feedback.replied', 'feedback.status_changed'],
        ],

        'slack' => [
            'webhook_url' => env('FEEDBACK_SLACK_WEBHOOK_URL'),
            'events' => ['feedback.created', 'feedback.status_changed'],
        ],

        'github' => [
            'token' => env('FEEDBACK_GITHUB_TOKEN'),
            'repo' => env('FEEDBACK_GITHUB_REPO'),          // "owner/name"
            'events' => ['feedback.replied', 'feedback.status_changed'],  // auto-comment gate only
            'close_on_resolved' => false,
        ],
    ],
```

- [ ] **Step 4: Run tests (new + existing destinations config)**

Run: `vendor/bin/pest tests/Feature/Destinations/ConfigTest.php tests/Feature/DestinationsTest.php`
Expected: PASS for ConfigTest; `DestinationsTest`'s "no destinations enabled by default" still passes (`default` is `mail`, `enabled` is `[]`).

- [ ] **Step 5: Commit**

```bash
git add config/feedback-board.php tests/Feature/Destinations/ConfigTest.php
git commit -m "feat(destinations): config block for webhook/slack/github + lock_store"
```

---

### Task 4: Contract `$deliveryId` + `SerializesModels` on events + `MailDriver` signature

**Files:**
- Modify: `src/Destinations/Contracts/Destination.php`
- Modify: `src/Destinations/Drivers/MailDriver.php`
- Modify: `src/Events/FeedbackReplied.php`, `src/Events/FeedbackStatusChanged.php`
- Modify: `tests/Feature/DestinationsTest.php` (the `MailDriver::send` 2-arg call → 3-arg)
- Test: `tests/Feature/Destinations/ContractTest.php`

**Interfaces:**
- Produces: `Destination::send(FeedbackItem $item, object $event, string $deliveryId): void`. `FeedbackReplied`/`FeedbackStatusChanged` now serialize compactly via `SerializesModels`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Destinations/ContractTest.php`:
```php
<?php

use YousefAman\FeedbackBoard\Destinations\Drivers\MailDriver;
use YousefAman\FeedbackBoard\Events\{FeedbackItemCreated, FeedbackReplied, FeedbackStatusChanged};
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};
use Illuminate\Queue\SerializesModels;

it('MailDriver send accepts a deliveryId and stays a no-op', function () {
    $item = FeedbackItem::factory()->create();
    $event = new FeedbackItemCreated($item);
    expect((new MailDriver())->send($item, $event, 'delivery-1'))->toBeNull();
});

it('reply and status-changed events use SerializesModels', function () {
    $item = FeedbackItem::factory()->create();
    $comment = FeedbackComment::factory()->create(['feedback_item_id' => $item->id]);

    expect(in_array(SerializesModels::class, class_uses(new FeedbackReplied($comment)), true))->toBeTrue()
        ->and(in_array(SerializesModels::class, class_uses(
            new FeedbackStatusChanged($item, $item->status, $item->status, null)
        ), true))->toBeTrue();
});

it('a serialized status-changed event reloads the item fresh', function () {
    $item = FeedbackItem::factory()->create(['title' => 'original']);
    $event = new FeedbackStatusChanged($item, $item->status, $item->status, null);
    $restored = unserialize(serialize($event));
    $item->update(['title' => 'changed-after-serialize']);
    expect($restored->item->title)->toBe('changed-after-serialize'); // reloaded fresh, not the snapshot
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Destinations/ContractTest.php`
Expected: FAIL (`send()` has 2 params; events lack `SerializesModels`).

- [ ] **Step 3: Change the contract**

`src/Destinations/Contracts/Destination.php`:
```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Destinations\Contracts;

use YousefAman\FeedbackBoard\Models\FeedbackItem;

interface Destination
{
    /**
     * Send (or forward) a feedback item to the destination.
     *
     * @param  string  $deliveryId  Stable UUID for this delivery (constant across retries);
     *                              used for receiver-side dedup and idempotency markers.
     */
    public function send(FeedbackItem $item, object $event, string $deliveryId): void;
}
```

- [ ] **Step 4: Update `MailDriver`**

`src/Destinations/Drivers/MailDriver.php` — change only the signature (keep the no-op + docblock):
```php
    public function send(FeedbackItem $item, object $event, string $deliveryId): void
    {
        // No-op: mail is handled by the notifications layer.
    }
```

- [ ] **Step 5: Add `SerializesModels` to the two events**

`src/Events/FeedbackReplied.php`:
```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use YousefAman\FeedbackBoard\Models\FeedbackComment;

class FeedbackReplied
{
    use Dispatchable, SerializesModels;

    public function __construct(public FeedbackComment $comment) {}
}
```

`src/Events/FeedbackStatusChanged.php`:
```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use YousefAman\FeedbackBoard\Enums\FeedbackStatus;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class FeedbackStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public FeedbackItem $item,
        public FeedbackStatus $from,
        public FeedbackStatus $to,
        public ?Model $actor,
    ) {}
}
```

- [ ] **Step 6: Fix the existing `DestinationsTest` `send()` call**

In `tests/Feature/DestinationsTest.php`, the test "MailDriver send is a no-op …" calls `$driver->send($item, $event);`. Change that line to:
```php
    $result = $driver->send($item, $event, 'delivery-1');
```

- [ ] **Step 7: Run tests**

Run: `vendor/bin/pest tests/Feature/Destinations/ContractTest.php tests/Feature/DestinationsTest.php`
Expected: PASS (ContractTest 3; DestinationsTest still green — the two `ShouldQueue` assertions remain valid until Task 10).

- [ ] **Step 8: Commit**

```bash
git add src/Destinations/Contracts/Destination.php src/Destinations/Drivers/MailDriver.php src/Events/FeedbackReplied.php src/Events/FeedbackStatusChanged.php tests/Feature/DestinationsTest.php tests/Feature/Destinations/ContractTest.php
git commit -m "feat(destinations): add deliveryId to Destination contract; SerializesModels on events"
```

---

### Task 5: `RetryAfter` exception + `DeliveryError` mapper + `DeliverToDestination` job

**Files:**
- Create: `src/Destinations/Exceptions/RetryAfter.php`
- Create: `src/Destinations/Support/DeliveryError.php`
- Create: `src/Jobs/DeliverToDestination.php`
- Test: `tests/Feature/Destinations/DeliverToDestinationTest.php`

**Interfaces:**
- Consumes: `DestinationManager` (existing), `DestinationEvent` (Task 2), `Destination::send(..., $deliveryId)` (Task 4), `config('feedback-board.destinations.lock_store')` (Task 3), `Support\DeveloperRecipients::resolve()` (existing — used by `notifyDevelopers`).
- Produces:
  - `RetryAfter` extends `\RuntimeException` with `public int $seconds` (constructor `__construct(int $seconds)`).
  - `DeliveryError::code(\Throwable $e): string` returning one of `connection_error|http_5xx|http_429|auth_401|validation_422|not_found_404|unknown`.
  - `DeliverToDestination implements ShouldQueue` — `__construct(public object $event, public string $driver, public string $deliveryId)`, `handle(DestinationManager $manager): void`, `failed(\Throwable $e): void`, `int $maxExceptions = 4`, `retryUntil(): \DateTimeInterface`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Destinations/DeliverToDestinationTest.php`:
```php
<?php

use Illuminate\Support\Facades\Cache;
use YousefAman\FeedbackBoard\Destinations\Contracts\Destination;
use YousefAman\FeedbackBoard\Destinations\DestinationManager;
use YousefAman\FeedbackBoard\Destinations\Exceptions\RetryAfter;
use YousefAman\FeedbackBoard\Destinations\Support\DeliveryError;
use YousefAman\FeedbackBoard\Events\FeedbackItemCreated;
use YousefAman\FeedbackBoard\Jobs\DeliverToDestination;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

beforeEach(function () {
    config()->set('feedback-board.destinations.lock_store', 'array');
});

// A driver test double registered onto the manager via extend().
function fakeDriver(callable $send): Destination {
    return new class($send) implements Destination {
        public function __construct(private $send) {}
        public function send(FeedbackItem $item, object $event, string $deliveryId): void { ($this->send)($item, $event, $deliveryId); }
    };
}

it('calls the resolved driver with the item, event and deliveryId', function () {
    $item = FeedbackItem::factory()->create();
    $seen = null;
    app(DestinationManager::class)->extend('spy', fn () => fakeDriver(function ($i, $e, $d) use (&$seen) { $seen = [$i->id, $d]; }));

    (new DeliverToDestination(new FeedbackItemCreated($item), 'spy', 'dlv-1'))->handle(app(DestinationManager::class));

    expect($seen)->toBe([$item->id, 'dlv-1']);
});

it('no-ops without calling the driver when the item was deleted', function () {
    $item = FeedbackItem::factory()->create();
    $event = new FeedbackItemCreated($item);
    $called = false;
    app(DestinationManager::class)->extend('spy', fn () => fakeDriver(function () use (&$called) { $called = true; }));
    $item->delete();

    (new DeliverToDestination($event, 'spy', 'dlv-2'))->handle(app(DestinationManager::class));

    expect($called)->toBeFalse();
});

it('translates a RetryAfter from the driver into release without failing', function () {
    $item = FeedbackItem::factory()->create();
    app(DestinationManager::class)->extend('rl', fn () => fakeDriver(function () { throw new RetryAfter(42); }));

    $job = Mockery::mock(DeliverToDestination::class, [new FeedbackItemCreated($item), 'rl', 'dlv-3'])->makePartial();
    $job->shouldReceive('release')->once()->with(42);

    $job->handle(app(DestinationManager::class));
});

it('failed() writes a sanitized error code under external_refs and never the raw message', function () {
    // Default config has no `notifications.developers` (env unset) → email-only/empty,
    // so notifyDevelopers() no-ops here: this test does NOT create a notifications table,
    // proving the email-only path never attempts a database notification.
    $item = FeedbackItem::factory()->create();
    $job = new DeliverToDestination(new FeedbackItemCreated($item), 'slack', 'dlv-4');

    $job->failed(new \Illuminate\Http\Client\ConnectionException('cURL error 6: Could not resolve host: hooks.slack.com/services/SECRET'));

    $err = $item->refresh()->external_refs['slack_last_error'];
    expect($err['code'])->toBe('connection_error')
        ->and($err['event'])->toBe('feedback.created')
        ->and(json_encode($err))->not->toContain('SECRET')
        ->and(json_encode($err))->not->toContain('hooks.slack.com');
});

it('raises a best-effort Filament database notification to model developers', function () {
    \Illuminate\Support\Facades\Schema::create('notifications', function ($t) {
        $t->uuid('id')->primary();
        $t->string('type');
        $t->morphs('notifiable');
        $t->text('data');
        $t->timestamp('read_at')->nullable();
        $t->timestamps();
    });
    $dev = \YousefAman\FeedbackBoard\Tests\TestUser::create(['name' => 'Dev']);
    config()->set('feedback-board.notifications.developers', fn () => collect([$dev]));
    $item = FeedbackItem::factory()->create();

    (new DeliverToDestination(new FeedbackItemCreated($item), 'slack', 'dlv-n'))
        ->failed(new \Illuminate\Http\Client\ConnectionException('cURL error to hooks.slack.com/services/SECRET'));

    $first = $dev->fresh()->notifications()->first();
    expect($dev->notifications()->count())->toBe(1)
        ->and($first->data['format'])->toBe('filament')     // Filament render contract
        ->and(json_encode($first->data))->not->toContain('SECRET')
        ->and(json_encode($first->data))->not->toContain('hooks.slack.com');
});

it('retries are time-bounded, not count-bounded', function () {
    $item = FeedbackItem::factory()->create();
    $job = new DeliverToDestination(new FeedbackItemCreated($item), 'slack', 'dlv-5');
    expect($job->maxExceptions)->toBe(4)
        ->and($job->retryUntil())->toBeInstanceOf(DateTimeInterface::class);
});

it('DeliveryError maps throwables to a fixed vocabulary', function () {
    expect(DeliveryError::code(new \Illuminate\Http\Client\ConnectionException('x')))->toBe('connection_error')
        ->and(DeliveryError::code(new RuntimeException('boom')))->toBe('unknown');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Destinations/DeliverToDestinationTest.php`
Expected: FAIL ("Class RetryAfter not found").

- [ ] **Step 3: Write `RetryAfter`**

`src/Destinations/Exceptions/RetryAfter.php`:
```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Destinations\Exceptions;

use RuntimeException;

class RetryAfter extends RuntimeException
{
    public function __construct(public int $seconds)
    {
        parent::__construct('Retry after '.$seconds.'s');
    }
}
```

- [ ] **Step 4: Write `DeliveryError`**

`src/Destinations/Support/DeliveryError.php`:
```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Destinations\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

final class DeliveryError
{
    /**
     * Map any throwable to a fixed, secret-free vocabulary. NEVER returns
     * the message, response body, or any URL (a Guzzle/Slack message embeds
     * the webhook URL, which is itself a secret).
     */
    public static function code(Throwable $e): string
    {
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

- [ ] **Step 5: Write `DeliverToDestination`**

`src/Jobs/DeliverToDestination.php`:
```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Jobs;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Throwable;
use YousefAman\FeedbackBoard\Destinations\DestinationManager;
use YousefAman\FeedbackBoard\Destinations\Exceptions\RetryAfter;
use YousefAman\FeedbackBoard\Destinations\Support\DeliveryError;
use YousefAman\FeedbackBoard\Destinations\Support\DestinationEvent;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class DeliverToDestination implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $maxExceptions = 4;

    public function __construct(
        public object $event,
        public string $driver,
        public string $deliveryId,
    ) {}

    /** Bound retries by time so a Retry-After release never marches toward MaxAttemptsExceeded. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(15);
    }

    public function handle(DestinationManager $manager): void
    {
        $item = DestinationEvent::item($this->event);
        if ($item === null) {
            return; // item/comment deleted between enqueue and processing — no-op, no retry
        }

        try {
            $manager->driver($this->driver)->send($item, $this->event, $this->deliveryId);
        } catch (RetryAfter $e) {
            $this->release($e->seconds);
        }
    }

    public function failed(Throwable $e): void
    {
        $item = DestinationEvent::item($this->event);
        if ($item === null) {
            return;
        }

        $code = DeliveryError::code($e);
        $event = DestinationEvent::name($this->event);

        // Write under the same per-item lock the GitHub driver uses, so a
        // failure write and a concurrent ref write cannot lost-update external_refs.
        $store = config('feedback-board.destinations.lock_store');
        Cache::store($store)->lock('feedback-board:github:'.$item->id, 10)->block(5, function () use ($item, $code, $event) {
            $fresh = FeedbackItem::find($item->id);
            if ($fresh === null) {
                return;
            }
            $refs = $fresh->external_refs ?? [];
            $refs[$this->driver.'_last_error'] = [
                'code' => $code,
                'event' => $event,
                'at' => now()->toIso8601String(),
            ];
            $fresh->external_refs = $refs;
            $fresh->save();
        });

        $this->notifyDevelopers($code);
    }

    /** Best-effort Filament database notification; never throws, never leaks secrets. */
    protected function notifyDevelopers(string $code): void
    {
        if (! class_exists(\Filament\Notifications\Notification::class)) {
            return;
        }

        // Reuse the existing recipient resolver (it owns the
        // feedback-board.notifications.developers key — intentionally NOT under
        // destinations.*; do not "correct" it). Keep only DB-routable model
        // notifiables: the array-of-emails config yields AnonymousNotifiable
        // (mail route), which sendToDatabase() cannot persist.
        $notifiables = app(\YousefAman\FeedbackBoard\Support\DeveloperRecipients::class)
            ->resolve()
            ->filter(fn ($n) => $n instanceof \Illuminate\Database\Eloquent\Model);
        if ($notifiables->isEmpty()) {
            return; // email-only recipients have no database notification target
        }

        try {
            \Filament\Notifications\Notification::make()
                ->title(__('feedback-board::feedback-board.destinations.delivery_failed'))
                ->body($this->driver.': '.$code)
                ->danger()
                ->sendToDatabase($notifiables);
        } catch (Throwable) {
            // best-effort: a notification failure must not mask the delivery failure
        }
    }
}
```

- [ ] **Step 6: Add the translation string used by `notifyDevelopers`**

Append to `resources/lang/en/feedback-board.php` (inside the returned array) — add a `destinations` key if absent:
```php
    'destinations' => [
        'delivery_failed' => 'Feedback delivery failed',
    ],
```
And to `resources/lang/ar/feedback-board.php`:
```php
    'destinations' => [
        'delivery_failed' => 'فشل إرسال الملاحظة',
    ],
```

- [ ] **Step 7: Run tests**

Run: `vendor/bin/pest tests/Feature/Destinations/DeliverToDestinationTest.php`
Expected: PASS (8 tests). If the `release` mock test reports Mockery expectations unmet, confirm `handle()` catches `RetryAfter` and calls `$this->release($e->seconds)`. The Filament-notification test relies on `Filament\Notifications\Notification::sendToDatabase()` writing to the in-test `notifications` table; it is best-effort (wrapped in try/catch in the job).

- [ ] **Step 8: Commit**

```bash
git add src/Destinations/Exceptions/RetryAfter.php src/Destinations/Support/DeliveryError.php src/Jobs/DeliverToDestination.php resources/lang/en/feedback-board.php resources/lang/ar/feedback-board.php tests/Feature/Destinations/DeliverToDestinationTest.php
git commit -m "feat(destinations): DeliverToDestination job with isolation, time-bounded retry, sanitized failure"
```

---

### Task 6: `WebhookPayload` whitelist builder

**Files:**
- Create: `src/Destinations/Support/WebhookPayload.php`
- Test: `tests/Feature/Destinations/WebhookPayloadTest.php`

**Interfaces:**
- Consumes: `DestinationEvent::name()` (Task 2).
- Produces: `WebhookPayload::build(FeedbackItem $item, object $event, string $deliveryId): array` — keys `event, delivery, item{...}` and a `change` key present only for replied/status_changed.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Destinations/WebhookPayloadTest.php`:
```php
<?php

use YousefAman\FeedbackBoard\Destinations\Support\WebhookPayload;
use YousefAman\FeedbackBoard\Enums\{AuthorRole, FeedbackStatus};
use YousefAman\FeedbackBoard\Events\{FeedbackItemCreated, FeedbackReplied, FeedbackStatusChanged};
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};

it('whitelists item fields and never leaks internal columns', function () {
    $item = FeedbackItem::factory()->create([
        'reporter_name' => 'Client A',
        'reporter_email' => 'client@example.com',
        'ai_state' => ['secret' => 'do-not-leak'],
        'external_refs' => ['github' => ['issue_number' => 9]],
    ]);

    $payload = WebhookPayload::build($item, new FeedbackItemCreated($item), 'dlv-1');

    expect($payload['item'])->toHaveKeys(['id', 'type', 'status', 'priority', 'title', 'description', 'project', 'reporter_name', 'created_at'])
        ->and($payload['item'])->not->toHaveKey('reporter_email')
        ->and($payload['item'])->not->toHaveKey('ai_state')
        ->and($payload['item'])->not->toHaveKey('external_refs')
        ->and(json_encode($payload))->not->toContain('do-not-leak')
        ->and(json_encode($payload))->not->toContain('client@example.com');
});

it('omits the change key for created and includes it for status_changed', function () {
    $item = FeedbackItem::factory()->create(['status' => FeedbackStatus::New]);

    $created = WebhookPayload::build($item, new FeedbackItemCreated($item), 'd');
    expect($created)->not->toHaveKey('change')
        ->and($created['event'])->toBe('feedback.created')
        ->and($created['delivery'])->toBe('d');

    $changed = WebhookPayload::build($item, new FeedbackStatusChanged($item, FeedbackStatus::New, FeedbackStatus::Resolved, null), 'd');
    expect($changed['change'])->toBe(['from' => 'new', 'to' => 'resolved']);
});

it('includes the reply body for a public reply', function () {
    $item = FeedbackItem::factory()->create();
    $comment = FeedbackComment::factory()->create([
        'feedback_item_id' => $item->id, 'body' => 'hello', 'is_internal' => false, 'author_role' => AuthorRole::Developer,
    ]);
    $payload = WebhookPayload::build($item, new FeedbackReplied($comment), 'd');
    expect($payload['change'])->toBe(['author_role' => 'developer', 'body' => 'hello']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Destinations/WebhookPayloadTest.php`
Expected: FAIL ("Class WebhookPayload not found").

- [ ] **Step 3: Write the builder**

`src/Destinations/Support/WebhookPayload.php`:
```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Destinations\Support;

use YousefAman\FeedbackBoard\Events\FeedbackReplied;
use YousefAman\FeedbackBoard\Events\FeedbackStatusChanged;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

final class WebhookPayload
{
    /**
     * Build the curated, whitelisted outbound payload. NEVER serialize the raw
     * model: ai_state, external_refs, meta, reporter_email, reporter_id and
     * assignee_* must never appear here.
     */
    public static function build(FeedbackItem $item, object $event, string $deliveryId): array
    {
        $payload = [
            'event' => DestinationEvent::name($event),
            'delivery' => $deliveryId,
            'item' => [
                'id' => $item->id,
                'type' => $item->type?->value,
                'status' => $item->status?->value,
                'priority' => $item->priority?->value,
                'title' => $item->title,
                'description' => $item->description,
                'project' => $item->project,
                'reporter_name' => $item->reporter_name,
                'created_at' => $item->created_at?->toIso8601String(),
            ],
        ];

        if ($event instanceof FeedbackStatusChanged) {
            $payload['change'] = ['from' => $event->from->value, 'to' => $event->to->value];
        } elseif ($event instanceof FeedbackReplied) {
            $payload['change'] = [
                'author_role' => $event->comment->author_role?->value,
                'body' => $event->comment->body,
            ];
        }
        // created: no 'change' key (key omitted entirely)

        return $payload;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Destinations/WebhookPayloadTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Destinations/Support/WebhookPayload.php tests/Feature/Destinations/WebhookPayloadTest.php
git commit -m "feat(destinations): WebhookPayload whitelist builder"
```

---

### Task 7: `WebhookDriver` + `createWebhookDriver`

**Files:**
- Create: `src/Destinations/Support/TransientRetry.php` (shared retry predicate, used by all three drivers)
- Create: `src/Destinations/Drivers/WebhookDriver.php`
- Modify: `src/Destinations/DestinationManager.php` (+ `createWebhookDriver`)
- Test: `tests/Unit/TransientRetryTest.php`
- Test: `tests/Feature/Destinations/WebhookDriverTest.php`

**Interfaces:**
- Consumes: `SignsPayloads` (Task 1), `WebhookPayload` (Task 6), `DestinationEvent::name()` (Task 2), config `feedback-board.destinations.webhook.{url,secret}`.
- Produces: `TransientRetry::when(): \Closure` (retry only connection errors / 429 / 5xx — never permanent 4xx; consumed by Tasks 7,8,9); `WebhookDriver implements Destination`; `DestinationManager::createWebhookDriver(): Destination`.

> **Why a retry predicate:** verified against `Illuminate\Http\Client\PendingRequest::retry()` — with **no** `when` callback, `retry()` retries on **every** non-2xx (422/404 included). The spec requires retrying only transient failures (connection / 429 / 5xx). `TransientRetry::when()` supplies that predicate; without it, a permanent 422 would be retried (2 attempts) instead of failing immediately.

- [ ] **Step 1: Write the failing test (predicate + driver)**

`tests/Unit/TransientRetryTest.php`:
```php
<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use YousefAman\FeedbackBoard\Destinations\Support\TransientRetry;

function reqException(int $status): RequestException {
    return new RequestException(new Response(new \GuzzleHttp\Psr7\Response($status)));
}

it('retries connection errors, 429 and 5xx but not permanent 4xx', function () {
    $when = TransientRetry::when();
    expect($when(new ConnectionException('x')))->toBeTrue()
        ->and($when(reqException(429)))->toBeTrue()
        ->and($when(reqException(503)))->toBeTrue()
        ->and($when(reqException(422)))->toBeFalse()
        ->and($when(reqException(404)))->toBeFalse()
        ->and($when(reqException(401)))->toBeFalse();
});
```

`tests/Feature/Destinations/WebhookDriverTest.php`:
```php
<?php

use Illuminate\Support\Facades\Http;
use YousefAman\FeedbackBoard\Destinations\Drivers\WebhookDriver;
use YousefAman\FeedbackBoard\Events\FeedbackItemCreated;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

beforeEach(function () {
    config()->set('feedback-board.destinations.webhook.url', 'https://example.test/hook');
    config()->set('feedback-board.destinations.webhook.secret', 'whsecret');
});

it('signs the exact bytes sent and sets the delivery + event headers', function () {
    Http::fake(['example.test/*' => Http::response('', 200)]);
    $item = FeedbackItem::factory()->create(['ai_state' => ['x' => 'leak']]);

    (new WebhookDriver())->send($item, new FeedbackItemCreated($item), 'dlv-1');

    Http::assertSent(function ($request) {
        $raw = $request->body();
        $ts = $request->header('X-Feedback-Timestamp')[0];
        $expected = hash_hmac('sha256', $ts.'.'.$raw, 'whsecret');
        return $request->url() === 'https://example.test/hook'
            && $request->header('X-Feedback-Signature')[0] === $expected
            && $request->header('X-Feedback-Event')[0] === 'feedback.created'
            && $request->header('X-Feedback-Delivery')[0] === 'dlv-1'
            && ! str_contains($raw, 'leak');                  // curated payload, no ai_state
    });
});

it('is a no-op when the url is missing', function () {
    config()->set('feedback-board.destinations.webhook.url', null);
    Http::fake();
    $item = FeedbackItem::factory()->create();

    (new WebhookDriver())->send($item, new FeedbackItemCreated($item), 'dlv-2');

    Http::assertNothingSent();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/TransientRetryTest.php tests/Feature/Destinations/WebhookDriverTest.php`
Expected: FAIL ("Class TransientRetry/WebhookDriver not found").

- [ ] **Step 3: Write the shared retry predicate**

`src/Destinations/Support/TransientRetry.php`:
```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Destinations\Support;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

final class TransientRetry
{
    /**
     * Retry predicate for Http::retry(): retry ONLY transient failures —
     * connection errors, HTTP 429, and 5xx. Permanent 4xx (422 validation,
     * 404 repo, 401 auth) are never retried. Without this predicate, retry()
     * retries on every non-2xx (verified in PendingRequest::retry()).
     */
    public static function when(): Closure
    {
        return function (\Throwable $e): bool {
            if ($e instanceof ConnectionException) {
                return true;
            }
            if ($e instanceof RequestException) {
                $status = $e->response->status();

                return $status === 429 || $status >= 500;
            }

            return false;
        };
    }
}
```

- [ ] **Step 4: Write the driver**

`src/Destinations/Drivers/WebhookDriver.php`:
```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Destinations\Drivers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use YousefAman\FeedbackBoard\Destinations\Contracts\Destination;
use YousefAman\FeedbackBoard\Destinations\Support\DestinationEvent;
use YousefAman\FeedbackBoard\Destinations\Support\SignsPayloads;
use YousefAman\FeedbackBoard\Destinations\Support\TransientRetry;
use YousefAman\FeedbackBoard\Destinations\Support\WebhookPayload;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class WebhookDriver implements Destination
{
    use SignsPayloads;

    public function send(FeedbackItem $item, object $event, string $deliveryId): void
    {
        $url = (string) config('feedback-board.destinations.webhook.url');
        $secret = (string) config('feedback-board.destinations.webhook.secret');
        if ($url === '' || $secret === '') {
            Log::warning('feedback-board: webhook driver not configured; skipping delivery.');
            return;
        }

        // json_encode ONCE; sign those exact bytes; send the same bytes via withBody.
        $rawBody = json_encode(WebhookPayload::build($item, $event, $deliveryId), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ts = (string) now()->timestamp;
        $signature = $this->sign($ts, $rawBody, $secret);

        // retry() retries on connection errors AND any non-2xx; the TransientRetry
        // predicate narrows that to connection/429/5xx. With throw:false the final
        // attempt returns the response instead of throwing, so we ->throw() explicitly.
        Http::timeout(8)->connectTimeout(4)
            ->retry(2, 200, TransientRetry::when(), throw: false)
            ->withHeaders([
                'X-Feedback-Timestamp' => $ts,
                'X-Feedback-Signature' => $signature,
                'X-Feedback-Event' => DestinationEvent::name($event),
                'X-Feedback-Delivery' => $deliveryId,
            ])
            ->withBody($rawBody, 'application/json')
            ->post($url)
            ->throw();
    }
}
```

- [ ] **Step 5: Register the factory**

`src/Destinations/DestinationManager.php` — add the method (and the import):
```php
    use YousefAman\FeedbackBoard\Destinations\Drivers\WebhookDriver;
    // ...
    protected function createWebhookDriver(): Destination
    {
        return new WebhookDriver();
    }
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/TransientRetryTest.php tests/Feature/Destinations/WebhookDriverTest.php`
Expected: PASS (TransientRetry 1; WebhookDriver 2).

- [ ] **Step 7: Commit**

```bash
git add src/Destinations/Support/TransientRetry.php src/Destinations/Drivers/WebhookDriver.php src/Destinations/DestinationManager.php tests/Unit/TransientRetryTest.php tests/Feature/Destinations/WebhookDriverTest.php
git commit -m "feat(destinations): TransientRetry predicate + signed WebhookDriver (HMAC mirror of ingest)"
```

---

### Task 8: `SlackDriver` + `createSlackDriver`

**Files:**
- Create: `src/Destinations/Drivers/SlackDriver.php`
- Modify: `src/Destinations/DestinationManager.php` (+ `createSlackDriver`)
- Test: `tests/Feature/Destinations/SlackDriverTest.php`

**Interfaces:**
- Consumes: config `feedback-board.destinations.slack.webhook_url`; `DestinationEvent::name()` (Task 2); `TransientRetry::when()` (Task 7).
- Produces: `SlackDriver implements Destination`; `DestinationManager::createSlackDriver(): Destination`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Destinations/SlackDriverTest.php`:
```php
<?php

use Illuminate\Support\Facades\Http;
use YousefAman\FeedbackBoard\Destinations\Drivers\SlackDriver;
use YousefAman\FeedbackBoard\Events\FeedbackItemCreated;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

beforeEach(function () {
    config()->set('feedback-board.destinations.slack.webhook_url', 'https://hooks.slack.test/services/T/B/X');
});

it('posts Block Kit JSON and succeeds only on 200 + body "ok"', function () {
    Http::fake(['hooks.slack.test/*' => Http::response('ok', 200)]);
    $item = FeedbackItem::factory()->create(['title' => 'Crash on save']);

    (new SlackDriver())->send($item, new FeedbackItemCreated($item), 'dlv-1');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://hooks.slack.test/services/T/B/X'
            && is_array($request['blocks'])
            && str_contains(json_encode($request->data()), 'Crash on save');
    });
});

it('throws when Slack does not return the literal ok body', function () {
    Http::fake(['hooks.slack.test/*' => Http::response('invalid_payload', 200)]);
    $item = FeedbackItem::factory()->create();

    expect(fn () => (new SlackDriver())->send($item, new FeedbackItemCreated($item), 'dlv-2'))
        ->toThrow(RuntimeException::class);
});

it('is a no-op when the webhook url is missing', function () {
    config()->set('feedback-board.destinations.slack.webhook_url', null);
    Http::fake();
    $item = FeedbackItem::factory()->create();

    (new SlackDriver())->send($item, new FeedbackItemCreated($item), 'dlv-3');

    Http::assertNothingSent();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Destinations/SlackDriverTest.php`
Expected: FAIL ("Class SlackDriver not found").

- [ ] **Step 3: Write the driver**

`src/Destinations/Drivers/SlackDriver.php`:
```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Destinations\Drivers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use YousefAman\FeedbackBoard\Destinations\Contracts\Destination;
use YousefAman\FeedbackBoard\Destinations\Support\DestinationEvent;
use YousefAman\FeedbackBoard\Destinations\Support\TransientRetry;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class SlackDriver implements Destination
{
    public function send(FeedbackItem $item, object $event, string $deliveryId): void
    {
        $url = (string) config('feedback-board.destinations.slack.webhook_url');
        if ($url === '') {
            Log::warning('feedback-board: slack driver not configured; skipping delivery.');
            return;
        }

        $name = DestinationEvent::name($event);
        $fallback = "[{$name}] {$item->title}";

        $response = Http::asJson()->timeout(8)->connectTimeout(4)
            ->retry(2, 200, TransientRetry::when(), throw: false)
            ->post($url, [
                'text' => $fallback,
                'blocks' => [
                    ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => mb_strimwidth($item->title, 0, 150, '…')]],
                    ['type' => 'section', 'fields' => [
                        ['type' => 'mrkdwn', 'text' => "*Event:*\n{$name}"],
                        ['type' => 'mrkdwn', 'text' => '*Type:*\n'.($item->type?->value ?? '—')],
                        ['type' => 'mrkdwn', 'text' => '*Priority:*\n'.($item->priority?->value ?? '—')],
                        ['type' => 'mrkdwn', 'text' => '*Status:*\n'.($item->status?->value ?? '—')],
                        ['type' => 'mrkdwn', 'text' => '*Reporter:*\n'.($item->reporter_name ?? '—')],
                    ]],
                ],
            ]);

        // Slack Incoming Webhooks return HTTP 200 with the literal text "ok".
        if ($response->status() !== 200 || trim($response->body()) !== 'ok') {
            throw new RuntimeException('feedback-board: slack delivery rejected (status '.$response->status().').');
        }
    }
}
```

- [ ] **Step 4: Register the factory**

`src/Destinations/DestinationManager.php` — add:
```php
    use YousefAman\FeedbackBoard\Destinations\Drivers\SlackDriver;
    // ...
    protected function createSlackDriver(): Destination
    {
        return new SlackDriver();
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Destinations/SlackDriverTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Destinations/Drivers/SlackDriver.php src/Destinations/DestinationManager.php tests/Feature/Destinations/SlackDriverTest.php
git commit -m "feat(destinations): SlackDriver (Block Kit, 200+ok success)"
```

---

### Task 9: `GithubIssueDriver` + `createGithubDriver`

**Files:**
- Create: `src/Destinations/Drivers/GithubIssueDriver.php`
- Modify: `src/Destinations/DestinationManager.php` (+ `createGithubDriver`)
- Test: `tests/Feature/Destinations/GithubIssueDriverTest.php`

**Interfaces:**
- Consumes: `DestinationEvent::name()` (Task 2), `RetryAfter` (Task 5), `TransientRetry::when()` (Task 7), config `feedback-board.destinations.github.{token,repo,close_on_resolved}`, `feedback-board.destinations.lock_store`.
- Produces: `GithubIssueDriver implements Destination`; `DestinationManager::createGithubDriver(): Destination`. Persists `external_refs['github'] = ['issue_number','node_id','html_url','repo','posted'=>[deliveryId,...]]`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Destinations/GithubIssueDriverTest.php`:
```php
<?php

use Illuminate\Support\Facades\Http;
use YousefAman\FeedbackBoard\Destinations\Drivers\GithubIssueDriver;
use YousefAman\FeedbackBoard\Destinations\Exceptions\RetryAfter;
use YousefAman\FeedbackBoard\Enums\FeedbackStatus;
use YousefAman\FeedbackBoard\Events\{FeedbackItemCreated, FeedbackReplied, FeedbackStatusChanged};
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};

beforeEach(function () {
    config()->set('feedback-board.destinations.lock_store', 'array');
    config()->set('feedback-board.destinations.github.token', 'ghp_test');
    config()->set('feedback-board.destinations.github.repo', 'acme/app');
    \Illuminate\Support\Sleep::fake(); // retrying tests (429) must not sleep real wall-clock
});

function githubCreated() {
    return Http::response(['number' => 7, 'node_id' => 'N_1', 'html_url' => 'https://github.com/acme/app/issues/7'], 201);
}

it('creates an issue on a created event then stores the ref', function () {
    Http::fake(['api.github.com/repos/acme/app/issues' => githubCreated()]);
    $item = FeedbackItem::factory()->create();

    (new GithubIssueDriver())->send($item, new FeedbackItemCreated($item), 'dlv-create');

    $ref = $item->refresh()->external_refs['github'];
    expect($ref['issue_number'])->toBe(7)
        ->and($ref['html_url'])->toBe('https://github.com/acme/app/issues/7')
        ->and($ref['repo'])->toBe('acme/app')
        ->and($ref['posted'])->toBe(['dlv-create']);
    Http::assertSentCount(1);
});

it('comments instead of creating when a ref already exists', function () {
    Http::fake([
        'api.github.com/repos/acme/app/issues/7/comments' => Http::response(['id' => 1], 201),
    ]);
    $item = FeedbackItem::factory()->create([
        'external_refs' => ['github' => ['issue_number' => 7, 'node_id' => 'N_1', 'html_url' => 'u', 'repo' => 'acme/app', 'posted' => []]],
    ]);
    $comment = FeedbackComment::factory()->create(['feedback_item_id' => $item->id, 'body' => 'a reply', 'is_internal' => false]);

    (new GithubIssueDriver())->send($item, new FeedbackReplied($comment), 'dlv-reply');

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/issues/7/comments') && str_contains(json_encode($r->data()), 'a reply'));
    expect($item->refresh()->external_refs['github']['posted'])->toBe(['dlv-reply']);
});

it('does not post a second comment when the same deliveryId retries', function () {
    Http::fake(['api.github.com/*' => Http::response(['id' => 1], 201)]);
    $item = FeedbackItem::factory()->create([
        'external_refs' => ['github' => ['issue_number' => 7, 'node_id' => 'N', 'html_url' => 'u', 'repo' => 'acme/app', 'posted' => []]],
    ]);
    $comment = FeedbackComment::factory()->create(['feedback_item_id' => $item->id, 'is_internal' => false]);
    $driver = new GithubIssueDriver();

    $driver->send($item, new FeedbackReplied($comment), 'dlv-x');
    $item->refresh();
    $driver->send($item, new FeedbackReplied($comment), 'dlv-x'); // retry, same id

    Http::assertSentCount(1);
});

it('never comments an internal note', function () {
    Http::fake(['api.github.com/*' => Http::response(['id' => 1], 201)]);
    $item = FeedbackItem::factory()->create([
        'external_refs' => ['github' => ['issue_number' => 7, 'node_id' => 'N', 'html_url' => 'u', 'repo' => 'acme/app', 'posted' => []]],
    ]);
    $comment = FeedbackComment::factory()->create(['feedback_item_id' => $item->id, 'is_internal' => true, 'body' => 'secret note']);

    (new GithubIssueDriver())->send($item, new FeedbackReplied($comment), 'dlv-int');

    Http::assertNothingSent();
});

it('no-ops a duplicate created send when a ref already exists', function () {
    Http::fake();
    $item = FeedbackItem::factory()->create([
        'external_refs' => ['github' => ['issue_number' => 7, 'node_id' => 'N', 'html_url' => 'u', 'repo' => 'acme/app', 'posted' => []]],
    ]);

    (new GithubIssueDriver())->send($item, new FeedbackItemCreated($item), 'dlv-dup');

    Http::assertNothingSent();
});

it('closes the issue on resolved when close_on_resolved is true', function () {
    config()->set('feedback-board.destinations.github.close_on_resolved', true);
    Http::fake(['api.github.com/*' => Http::response(['id' => 1], 200)]);
    $item = FeedbackItem::factory()->create([
        'external_refs' => ['github' => ['issue_number' => 7, 'node_id' => 'N', 'html_url' => 'u', 'repo' => 'acme/app', 'posted' => []]],
    ]);

    (new GithubIssueDriver())->send($item, new FeedbackStatusChanged($item, FeedbackStatus::InProgress, FeedbackStatus::Resolved, null), 'dlv-res');

    Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/issues/7') && ($r->data()['state'] ?? null) === 'closed');
});

it('throws RetryAfter on a 429 with Retry-After', function () {
    Http::fake(['api.github.com/*' => Http::response('', 429, ['Retry-After' => '12'])]);
    $item = FeedbackItem::factory()->create();

    expect(fn () => (new GithubIssueDriver())->send($item, new FeedbackItemCreated($item), 'dlv-rl'))
        ->toThrow(RetryAfter::class);
});

it('throws RetryAfter on a 403 secondary-limit with Retry-After', function () {
    Http::fake(['api.github.com/*' => Http::response('', 403, ['Retry-After' => '30'])]);
    $item = FeedbackItem::factory()->create();

    expect(fn () => (new GithubIssueDriver())->send($item, new FeedbackItemCreated($item), 'dlv-403'))
        ->toThrow(RetryAfter::class);
});

it('does not retry a permanent 422 and fails the job', function () {
    Http::fake(['api.github.com/repos/acme/app/issues' => Http::response(['message' => 'Validation Failed'], 422)]);
    $item = FeedbackItem::factory()->create();

    expect(fn () => (new GithubIssueDriver())->send($item, new FeedbackItemCreated($item), 'dlv-422'))
        ->toThrow(\Illuminate\Http\Client\RequestException::class);
    Http::assertSentCount(1); // TransientRetry: 422 is permanent, exactly one attempt
});

it('is a no-op when token or repo is missing', function () {
    config()->set('feedback-board.destinations.github.token', null);
    Http::fake();
    $item = FeedbackItem::factory()->create();

    (new GithubIssueDriver())->send($item, new FeedbackItemCreated($item), 'dlv-cfg');

    Http::assertNothingSent();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Destinations/GithubIssueDriverTest.php`
Expected: FAIL ("Class GithubIssueDriver not found").

- [ ] **Step 3: Write the driver**

`src/Destinations/Drivers/GithubIssueDriver.php`:
```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Destinations\Drivers;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use YousefAman\FeedbackBoard\Destinations\Contracts\Destination;
use YousefAman\FeedbackBoard\Destinations\Exceptions\RetryAfter;
use YousefAman\FeedbackBoard\Destinations\Support\DestinationEvent;
use YousefAman\FeedbackBoard\Enums\FeedbackStatus;
use YousefAman\FeedbackBoard\Events\FeedbackItemCreated;
use YousefAman\FeedbackBoard\Events\FeedbackReplied;
use YousefAman\FeedbackBoard\Events\FeedbackStatusChanged;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class GithubIssueDriver implements Destination
{
    public function send(FeedbackItem $item, object $event, string $deliveryId): void
    {
        $token = (string) config('feedback-board.destinations.github.token');
        $repo = (string) config('feedback-board.destinations.github.repo');
        if ($token === '' || $repo === '') {
            Log::warning('feedback-board: github driver not configured; skipping delivery.');
            return;
        }

        // Internal notes never leave the app (defense in depth; the listener also drops them).
        if ($event instanceof FeedbackReplied && $event->comment->is_internal) {
            return;
        }

        $store = config('feedback-board.destinations.lock_store');
        try {
            Cache::store($store)->lock('feedback-board:github:'.$item->id, 60)->block(8, function () use ($item, $event, $deliveryId, $token, $repo) {
                $fresh = FeedbackItem::find($item->id);
                if ($fresh === null) {
                    return;
                }
                $ref = $fresh->external_refs['github'] ?? null;

                if ($ref === null) {
                    // Create path — reachable only for the manual created event.
                    if (! $event instanceof FeedbackItemCreated) {
                        return;
                    }
                    $resp = $this->client($token)->post($this->base($repo).'/issues', [
                        'title' => mb_strimwidth($item->title, 0, 250, '…'),
                        'body' => $this->issueBody($item),
                    ]);
                    $this->guardResponse($resp);
                    $data = $resp->json();
                    $refs = $fresh->external_refs ?? [];
                    $refs['github'] = [
                        'issue_number' => $data['number'],
                        'node_id' => $data['node_id'] ?? null,
                        'html_url' => $data['html_url'] ?? null,
                        'repo' => $repo,
                        'posted' => [$deliveryId],
                    ];
                    $fresh->external_refs = $refs;
                    $fresh->save();
                    return;
                }

                // Ref exists.
                if ($event instanceof FeedbackItemCreated) {
                    return; // duplicate manual send — server-side no-op guard
                }

                $number = $ref['issue_number'];
                $posted = $ref['posted'] ?? [];
                if (! in_array($deliveryId, $posted, true)) {
                    $resp = $this->client($token)->post($this->base($repo)."/issues/{$number}/comments", [
                        'body' => $this->commentBody($event),
                    ]);
                    $this->guardResponse($resp);
                    $ref['posted'] = [...$posted, $deliveryId];
                    $refs = $fresh->external_refs ?? [];
                    $refs['github'] = $ref;
                    $fresh->external_refs = $refs;
                    $fresh->save();
                }

                if ($event instanceof FeedbackStatusChanged
                    && $event->to === FeedbackStatus::Resolved
                    && config('feedback-board.destinations.github.close_on_resolved')) {
                    $resp = $this->client($token)->patch($this->base($repo)."/issues/{$number}", ['state' => 'closed']);
                    $this->guardResponse($resp);
                }
            });
        } catch (LockTimeoutException) {
            throw new RetryAfter(30); // contention — soft queue retry
        }
    }

    private function client(string $token)
    {
        // retry() retries connection errors AND any non-2xx; TransientRetry narrows
        // it to connection/429/5xx so a permanent 422/404 is NOT retried. throw:false
        // returns the final response; guardResponse() then decides RetryAfter vs throw.
        return Http::withToken($token)
            ->timeout(8)->connectTimeout(4)
            ->retry(2, 200, \YousefAman\FeedbackBoard\Destinations\Support\TransientRetry::when(), throw: false)
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ]);
    }

    private function base(string $repo): string
    {
        return 'https://api.github.com/repos/'.$repo;
    }

    /** Throw RetryAfter on a rate-limited response; fail on other 4xx/5xx; pass on 2xx. */
    private function guardResponse(Response $resp): void
    {
        if (($resp->status() === 429 || $resp->status() === 403) && $resp->header('Retry-After') !== '') {
            throw new RetryAfter((int) $resp->header('Retry-After'));
        }
        $resp->throw(); // permanent 4xx (422/404) and unrecovered 5xx fail the job
    }

    private function issueBody(FeedbackItem $item): string
    {
        return trim(($item->description ?? '')."\n\n—\nfeedback-board #{$item->id} ({$item->project})");
    }

    private function commentBody(object $event): string
    {
        if ($event instanceof FeedbackReplied) {
            return $event->comment->body ?? '';
        }
        if ($event instanceof FeedbackStatusChanged) {
            return "Status: {$event->from->value} → {$event->to->value}";
        }
        return DestinationEvent::name($event);
    }
}
```

- [ ] **Step 4: Register the factory**

`src/Destinations/DestinationManager.php` — add:
```php
    use YousefAman\FeedbackBoard\Destinations\Drivers\GithubIssueDriver;
    // ...
    protected function createGithubDriver(): Destination
    {
        return new GithubIssueDriver();
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Destinations/GithubIssueDriverTest.php`
Expected: PASS (10 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Destinations/Drivers/GithubIssueDriver.php src/Destinations/DestinationManager.php tests/Feature/Destinations/GithubIssueDriverTest.php
git commit -m "feat(destinations): idempotent GithubIssueDriver (lock + deliveryId markers)"
```

---

### Task 10: Refactor `DispatchToDestinations` (drop ShouldQueue, sole filter, fan-out, GitHub gate)

**Files:**
- Modify: `src/Listeners/DispatchToDestinations.php`
- Modify: `tests/Feature/DestinationsTest.php` (replace the two `ShouldQueue` assertions)
- Test: `tests/Feature/Destinations/FanOutTest.php`

**Interfaces:**
- Consumes: `DestinationEvent` (Task 2), `DeliverToDestination` (Task 5), config `destinations.enabled` + per-driver `events` + `github.{token,repo}`.
- Produces: a synchronous listener that dispatches `DeliverToDestination` jobs.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Destinations/FanOutTest.php`:
```php
<?php

use Illuminate\Support\Facades\Bus;
use YousefAman\FeedbackBoard\Events\{FeedbackItemCreated, FeedbackReplied, FeedbackStatusChanged};
use YousefAman\FeedbackBoard\Enums\FeedbackStatus;
use YousefAman\FeedbackBoard\Jobs\DeliverToDestination;
use YousefAman\FeedbackBoard\Listeners\DispatchToDestinations;
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};

function fanout(): DispatchToDestinations {
    return app(DispatchToDestinations::class);
}

beforeEach(function () {
    config()->set('feedback-board.destinations.enabled', ['webhook', 'slack']);
});

it('fans out only to drivers whose event filter matches', function () {
    Bus::fake();
    $item = FeedbackItem::factory()->create();

    fanout()->onCreated(new FeedbackItemCreated($item)); // webhook+slack both list feedback.created

    Bus::assertDispatched(DeliverToDestination::class, fn ($j) => $j->driver === 'webhook' && $j->deliveryId !== '');
    Bus::assertDispatched(DeliverToDestination::class, fn ($j) => $j->driver === 'slack');
    Bus::assertDispatchedTimes(DeliverToDestination::class, 2);
});

it('drops an internal-note reply for every driver', function () {
    Bus::fake();
    $item = FeedbackItem::factory()->create();
    $comment = FeedbackComment::factory()->create(['feedback_item_id' => $item->id, 'is_internal' => true]);

    fanout()->onReplied(new FeedbackReplied($comment));

    Bus::assertNotDispatched(DeliverToDestination::class);
});

it('auto-dispatches GitHub only when a github ref already exists', function () {
    config()->set('feedback-board.destinations.github.token', 'ghp');
    config()->set('feedback-board.destinations.github.repo', 'a/b');
    Bus::fake();

    $without = FeedbackItem::factory()->create();
    fanout()->onStatusChanged(new FeedbackStatusChanged($without, FeedbackStatus::New, FeedbackStatus::Resolved, null));
    Bus::assertNotDispatched(DeliverToDestination::class, fn ($j) => $j->driver === 'github');

    $with = FeedbackItem::factory()->create(['external_refs' => ['github' => ['issue_number' => 1]]]);
    fanout()->onStatusChanged(new FeedbackStatusChanged($with, FeedbackStatus::New, FeedbackStatus::Resolved, null));
    Bus::assertDispatched(DeliverToDestination::class, fn ($j) => $j->driver === 'github');
});

it('never auto-dispatches GitHub on a created event', function () {
    config()->set('feedback-board.destinations.github.token', 'ghp');
    config()->set('feedback-board.destinations.github.repo', 'a/b');
    Bus::fake();
    $item = FeedbackItem::factory()->create(['external_refs' => ['github' => ['issue_number' => 1]]]);

    fanout()->onCreated(new FeedbackItemCreated($item));

    Bus::assertNotDispatched(DeliverToDestination::class, fn ($j) => $j->driver === 'github');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Destinations/FanOutTest.php`
Expected: FAIL (current listener foreach-calls drivers synchronously; does not dispatch `DeliverToDestination`).

- [ ] **Step 3: Rewrite the listener**

`src/Listeners/DispatchToDestinations.php`:
```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Listeners;

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Str;
use YousefAman\FeedbackBoard\Destinations\Support\DestinationEvent;
use YousefAman\FeedbackBoard\Events\FeedbackItemCreated;
use YousefAman\FeedbackBoard\Events\FeedbackReplied;
use YousefAman\FeedbackBoard\Events\FeedbackStatusChanged;
use YousefAman\FeedbackBoard\Jobs\DeliverToDestination;

/**
 * Sole owner of event-name filtering. Synchronously fans an event out to one
 * queued DeliverToDestination job per (driver) that should receive it. Not
 * ShouldQueue itself — the queued unit is the per-destination job.
 */
class DispatchToDestinations
{
    public function onCreated(FeedbackItemCreated $event): void
    {
        $this->fanOut($event);
    }

    public function onReplied(FeedbackReplied $event): void
    {
        // Internal notes never leave the app — drop before any job is enqueued.
        if ($event->comment->is_internal) {
            return;
        }
        $this->fanOut($event);
    }

    public function onStatusChanged(FeedbackStatusChanged $event): void
    {
        $this->fanOut($event);
    }

    protected function fanOut(object $event): void
    {
        $item = DestinationEvent::item($event);
        if ($item === null) {
            return;
        }
        $name = DestinationEvent::name($event);

        // Automatic drivers (webhook, slack) listed in `enabled`, filtered by their event list.
        foreach ((array) config('feedback-board.destinations.enabled', []) as $driver) {
            $events = (array) config("feedback-board.destinations.{$driver}.events", []);
            if (in_array($name, $events, true)) {
                DeliverToDestination::dispatch($event, (string) $driver, (string) Str::uuid());
            }
        }

        // GitHub auto-comment gate: never in `enabled`; only after a ref exists
        // (which guarantees the first create came from the manual action).
        $this->maybeDispatchGithub($event, $name, $item);
    }

    protected function maybeDispatchGithub(object $event, string $name, \YousefAman\FeedbackBoard\Models\FeedbackItem $item): void
    {
        $configured = (string) config('feedback-board.destinations.github.token') !== ''
            && (string) config('feedback-board.destinations.github.repo') !== '';
        $events = (array) config('feedback-board.destinations.github.events', []);
        $hasRef = isset(($item->external_refs ?? [])['github']);

        if ($configured && $hasRef && in_array($name, $events, true)) {
            DeliverToDestination::dispatch($event, 'github', (string) Str::uuid());
        }
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            FeedbackItemCreated::class => 'onCreated',
            FeedbackReplied::class => 'onReplied',
            FeedbackStatusChanged::class => 'onStatusChanged',
        ];
    }
}
```

- [ ] **Step 4: Replace the stale assertions in `DestinationsTest`**

In `tests/Feature/DestinationsTest.php`, the two tests that assert the listener `implements ShouldQueue` are now wrong (the listener is intentionally synchronous). Replace **both** tests with a single test that proves the listener is registered and synchronous:
```php
it('the dispatch listener is registered and synchronous (not ShouldQueue)', function () {
    $listener = app(\YousefAman\FeedbackBoard\Listeners\DispatchToDestinations::class);
    expect($listener)->not->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class)
        ->and(array_keys($listener->subscribe(app('events'))))->toBe([
            \YousefAman\FeedbackBoard\Events\FeedbackItemCreated::class,
            \YousefAman\FeedbackBoard\Events\FeedbackReplied::class,
            \YousefAman\FeedbackBoard\Events\FeedbackStatusChanged::class,
        ]);
});
```
Delete the old "DispatchToDestinations listener is subscribed to package events" and "the dispatch listener is queued" tests (their `ShouldQueue` expectation contradicts the refactor; the fan-out behavior is now covered by `FanOutTest`).

- [ ] **Step 5: Run tests**

Run: `vendor/bin/pest tests/Feature/Destinations/FanOutTest.php tests/Feature/DestinationsTest.php`
Expected: PASS (FanOut 4; DestinationsTest green with the replaced assertion).

- [ ] **Step 6: Commit**

```bash
git add src/Listeners/DispatchToDestinations.php tests/Feature/DestinationsTest.php tests/Feature/Destinations/FanOutTest.php
git commit -m "feat(destinations): listener fan-out to per-destination jobs; sole event filter; github gate"
```

---

### Task 11: Manual "Send to GitHub" — shared `SendItemToGithub` action + Livewire + Filament

**Files:**
- Create: `src/Actions/SendItemToGithub.php`
- Modify: `src/Livewire/FeedbackDetail.php` (+ `sendToGithub`)
- Modify: `src/Filament/Resources/FeedbackItemResource.php` (+ table record action)
- Test: `tests/Feature/Destinations/SendItemToGithubTest.php`
- Test: `tests/Feature/Destinations/SendToGithubLivewireTest.php`

**Why a shared action:** the spec puts the hard-auth + config-guard + dispatch logic inline in the Livewire method, but the same logic must run from the Filament record action too. Extracting one `Actions\SendItemToGithub` (matching the existing `src/Actions/*` `handle()` pattern) gives a single, panel-free, fully-testable unit for the hard-authorization requirement and keeps the two UI surfaces DRY. `FeedbackDetail::mount()` already hard-guards the whole detail surface, so a non-developer can never reach the Livewire method — the meaningful non-dev authorization test lives on the shared action, not the component.

**Filament v4 note (verified against the installed `filament/actions` v4):** the unified action class is `Filament\Actions\Action` (NOT `Filament\Tables\Actions\Action`, which does not exist in v4), registered on a table via `->recordActions([...])`.

**Interfaces:**
- Consumes: `DeliverToDestination` (Task 5), `FeedbackItemPolicy::isDeveloper`.
- Produces: `SendItemToGithub::handle(FeedbackItem $item, ?Model $actor): void` — `abort_unless(isDeveloper,403)`, `abort_unless(configured,422)`, then dispatches a `github` `FeedbackItemCreated` job. `FeedbackDetail::sendToGithub(): void` and a Filament record action both delegate to it.

- [ ] **Step 1: Write the failing test (the shared action — carries the hard-auth coverage)**

`tests/Feature/Destinations/SendItemToGithubTest.php`:
```php
<?php

use Illuminate\Support\Facades\{Bus, Gate};
use Symfony\Component\HttpKernel\Exception\HttpException;
use YousefAman\FeedbackBoard\Actions\SendItemToGithub;
use YousefAman\FeedbackBoard\Events\FeedbackItemCreated;
use YousefAman\FeedbackBoard\Jobs\DeliverToDestination;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    config()->set('feedback-board.destinations.github.token', 'ghp');
    config()->set('feedback-board.destinations.github.repo', 'a/b');
});

it('dispatches a github created job for a developer', function () {
    Bus::fake();
    $dev = TestUser::create(['name' => 'Dev']);
    Gate::define('feedback-board.manage', fn ($u) => true);
    $item = FeedbackItem::factory()->create();

    app(SendItemToGithub::class)->handle($item, $dev);

    Bus::assertDispatched(DeliverToDestination::class, fn ($j) => $j->driver === 'github'
        && $j->event instanceof FeedbackItemCreated && $j->deliveryId !== '');
});

it('aborts 403 for a non-developer (hard authorization, not UI hiding)', function () {
    Bus::fake();
    $user = TestUser::create(['name' => 'Client']);
    Gate::define('feedback-board.manage', fn ($u) => false);
    $item = FeedbackItem::factory()->create();

    try {
        app(SendItemToGithub::class)->handle($item, $user);
        $this->fail('expected an HttpException');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    }
    Bus::assertNotDispatched(DeliverToDestination::class);
});

it('aborts 422 when github is not configured', function () {
    config()->set('feedback-board.destinations.github.token', null);
    Bus::fake();
    $dev = TestUser::create(['name' => 'Dev']);
    Gate::define('feedback-board.manage', fn ($u) => true);
    $item = FeedbackItem::factory()->create();

    try {
        app(SendItemToGithub::class)->handle($item, $dev);
        $this->fail('expected an HttpException');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(422);
    }
    Bus::assertNotDispatched(DeliverToDestination::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Destinations/SendItemToGithubTest.php`
Expected: FAIL ("Class ... SendItemToGithub not found").

- [ ] **Step 3: Write the shared action**

`src/Actions/SendItemToGithub.php`:
```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use YousefAman\FeedbackBoard\Events\FeedbackItemCreated;
use YousefAman\FeedbackBoard\Jobs\DeliverToDestination;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy;

class SendItemToGithub
{
    /**
     * Hard-authorize, verify configuration, then enqueue the manual GitHub
     * issue creation. Both the Livewire method and the Filament action call this.
     */
    public function handle(FeedbackItem $item, ?Model $actor): void
    {
        abort_unless(FeedbackItemPolicy::isDeveloper($actor), 403);
        abort_unless(
            (bool) config('feedback-board.destinations.github.token')
                && (bool) config('feedback-board.destinations.github.repo'),
            422,
        );

        DeliverToDestination::dispatch(
            new FeedbackItemCreated($item),
            'github',
            (string) Str::uuid(),
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Destinations/SendItemToGithubTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Write the Livewire wiring test**

`tests/Feature/Destinations/SendToGithubLivewireTest.php`:
```php
<?php

use Illuminate\Support\Facades\{Bus, Gate};
use Livewire\Livewire;
use YousefAman\FeedbackBoard\Jobs\DeliverToDestination;
use YousefAman\FeedbackBoard\Livewire\FeedbackDetail;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\TestUser;

it('the developer detail surface dispatches the github job', function () {
    config()->set('feedback-board.destinations.github.token', 'ghp');
    config()->set('feedback-board.destinations.github.repo', 'a/b');
    Bus::fake();
    $dev = TestUser::create(['name' => 'Dev']);
    Gate::define('feedback-board.manage', fn ($u) => true);
    $item = FeedbackItem::factory()->create();

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->call('sendToGithub');

    Bus::assertDispatched(DeliverToDestination::class, fn ($j) => $j->driver === 'github');
});
```
> The non-developer path is already enforced (and tested) by `FeedbackDetail::mount()`'s `abort_unless` (existing `FeedbackDetailTest`) and by `SendItemToGithubTest` above. Do NOT add `withoutExceptionHandling()` to this file.

- [ ] **Step 6: Add `sendToGithub` to `FeedbackDetail` (delegates to the shared action)**

In `src/Livewire/FeedbackDetail.php`, add this method (after `changeStatus`):
```php
    public function sendToGithub(\YousefAman\FeedbackBoard\Actions\SendItemToGithub $action): void
    {
        $action->handle($this->item, auth()->user());
        $this->dispatch('feedback-sent-to-github'); // optimistic UI hook
    }
```

- [ ] **Step 7: Add the Filament record action (delegates to the shared action)**

In `src/Filament/Resources/FeedbackItemResource.php`, add **two** imports (the file already imports `FeedbackItem` and `FeedbackItemPolicy` — do not re-add them) and a `->recordActions([...])` to the table (before `->defaultSort(...)`). The action body is a one-line delegate to `SendItemToGithub::handle()`, whose authorization/config/dispatch behavior is fully covered by `SendItemToGithubTest` (Step 1); the Filament closure itself is intentionally not exercised by a panel-render test, to avoid scaffolding a full Filament panel the harness does not set up.
```php
use Filament\Actions\Action;                 // Filament v4 unified action class
use YousefAman\FeedbackBoard\Actions\SendItemToGithub;
// ... inside table():
            ->recordActions([
                Action::make('sendToGithub')
                    ->label('Send to GitHub')
                    ->visible(fn (FeedbackItem $record): bool =>
                        FeedbackItemPolicy::isDeveloper(auth()->user())
                        && (bool) config('feedback-board.destinations.github.token')
                        && (bool) config('feedback-board.destinations.github.repo')
                        && ! isset(($record->external_refs ?? [])['github']))
                    ->action(fn (FeedbackItem $record) =>
                        // Hard authorization lives inside SendItemToGithub::handle()
                        // (abort_unless) — UI visibility above is not the gate.
                        app(SendItemToGithub::class)->handle($record, auth()->user())),
            ])
```

- [ ] **Step 8: Run tests (shared action + Livewire wiring + Filament plugin still green)**

Run: `vendor/bin/pest tests/Feature/Destinations/SendItemToGithubTest.php tests/Feature/Destinations/SendToGithubLivewireTest.php tests/Feature/FilamentPluginTest.php`
Expected: PASS (shared action 3; Livewire wiring 1; FilamentPluginTest unaffected).

- [ ] **Step 9: Commit**

```bash
git add src/Actions/SendItemToGithub.php src/Livewire/FeedbackDetail.php src/Filament/Resources/FeedbackItemResource.php tests/Feature/Destinations/SendItemToGithubTest.php tests/Feature/Destinations/SendToGithubLivewireTest.php
git commit -m "feat(destinations): manual Send-to-GitHub (shared action, Livewire + Filament v4, hard auth)"
```

---

### Task 12: Documentation — destinations section in README

**Files:**
- Modify: `README.md` (add a "Destinations" section)
- Test: none (docs) — run the **full suite** at the end of this task as the final gate.

**Interfaces:** none.

- [ ] **Step 1: Add the Destinations section to `README.md`**

Append a section documenting:
```markdown
## Destinations (outbound)

Mirror feedback events to external systems. Configure under `feedback-board.destinations`.

### Webhook (signed)
Set `FEEDBACK_WEBHOOK_URL` and `FEEDBACK_WEBHOOK_SECRET`, then add `'webhook'` to
`destinations.enabled`. Each POST carries `X-Feedback-Timestamp`, `X-Feedback-Signature`
(HMAC-SHA256 over `"{timestamp}.{rawBody}"`, bare hex — identical to the ingest contract),
`X-Feedback-Event`, and `X-Feedback-Delivery`. The payload is a curated whitelist; verify
the signature against the **raw request body** before trusting it.

### Slack
Create an Incoming Webhook, set `FEEDBACK_SLACK_WEBHOOK_URL`, add `'slack'` to
`destinations.enabled`. The webhook URL is a secret — it is never logged or stored in error state.

### GitHub Issues
Set `FEEDBACK_GITHUB_TOKEN` (a **fine-grained PAT** scoped to a single repo with
`Issues: write` — least privilege; the classic `repo` scope also works but grants far
broader access, avoid it) and `FEEDBACK_GITHUB_REPO` (`owner/name`). GitHub is **not**
added to `destinations.enabled`: the first issue is created by the developer's manual
"Send to GitHub" action; later replies and status changes auto-comment on it.
Set `feedback-board.destinations.github.close_on_resolved` to close the issue on resolve.

GitHub idempotency uses an atomic cache lock — set `FEEDBACK_DESTINATIONS_LOCK_STORE` to an
atomic store (`redis`, `memcached`, `dynamodb`, or `database`). If left unset it falls back to
your app's default cache store; a non-lockable default such as `file` will **throw** on the
first GitHub delivery, so set this explicitly when your default cache is `file`. The `array`
store is atomic but process-local (no cross-worker protection).

Delivery failures are isolated per destination, retried with backoff for up to 15 minutes,
and recorded as a sanitized code in `external_refs.{driver}_last_error` (never the raw error,
URL, or token). Internal notes are never forwarded to any destination.
```

- [ ] **Step 2: Run the full suite (final gate for the branch)**

Run: `vendor/bin/pest`
Expected: PASS — all pre-existing tests plus the new `tests/Feature/Destinations/*`, `tests/Unit/SignsPayloadsTest.php`, and `tests/Unit/DestinationEventTest.php`. The `ArchTest` (strict_types) passes for every new `src/` file.

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "docs: destinations configuration, security, and GitHub setup"
```

---

## Self-Review

**Spec coverage** (each spec section → task):
- §2.1 components → Tasks 1,2,4,5,6,7,8,9,10 (every unit has a task).
- §2.2 event names → Task 2 (`DestinationEvent::name`).
- §2.3 fan-out + filter ownership + is_internal drop + github gate → Task 10.
- §2.4 deliveryId + SerializesModels + null no-op → Tasks 4 (events) + 5 (job).
- §3.1 WebhookDriver → Tasks 6 (payload) + 7 (driver + `TransientRetry`).
- §3.2 GithubIssueDriver (lock, create/comment/no-op, posted markers, close, RetryAfter, 422-no-retry, 403/429 Retry-After) → Task 9.
- §3.3 SlackDriver → Task 8.
- §4 config (default/enabled/lock_store/per-driver) → Task 3.
- §5 failure/retry (`TransientRetry` predicate, retryUntil, RetryAfter→release, sanitized error under lock via `DeveloperRecipients`, Filament notification) → Tasks 5 + 7.
- §6 security constraints → enforced across Tasks 1,6,7,9,10,11 (Global Constraints copied).
- §7 manual UI → Task 11.
- §8 testing strategy → each task's tests map 1:1 to the listed assertions.
- §9 file structure → matches the per-task Files lists.

**Placeholder scan:** no "TBD"/"handle errors"/"similar to Task N"; every code step shows complete code; every run step shows the exact command and expected result.

**Type consistency:** `send(FeedbackItem, object, string)` is used identically in Tasks 4,7,8,9; `DeliverToDestination::__construct(object $event, string $driver, string $deliveryId)` matches every `dispatch(...)` call (Tasks 10,11 via `SendItemToGithub`) and the `->driver`/`->event`/`->deliveryId` property reads in tests; `DestinationEvent::name/item` signatures match every call; `RetryAfter(int $seconds)` matches throw sites (Task 9) and the catch (Task 5); `TransientRetry::when()` is produced in Task 7 and consumed identically in Tasks 7,8,9; `external_refs['github']` shape (`issue_number,node_id,html_url,repo,posted[]`) is consistent across Tasks 9 and 10.

**Self-review adversarial pass (applied):** two workflow reviews (spec, then plan) raised 3+16 findings; all fixed inline. Notably: `release()` consumes a try under numeric `$tries` → switched to `retryUntil()`; `Http::retry()` without a `when` callback retries permanent 4xx → added `TransientRetry`; `notifyDevelopers` reinvented (and dead in the default email config) → switched to the existing `DeveloperRecipients` resolver, filtered to DB-routable models; `lock_store` null fallback throws on a non-LockProvider default → documented; Filament v4 uses `Filament\Actions\Action` + `recordActions()` (verified in vendor).

**Known existing-test edits (intentional, plan-mandated):** Task 4 changes one `MailDriver::send` call to 3 args; Task 10 deletes two `DestinationsTest` assertions that the listener intentionally no longer satisfies (it is no longer `ShouldQueue`) and replaces them with one synchronous-registration assertion. Both are called out in their tasks so a reviewer expects them.
