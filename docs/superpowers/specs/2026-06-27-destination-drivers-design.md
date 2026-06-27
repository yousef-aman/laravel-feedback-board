# Destination Drivers (Phase 2-A) — Design Spec

> Status: approved in brainstorming, pending user review of this written spec.
> Builds on: `2026-06-26-feedback-board-design.md` (MVP) and the merged MVP package.
> Revision: incorporates the adversarial self-review (3 blockers, 6 important, 7 minor) of the first draft.

## 1. Purpose & Scope

Phase 2-A adds **outbound destination drivers** that mirror feedback events to external systems. This cycle ships exactly three drivers:

- **WebhookDriver** — signed, generic HTTP POST (HMAC mirror of the inbound `ingest` contract).
- **GitHubIssueDriver** — creates a GitHub issue (manual trigger) and comments on it for later events (idempotent).
- **SlackDriver** — fire-and-forget Incoming-Webhook notification (Block Kit).

**Explicitly out of scope this cycle:** Trello, Linear, inbound-from-destination sync, two-way sync, AI layers (Phase 2-B/C/D). Delivery is **one-way outbound push** only.

**Trigger model = hybrid:**
- **Automatic** (notification-style drivers, webhook + slack): the event listener fans out to every driver in `destinations.enabled` whose per-driver event filter matches.
- **Manual** (artifact-style driver, GitHub): the **initial** issue creation is triggered only by an explicit developer action. Once an issue exists, later `replied`/`status_changed` events **auto-comment** on it.

## 2. Architecture

### 2.1 Components

| Unit | Responsibility |
|------|----------------|
| `Destinations\Contracts\Destination` | Unchanged contract: `send(FeedbackItem $item, object $event, string $deliveryId): void`. (One added param — see §2.4.) |
| `Destinations\DestinationManager` | `Manager` subclass; gains `createWebhookDriver`/`createGithubDriver`/`createSlackDriver`. |
| `Destinations\Drivers\WebhookDriver` | Signs and POSTs a curated payload. |
| `Destinations\Drivers\GithubIssueDriver` | Idempotent create-or-comment against GitHub Issues API. |
| `Destinations\Drivers\SlackDriver` | Block Kit notification to an Incoming Webhook URL. |
| `Destinations\Support\SignsPayloads` (trait) | **Pure** HMAC helper: `sign(string $ts, string $body, string $secret): string` (bare hex) and `verifySignature(string $expected, string $actual): bool` (wraps `hash_equals`). Used by `WebhookDriver` and by `VerifyIngestSignature` (which keeps all its ingest-only logic — see §6). |
| `Destinations\Support\DestinationEvent` | `item(object $event): ?FeedbackItem` and `name(object $event): string`. Single source of the event→item and event→name mappings (DRY across listener, job, drivers). Returns `null` item when the underlying item/comment was deleted. |
| `Destinations\Support\WebhookPayload` | Builds the **whitelisted** outbound array from a `FeedbackItem` + event. No raw `toArray()`. |
| `Destinations\Support\DeliveryError` | Maps an exception/response to a **sanitized** fixed-vocabulary error code (see §5). Never returns raw messages, URLs, or bodies. |
| `Destinations\Exceptions\RetryAfter` | Thrown by a driver to request a soft queue backoff (`public int $seconds`); caught by `DeliverToDestination::handle()` → `release($seconds)`. |
| `Jobs\DeliverToDestination` | `ShouldQueue` job; one instance per `(event, driver, deliveryId)`. Isolates failure and retry per destination. |
| `Listeners\DispatchToDestinations` | Refactored: **no longer `ShouldQueue`**; a thin synchronous fan-out that enqueues `DeliverToDestination` jobs. **Sole owner of event-name filtering.** |

### 2.2 Event names (canonical strings)

`DestinationEvent::name()` maps:

| Event class | Name string |
|-------------|-------------|
| `FeedbackItemCreated` | `feedback.created` |
| `FeedbackReplied` | `feedback.replied` |
| `FeedbackStatusChanged` | `feedback.status_changed` |

`DestinationEvent::item()` maps: `FeedbackItemCreated→$event->item`, `FeedbackReplied→$event->comment->item`, `FeedbackStatusChanged→$event->item`. If the resolved model is missing (parent deleted between enqueue and processing), it returns `null`.

### 2.3 Fan-out flow

`DispatchToDestinations` is the **only** component that filters on event name. Drivers never re-check event names; their only content-level drop is `is_internal` (§3, §6).

```
Event fired (created | replied | status_changed)
        │
        ▼
DispatchToDestinations (synchronous, in-request)
        │  name = DestinationEvent::name($event)
        │  item = DestinationEvent::item($event)          // skip everything if null
        │  if event is replied AND comment.is_internal === true: return   // internal notes never enqueue any job
        │
        ├─ for each $driver in config('feedback-board.destinations.enabled'):     ← webhook, slack
        │     if name ∈ config("feedback-board.destinations.$driver.events"):
        │        DeliverToDestination::dispatch($event, $driver, (string) Str::uuid())
        │
        └─ GitHub auto-comment gate (GitHub is NOT in `enabled`):
              if github configured (token+repo)
                 AND name ∈ config('feedback-board.destinations.github.events')   // replied, status_changed
                 AND item.external_refs['github'] is already set                  ← guarantees the first create was manual
              then DeliverToDestination::dispatch($event, 'github', (string) Str::uuid())
        ▼
DeliverToDestination (queued, one per (event, driver, deliveryId))
        │  item = DestinationEvent::item($event)   // fresh-reloaded via SerializesModels; no-op return if null
        │  driver = manager->driver($driverName)
        ▼
        driver->send($item, $event, $deliveryId)
```

The **manual** developer action dispatches `DeliverToDestination::dispatch(new FeedbackItemCreated($item), 'github', (string) Str::uuid())` directly (see §7). This path **deliberately bypasses the `github.events` filter** — issue creation is the one event (`feedback.created`) intentionally not listed in `github.events`, and only the manual action may originate it. Because `external_refs['github']` is absent at that point, the listener never auto-dispatches GitHub-create; the first GitHub call always originates from the manual action.

**Filter ownership (single rule):** event-name filtering lives in `DispatchToDestinations` only. The `github.events` list governs the **auto-comment gate**; the manual create path is exempt by design. `GithubIssueDriver` itself performs no event-name filtering — it dispatches on create-vs-comment-vs-no-op purely by ref presence and event kind (§3.2).

### 2.4 Queue serialization & the delivery id

`DeliverToDestination` signature:

```php
public function __construct(
    public object $event,        // a FeedbackItemCreated|FeedbackReplied|FeedbackStatusChanged
    public string $driver,       // 'webhook' | 'slack' | 'github'
    public string $deliveryId,   // Str::uuid() generated at dispatch; STABLE across retries
) {}
```

- `$deliveryId` is generated once at dispatch and is a job property, so it is **identical across every retry of the same job**. It is the webhook `X-Feedback-Delivery` header (receiver-side dedup) **and** the GitHub per-comment idempotency marker (§3.2). Distinct legitimate events get distinct ids; retries of one event reuse the same id.
- To serialize the carried event compactly and reload models **fresh** on the worker, `FeedbackReplied` and `FeedbackStatusChanged` gain the `SerializesModels` trait (`FeedbackItemCreated` already has it). PHP's `serialize()` recurses into the nested event object and honors its `SerializesModels::__serialize`, replacing models with identifiers; the worker reloads fresh from the DB. This is additive and safe.
- `handle()` resolves `$item = DestinationEvent::item($this->event)` and **returns early (no-op, no retry)** if `$item === null` (item/comment deleted between enqueue and processing). The job never dereferences `$event->actor`.

The `Destination::send()` contract gains the `$deliveryId` parameter; `MailDriver::send()` updates its signature (still a no-op).

## 3. Drivers

### 3.1 WebhookDriver

- **Signature (mirror of `VerifyIngestSignature`, via `SignsPayloads`):** `hash_hmac('sha256', $ts.'.'.$rawBody, $secret)` — bare lowercase hex, **no** `sha256=` prefix. Verified at the receiving end with `hash_equals`.
- **One encode, sign the exact bytes sent:** `$rawBody = json_encode($payload)` once; sign `$ts.'.'.$rawBody`; send with `Http::timeout(8)->connectTimeout(4)->withBody($rawBody, 'application/json')->post($url)`. **Never** `Http::post($url, $payload)` after signing (re-encodes → signature mismatch).
- **Headers:** `X-Feedback-Timestamp`, `X-Feedback-Signature`, `X-Feedback-Event` (the canonical name), `X-Feedback-Delivery` (`$deliveryId` — stable across retries).
- **Payload (curated whitelist — `WebhookPayload`):**
  ```
  {
    "event": "feedback.status_changed",
    "delivery": "<deliveryId>",
    "item": {
      "id", "type", "status", "priority",
      "title", "description", "project",
      "reporter_name",            // name only — never reporter_email
      "created_at"
    },
    "change": { ... }             // PRESENT only for replied/status_changed; ABSENT (key omitted) for created
  }
  ```
  **Never** include `ai_state`, `external_refs`, `meta`, `reporter_email`, `reporter_id`, `assignee_*`, or any column not in the whitelist above. The text column is `description` (the model has no `body` column).
  `change` block: `status_changed → {"from","to"}`; `replied → {"author_role","body"}`; `created → the "change" key is omitted entirely from the array`.
- **`is_internal` replies never reach a driver:** the listener drops them before enqueue (§2.3). `WebhookPayload` additionally treats a `replied` event defensively and never emits an internal note.

### 3.2 GithubIssueDriver

- **Auth:** `Http::withToken($pat)->timeout(8)->connectTimeout(4)` + headers `Accept: application/vnd.github+json`, `X-GitHub-Api-Version: 2022-11-28`. PAT is a **fine-grained** token scoped to one repo with `Issues: write` (least privilege). Docs note the classic `repo` scope as a broader-privilege fallback.
- **Idempotency, serialized by an atomic lock on an atomic store:**
  ```php
  $store = config('feedback-board.destinations.lock_store');   // must be redis/memcached/dynamodb/database
  $lock  = Cache::store($store)->lock("feedback-board:github:{$item->id}", 60);
  try {
      $lock->block(8, function () use ($item, $event, $deliveryId) {
          $fresh = FeedbackItem::find($item->id);
          if ($fresh === null) return;                          // deleted; no-op
          $ref = $fresh->external_refs['github'] ?? null;

          if ($ref === null) {
              if (DestinationEvent::name($event) !== 'feedback.created') return;  // create is manual-only
              $resp = <POST /repos/{repo}/issues>;              // Http::retry inside the 60s lock window
              $this->persistRef($fresh, $resp, $deliveryId);    // store {issue_number,node_id,html_url,repo,posted:[deliveryId]}
              return;
          }

          // ref exists:
          if (DestinationEvent::name($event) === 'feedback.created') return;       // duplicate manual send → no-op (server-side guard)
          if ($event instanceof FeedbackReplied && $event->comment->is_internal) return;  // defense in depth

          $posted = $ref['posted'] ?? [];
          if (! in_array($deliveryId, $posted, true)) {
              <POST /repos/{repo}/issues/{n}/comments  body=…>; // reply text | "status: from → to"
              $this->markPosted($fresh, $deliveryId);           // append deliveryId under this lock
          }
          if ($event instanceof FeedbackStatusChanged
              && $event->to === FeedbackStatus::Resolved
              && config('feedback-board.destinations.github.close_on_resolved')) {
              <PATCH /repos/{repo}/issues/{n}  {state:closed}>; // idempotent; no labels (PATCH replaces)
          }
      });
  } catch (LockTimeoutException $e) {
      throw new RetryAfter(30);                                 // contention → job catches → soft queue retry
  }
  ```
  - **Lock store** must be atomic (`redis`/`memcached`/`dynamodb`/`database`); `file`/`array` are process-local and provide no cross-worker exclusion — explicitly unsupported (§4).
  - **Lock TTL (60s) > worst-case critical section:** `(connectTimeout 4 + timeout 8) × (1 + retries 2) + save`. The HTTP bound (§above) keeps the section well under the TTL so the lock cannot expire mid-create and let a second worker duplicate the issue.
  - **Comment idempotency:** the `$deliveryId` marker (re-read inside the lock) makes a retried comment a no-op. The `PATCH state=closed` is naturally idempotent (closing a closed issue is a no-op), so it runs after the marker check.
  - **Residual create risk (documented):** GitHub issue creation has no idempotency key. If the `POST /issues` succeeds but `persistRef` save fails inside the lock, a retry re-creates. The window is tiny (save immediately follows create, under lock) and creation is manual/single-dispatch; accepted for this cycle, noted in §10.
- **Rate limits:** secondary write limit ~80/min can return `403` **or** `429` with `Retry-After`. A driver cannot call `release()` (it is not the job), so it throws `Destinations\Exceptions\RetryAfter((int) $retryAfter)`; `DeliverToDestination::handle()` catches it and calls `$this->release($seconds)`. See §5 for why this interacts with `retryUntil`, not a numeric `$tries`. Other `4xx` (422 validation, 404 repo) are **permanent** → no HTTP retry; the job fails to `failed()`.

### 3.3 SlackDriver

- **Transport:** `Http::asJson()->timeout(8)->connectTimeout(4)->post($webhookUrl, ['text'=>…, 'blocks'=>[…]])`.
- **Block Kit:** a `header` block + a `section` with fields (Title / Type / Priority / Reporter / Status) + a context/actions link back to the item using Slack's `<url|label>` mrkdwn syntax. `text` is always set as the notification fallback.
- **Success = HTTP 200 AND response body `=== 'ok'`** (Slack returns the literal text `ok`, not JSON). Any other body/status is a failure.
- **No idempotency, no threading** — Slack messages are disposable notifications.

## 4. Configuration

`config/feedback-board.php` `destinations` block becomes:

```php
'destinations' => [
    'default' => 'mail',             // retained for contract parity (MailDriver no-op)

    // Fully-automatic drivers only. GitHub is intentionally absent:
    // its first issue is created by the manual developer action.
    'enabled' => [],                 // e.g. ['webhook', 'slack']

    // Atomic cache store backing the GitHub per-item lock. MUST be one of
    // redis|memcached|dynamodb|database. null = app default (only safe if the
    // default store is itself atomic). file/array are unsupported for GitHub.
    'lock_store' => env('FEEDBACK_DESTINATIONS_LOCK_STORE'),

    'webhook' => [
        'url'    => env('FEEDBACK_WEBHOOK_URL'),
        'secret' => env('FEEDBACK_WEBHOOK_SECRET'),
        'events' => ['feedback.created', 'feedback.replied', 'feedback.status_changed'],
    ],
    'slack' => [
        'webhook_url' => env('FEEDBACK_SLACK_WEBHOOK_URL'),
        'events'      => ['feedback.created', 'feedback.status_changed'],
    ],
    'github' => [
        'token'  => env('FEEDBACK_GITHUB_TOKEN'),
        'repo'   => env('FEEDBACK_GITHUB_REPO'),         // "owner/name"
        'events' => ['feedback.replied', 'feedback.status_changed'],  // auto-comment gate only
        'close_on_resolved' => false,
    ],
],
```

Rules:
- **Per-driver event filter** is authoritative and enforced **only** in `DispatchToDestinations`. The single exception is the manual GitHub create (`feedback.created`), which is intentionally not in `github.events` and is dispatched by the developer action, bypassing the filter (§2.3).
- All config reads use the fully-qualified `feedback-board.destinations.*` path.
- Secrets come from `env()` only; the published config never contains literal secret values. (Per-project DB-stored, encrypted secrets are deferred — not in this cycle.)
- A driver with a missing required key (`url` / `webhook_url` / `token`+`repo` empty) is a **silent no-op**: the **driver's** `send()` logs a warning (no secret values) and returns without throwing, so the queue does not retry a misconfiguration. (The driver owns knowledge of its own required keys.)
- The existing `'default' => 'mail'` key and `MailDriver` remain for contract parity.

## 5. Failure & Retry

Two independent layers:

1. **HTTP layer (inside each driver):** `Http::timeout(8)->connectTimeout(4)->retry(2, 200, TransientRetry::when(), throw: false)`. A shared `TransientRetry::when()` predicate retries **only** connection errors, `429`, and `5xx`; permanent `4xx` (422/404/401) is not retried. (Laravel's `retry()` with no `when` callback retries on *every* non-2xx — the predicate is required to honor "no retry on permanent 4xx".)
   A driver signals a rate-limit/contention backoff by throwing `Destinations\Exceptions\RetryAfter($seconds)`; `DeliverToDestination::handle()` catches it and calls `$this->release($seconds)` (re-queues without erroring). Drivers never call `release()` directly.
2. **Queue layer (`DeliverToDestination`):** **time-bounded**, not count-bounded —
   ```php
   public int $maxExceptions = 4;
   public function retryUntil(): \DateTimeInterface { return now()->addMinutes(15); }
   ```
   `retryUntil()` is the right mechanism for rate-limited work: `$this->release($seconds)` re-queues without erroring, and because retries are bounded by **time** (not a numeric `$tries`), a `Retry-After` release does not march the job toward a `MaxAttemptsExceeded` force-fail. A fixed numeric `$tries` is **not** used — under it each `release()` would consume an attempt (verified against `Illuminate\Queue` driver behavior in the self-review). `$maxExceptions` still caps genuine repeated *exceptions* so a hard-erroring job fails fast.

On terminal failure (`failed()`):
- Write a **sanitized** `external_refs["{driver}_last_error"] = ['code'=>…, 'event'=>…, 'at'=>…]` via `DeliveryError`. `code` is a fixed vocabulary (`connection_error`, `http_5xx`, `http_429`, `auth_401`, `validation_422`, `not_found_404`, `unknown`). **Never** store `$e->getMessage()`, `$response->body()`, or any URL — a Guzzle exception message embeds the request URL, and the Slack/webhook URL *is* the secret.
- The `*_last_error` write **re-reads `external_refs` fresh and `array_merge`s under the same `Cache::store($lock_store)->lock("feedback-board:github:{$item->id}")`** used for the GitHub ref write, so a failure write and a concurrent ref write cannot lost-update the shared `external_refs` JSON. (For webhook/slack failures on items that never touch GitHub, the lock is still taken on the item id — cheap and uniform.)
- If the Filament plugin is active, raise a Filament **database** notification to the developer via `FilamentNotification::make()->getDatabaseMessage()` (so it carries the required `format: 'filament'` key). The notification body uses the sanitized `code`, never raw error text.

**Failure isolation:** because each `(event, driver, deliveryId)` is its own job, a Slack failure never touches the GitHub or webhook delivery; each retries independently.

## 6. Security (binding constraints — copy verbatim into the plan's Global Constraints)

- `declare(strict_types=1)` on every new `src/` file (enforced by the existing ArchTest).
- **Webhook signature == ingest signature, byte-for-byte:** `hash_hmac('sha256', $ts.'.'.$rawBody, $secret)` bare hex, `hash_equals`. The shared `SignsPayloads` trait holds **only** the pure digest (`sign()`) and a `hash_equals` wrapper (`verifySignature()`). `VerifyIngestSignature::handle()` keeps all ingest-only logic: the `ingest.enabled` 404 gate, the timestamp-tolerance staleness check, and the atomic `Cache::add` replay-nonce keyed on the signature. Only the signing line is shared, so inbound and outbound cannot diverge.
- **Sign the exact bytes sent:** `json_encode` once, sign that string, send via `withBody(...)`. Re-encoding after signing is forbidden.
- **Curated payload only:** `WebhookPayload` whitelists fields. Never serialize `ai_state`, `external_refs`, `meta`, `reporter_email`, `reporter_id`, `assignee_*`, or unlisted columns. Use `description` (no `body` column exists).
- **Internal notes never leave the app:** a `replied` event with `is_internal === true` is dropped at the listener before enqueue (§2.3); drivers also guard defensively. No internal note text reaches webhook `change.body` or a GitHub comment.
- **Secrets are never logged and never persisted in error state** — not the GitHub PAT, not the Slack webhook URL (it embeds a token), not the webhook secret. `*_last_error` stores only a fixed-vocabulary `code` (§5).
- **Hard authorization on the manual action:** the "Send to GitHub" action begins with `abort_unless(FeedbackItemPolicy::isDeveloper(auth()->user()), 403)` inside the action body. UI hiding alone is insufficient (known Filament-action trap).
- **Least-privilege token:** fine-grained PAT, `Issues: write`, single repo. Classic `repo` scope documented only as a broader fallback with an explicit privilege warning.

## 7. Developer UI (manual trigger)

- **`FeedbackDetail` (Livewire):** a `sendToGithub()` method —
  ```php
  public function sendToGithub(): void
  {
      $this->guard();                 // abort_unless(isDeveloper) — existing pattern
      abort_unless((bool) config('feedback-board.destinations.github.token')
                && (bool) config('feedback-board.destinations.github.repo'), 422);
      \YousefAman\FeedbackBoard\Jobs\DeliverToDestination::dispatch(
          new \YousefAman\FeedbackBoard\Events\FeedbackItemCreated($this->item),
          'github',
          (string) \Illuminate\Support\Str::uuid(),
      );
      // optimistic UI; the link surfaces from external_refs.github.html_url after the job runs
  }
  ```
  The button shows for developers only; once `external_refs['github']` is set it becomes a link to `html_url`. The UI link is a convenience — the **server-side** duplicate guard is the in-lock ref check in `GithubIssueDriver` (a second send with a ref present is a no-op, §3.2). A small "delivery failed" badge shows the sanitized `*_last_error` code (no secrets) when present.
- **Filament:** the same trigger as a resource `Action`, with the `abort_unless` gate **inside the action body** (not `->visible()` alone).
- **Reporter UI is untouched** — destinations are developer-only.

## 8. Testing Strategy

- **WebhookDriver:** `Http::fake()` capturing the request; assert the signature equals a hand-computed `hash_hmac` over the **exact bytes sent**; assert `X-Feedback-Delivery === $deliveryId`; assert the payload is whitelisted (no `ai_state`/`external_refs`/`reporter_email`); assert `change` is **absent** for `created` and present for `status_changed`.
- **GithubIssueDriver:** `Http::fake()` returning `{number,node_id,html_url}`; assert one create then a comment on a later event (idempotency via persisted ref); **retry of the same `deliveryId` posts no second comment** (marker dedup); a second manual `created` with a ref present is a no-op; `422` ⇒ no HTTP retry; `403`+`Retry-After` ⇒ `release`; `to === Resolved` + `close_on_resolved` ⇒ PATCH closed; concurrent double-invocation serialized by the lock yields a single issue.
- **SlackDriver:** success only when `200 && body==='ok'`; body is plain `ok`, not JSON.
- **DeliverToDestination:** failure isolation (one driver failing leaves others untouched); `failed()` writes a sanitized `*_last_error` (assert it **never contains** the configured `webhook_url`/token substring) and raises the Filament notification; missing-key driver is a no-op; deleted item ⇒ `handle()` no-ops without retry.
- **DispatchToDestinations (filter owner):** auto drivers fan out only on their filtered events; an `is_internal` reply enqueues **no** job for any driver; GitHub is auto-dispatched **only** when `external_refs['github']` already exists; `created` never auto-dispatches GitHub.
- **Manual action:** `abort_unless` rejects a non-developer (using the `withoutExceptionHandling` pattern already in `FeedbackDetailTest`); dispatches the GitHub job for a developer.
- **SignsPayloads:** one signing implementation; an ingest round-trip test still passes after the refactor (inbound/outbound parity), and the ingest enabled-gate / tolerance / replay-nonce behaviors are unchanged.

## 9. File Structure

```
src/
  Destinations/
    DestinationManager.php            (modify: + createWebhook/Github/Slack)
    Drivers/
      WebhookDriver.php               (create)
      GithubIssueDriver.php           (create)
      SlackDriver.php                 (create)
      MailDriver.php                  (modify: send() signature + $deliveryId)
    Support/
      SignsPayloads.php               (create — pure sign()/verifySignature(); ingest refactored onto it)
      TransientRetry.php              (create — shared Http::retry predicate: connection/429/5xx only)
      DestinationEvent.php            (create — event→item(?)/event→name)
      WebhookPayload.php              (create — whitelist builder)
      DeliveryError.php               (create — sanitized error-code mapper)
    Exceptions/
      RetryAfter.php                  (create — driver→job soft-backoff signal)
  Jobs/
    DeliverToDestination.php          (create)
  Listeners/
    DispatchToDestinations.php        (modify: drop ShouldQueue, sole filter owner, fan out jobs, GitHub gate)
  Http/Middleware/
    VerifyIngestSignature.php         (modify: use SignsPayloads for the digest only)
  Events/
    FeedbackReplied.php               (modify: + SerializesModels)
    FeedbackStatusChanged.php         (modify: + SerializesModels)
  Livewire/
    FeedbackDetail.php                (modify: + sendToGithub)
  Filament/Resources/
    FeedbackItemResource.php          (modify: + Send-to-GitHub Action)
config/feedback-board.php             (modify: destinations block + lock_store)
tests/Feature/Destinations/…          (create)
```

## 10. Open Decisions (resolved)

| Question | Decision |
|----------|----------|
| Raw model vs curated webhook payload | Curated whitelist (`WebhookPayload`); `change` key absent for `created`. |
| GitHub create trigger | Manual only (bypasses `github.events`); auto-comment after a ref exists. |
| Event-name filter ownership | `DispatchToDestinations` only; drivers never re-filter on event name. |
| Carry event object across queue vs reload-by-id | Carry event object; add `SerializesModels` to the two events lacking it → compact serialize + fresh reload; `handle()` no-ops on a deleted item. |
| Idempotency for GitHub comments / webhook dedup | Stable `deliveryId` (UUID at dispatch) → webhook `X-Feedback-Delivery` header and GitHub `posted[]` marker, re-checked under the lock. |
| Serialize concurrency for GitHub | Atomic `Cache::store($lock_store)->lock(key, 60)` with bounded HTTP timeouts; `file`/`array` unsupported; `LockTimeoutException` ⇒ `release`. |
| Queue retry shape | Time-bounded `retryUntil(15m)` + `$maxExceptions`; no numeric `$tries` (so `Retry-After` `release` is free). |
| Error persistence | Sanitized fixed-vocabulary `code` only; never raw messages/URLs/bodies; written under the per-item lock. |
| Residual GitHub create dup risk | Accepted: tiny window if `POST /issues` succeeds but the in-lock save fails; creation is manual/single-dispatch. No issue-search-before-create this cycle. |
| Secret storage | `env()` only this cycle; encrypted per-project DB config deferred. |
