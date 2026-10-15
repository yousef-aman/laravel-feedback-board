# AI Runner Contract (Level B/C)

This document specifies the full contract between the Feedback Board package and an external
AI runner. The runner is an operator-controlled process that lives **outside** the package —
typically a CI/CD job (e.g. GitHub Actions) or a private server script. The package
dispatches a signed task POST; the runner acts on it and posts results back. Everything is
manual and opt-in. The package never runs your agent, never executes code, and never merges
anything automatically. All results are suggestions that a developer reviews and applies.

---

## 1. Overview

Level B/C runner support extends the package's in-app AI triage (Level A) with an external,
developer-controlled agent loop:

1. A developer (or automation) triggers a runner task from the Filament UI or via the ingest
   endpoint.
2. The package dispatches a signed HTTPS POST to the operator's **runner endpoint** with a
   JSON envelope describing the feedback item.
3. The runner endpoint (e.g. `receiver.example.php`) verifies the HMAC signature, then
   forwards the task to a CI job (e.g. via `repository_dispatch`).
4. The CI job runs an AI coding agent (Claude Code Action, Aider, or any tool of your
   choice), produces analysis, a diff, and optionally opens a pull request.
5. The CI job posts results back to the package's signed ingest endpoint using
   `runner_result` and `runner_status` actions.
6. A developer reviews the suggestions in the Filament board and decides what to apply.

The package is a passive recipient of results — it never initiates a merge, never executes
remote code, and never contacts a third-party AI provider on the runner's behalf.

---

## 2. What Leaves the App

When a runner task is dispatched, the package sends a signed POST to your configured
`runner.endpoint` URL with the following JSON envelope:

```json
{
  "task_id": "01J1ZZ...",
  "event": "runner.task",
  "item": {
    "id": 42,
    "type": "bug",
    "status": "in_progress",
    "priority": "high",
    "title": "Login button unresponsive on Safari",
    "description": "Clicking the login button on Safari 17 does nothing. No JS errors visible.",
    "project": "my-app",
    "created_at": "2026-06-20T08:00:00+00:00"
  },
  "triage": {
    "suggested_type": "bug",
    "suggested_priority": "high",
    "summary": "User-reported UI regression on Safari; likely a JS compatibility issue."
  },
  "callback": {
    "ingest_url": "https://app.example.com/feedback-board/ingest",
    "item_id": 42,
    "task_id": "01J1ZZ..."
  }
}
```

**Field notes:**

- `task_id` — a unique opaque string (ULID) identifying this runner invocation. Echo it
  verbatim in all post-back calls.
- `event` — always `"runner.task"`.
- `item.id` — the feedback item's database ID.
- `item.type` — the item's type enum value (e.g. `"bug"`, `"feature"`), or `null`.
- `item.status` — the item's current status enum value, or `null`.
- `item.priority` — the item's priority enum value, or `null`.
- `item.title` / `item.description` — the raw reporter-submitted text. See the privacy note
  below.
- `item.project` — the project slug the item belongs to, or `null`.
- `item.created_at` — ISO 8601 timestamp of when the item was created, or `null`.
- `triage` — **conditional**. This key is present ONLY when Level A in-app triage has
  completed (`ai_state.triage.status === 'completed'`). When Level A never ran, the entire
  `triage` key is ABSENT from the envelope.
- `triage.summary` — the Level A summary string (string, normally non-null; built from
  `$triage['summary'] ?? null` so may be `null` in rare edge cases).
- `triage.suggested_type` and `triage.suggested_priority` — MAY be `null`. A completed
  in-app triage can legitimately produce no type or priority suggestion (e.g. the model
  returned an ambiguous response). Your runner MUST handle `null` for both fields and not
  treat them as errors.
- `callback.ingest_url` — the pre-built post-back URL. Present only when the operator has
  configured `runner.callback_url`; omitted otherwise. Convenience field — you may also
  store the ingest URL as a CI secret.
- `callback.item_id` — the feedback item ID (mirrors `item.id`); included for convenience
  in post-back payloads.
- `callback.task_id` — the task ID (mirrors `task_id`); included for convenience in
  post-back payloads.

**Privacy note:** The runner endpoint receives feedback body text — treat it as confidential
reporter data; do not point it at an untrusted third-party receiver.

Only the title, description, and in-app triage summary are forwarded. Reporter identity,
email, attachments, and all internal fields are never included in the envelope.

---

## 3. Outbound Headers

Every task POST from the package to your runner endpoint carries these headers:

| Header | Value |
|--------|-------|
| `X-Feedback-Timestamp` | Unix timestamp (seconds) at dispatch time |
| `X-Feedback-Signature` | `hash_hmac('sha256', "{ts}.{rawBody}", FEEDBACK_RUNNER_SECRET)` — bare hex |
| `X-Feedback-Event` | `runner.task` |
| `X-Feedback-Delivery` | The `task_id` for this dispatch |
| `Content-Type` | `application/json` |

### HMAC formula

```
signature = hash_hmac('sha256', "{ts}.{rawBody}", secret)
```

Where `ts` is the value of `X-Feedback-Timestamp` and `rawBody` is the raw (undecoded)
request body bytes. The secret is `FEEDBACK_RUNNER_SECRET` (the outbound runner secret
configured in the package). The signature is a lowercase hex string.

Your receiver MUST:
1. Extract `X-Feedback-Timestamp` and `X-Feedback-Signature` from the request headers.
2. Compute `hash_hmac('sha256', "{ts}.{rawBody}", $secret)` over the raw body.
3. Compare using a constant-time function (e.g. `hash_equals`).
4. Reject requests where `abs(time() - ts) > 300` (5-minute replay window).

---

## 4. Post-Back to the Package (Runner → App)

After running the agent, your CI job posts results back to the package's signed ingest
endpoint: `POST {ingest_url}`.

**Prerequisite:** `ingest.enabled` must be `true` in `config/feedback-board.php`. Post-back
calls get `404` while the ingest endpoint is disabled.

### 4.1 Signing post-back calls

Post-back requests are signed with `FEEDBACK_BOARD_INGEST_SECRET` (the **ingest** secret,
not the runner secret) using the **same HMAC formula**:

```
signature = hash_hmac('sha256', "{ts}.{rawBody}", FEEDBACK_BOARD_INGEST_SECRET)
```

Include the same three headers:
- `X-Feedback-Timestamp: {unix_ts}`
- `X-Feedback-Signature: {hex_hmac}`
- `Content-Type: application/json`

**Responses.** The endpoint answers with its real status and a JSON body: `200 {"ok":true}`; 403 (missing or bad signature, stale timestamp, replay); 404 (ingest disabled, unknown item); 409 (`task_id` mismatch); 422 (validation; the body lists `errors`); 429 (more than 60 requests a minute); 503 (busy, for example a lock held by a GitHub delivery). Retry 429, 503 and connection errors with a **fresh timestamp and signature**: a signature is accepted once. String values are trimmed of leading and trailing whitespace. [`examples/runner/post-back.sh`](examples/runner/post-back.sh) does all of this.

### 4.2 Action: `runner_status`

Report the running state of the task. Send this at the start of the job and again at
completion or failure.

```json
{
  "action": "runner_status",
  "item_id": 42,
  "task_id": "01J1ZZ...",
  "status": "running"
}
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `action` | string | yes | Must be `"runner_status"` |
| `item_id` | int | yes | The feedback item ID from the envelope |
| `task_id` | string | yes | Echo the `task_id` from the dispatch envelope verbatim |
| `status` | string | yes | One of `running`, `completed`, `failed` |
| `error_code` | string | no | Only when `status` is `"failed"`; must match `^[a-z0-9_]{1,40}$`. If `status` is `failed` and `error_code` is omitted or empty, the stored value defaults to `'unknown'`. |

**Status is monotonic.** Terminal statuses (`completed`, `failed`) are sticky per `task_id`.
Once a task reaches a terminal status, subsequent `runner_status` posts for the same
`task_id` are accepted (idempotent) but do not change the stored status. Your runner SHOULD
retry the terminal `runner_status` POST to ensure delivery (e.g. on network failure).

### 4.3 Action: `runner_result`

Post the agent's findings. Sending a `runner_result` also advances the task status to
`completed` implicitly — you do not need to send a separate terminal `runner_status` if you
post a result, though doing so is harmless and recommended for clarity.

```json
{
  "action": "runner_result",
  "item_id": 42,
  "task_id": "01J1ZZ...",
  "analysis": "Root cause identified in AuthController::login() — missing preventDefault call.",
  "diff": "--- a/app/Http/Controllers/AuthController.php\n+++ b/...",
  "pr_url": "https://github.com/owner/repo/pull/123"
}
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `action` | string | yes | Must be `"runner_result"` |
| `item_id` | int | yes | The feedback item ID from the envelope |
| `task_id` | string | yes | Echo the `task_id` from the dispatch envelope verbatim |
| `analysis` | string | no | Free-text analysis from the agent; at most `runner.max_analysis_chars` (10,000) characters. A longer one is rejected with 422, not truncated |
| `diff` | string | no | Unified diff produced by the agent; at most `runner.max_diff_chars` (20,000) characters. A longer one is rejected with 422 |
| `pr_url` | string | no | URL of an opened pull request |

**`pr_url` security constraint:** If provided, `pr_url` MUST be an `https` URL with no
userinfo component. The package rejects URLs that contain `@` in the authority segment (e.g.
`https://github.com@evil.com` is rejected). This prevents open-redirect / SSRF via crafted
URLs. The value is stored as-is for display to developers — it is never fetched by the
package.

---

## 5. Rules Summary

- **Echo `task_id` verbatim.** Every post-back call (both `runner_status` and
  `runner_result`) must include the exact `task_id` received in the dispatch envelope. The
  package uses it to correlate results to the dispatched task.
- **Status is monotonic.** Once a task reaches `completed` or `failed`, the status is
  sticky. Retry terminal `runner_status` POSTs freely — they are idempotent.
- **`runner_result` advances status.** A successful `runner_result` post implicitly sets
  the task status to `completed`. Still recommended to send `runner_status completed`
  explicitly after, so partial failures (result posted, status POST failed) are handled
  gracefully.
- **`ingest.enabled` must be true.** Post-back is rejected if the ingest endpoint is
  disabled.
- **`triage.suggested_type` and `triage.suggested_priority` may be `null`.** Always guard
  against null before using these fields.

---

## 6. Security Notes

- **Run the runner in CI/dev, never in production.** The receiver script and CI workflow
  run with scoped, time-limited credentials (e.g. a GitHub token with `repo:dispatch` only).
  They must never share secrets with the production application.
- **No auto-merge.** The package stores a `pr_url` for developer review. No merge is ever
  triggered automatically. The developer must review the agent's PR and merge it manually.
- **Secrets are never placed in a feedback item.** CI secrets (API keys, tokens) must never
  be written into the analysis, diff, or any other field that gets stored back on the
  feedback item. Keep them in the CI environment only.
- **Verify the HMAC before acting.** The receiver must verify the `X-Feedback-Signature`
  header using `hash_equals` (constant-time) before forwarding the task to any downstream
  system. Never process an unsigned or tampered payload.
- **Replay window.** Reject requests where `abs(time() - X-Feedback-Timestamp) > 300`.
- **Privacy.** The envelope contains reporter-submitted text. Do not log it to external
  services, do not forward it to untrusted third-party endpoints, and treat it with the same
  care as any PII.

---

## 7. Worked End-to-End Example

**Step 1 — Developer triggers the runner** from the Filament Feedback Board UI ("Run Agent"
action on a feedback item).

**Step 2 — Package dispatches to the receiver:**

```
POST https://your-infra.example.com/feedback-runner
X-Feedback-Timestamp: 1751276400
X-Feedback-Signature: a3f8c2...
X-Feedback-Event: runner.task
X-Feedback-Delivery: 01J1ZZABCDEF123

{
  "task_id": "01J1ZZABCDEF123",
  "event": "runner.task",
  "item": {
    "id": 99,
    "type": "bug",
    "status": "new",
    "priority": "high",
    "title": "NPE on checkout",
    "description": "...",
    "project": "storefront",
    "created_at": "2026-06-25T09:30:00+00:00"
  },
  "triage": {
    "suggested_type": "bug",
    "suggested_priority": "high",
    "summary": "Null pointer on order total calculation."
  },
  "callback": {
    "ingest_url": "https://app.example.com/feedback-board/ingest",
    "item_id": 99,
    "task_id": "01J1ZZABCDEF123"
  }
}
```

*(The `triage` block is absent when Level A triage has not completed for this item.
`callback.ingest_url` is absent when `runner.callback_url` is not configured.)*

**Step 3 — Receiver verifies HMAC**, dispatches `repository_dispatch` to GitHub.

**Step 4 — GitHub Actions job runs:**

- Sends `runner_status running` → `POST {ingest_url}` signed with `FEEDBACK_BOARD_INGEST_SECRET`.
- AI coding agent analyses the codebase, opens PR #42.
- Sends `runner_result` with `analysis`, `diff`, `pr_url`.
- Sends `runner_status completed`.

**Step 5 — Developer sees results** in the Feedback Board detail panel: the analysis text,
diff, and a link to PR #42. Developer reviews the PR, applies it if appropriate.

---

## 8. Configuration Reference

| Config key | Env var | Description |
|------------|---------|-------------|
| `runner.enabled` | `FEEDBACK_BOARD_RUNNER_ENABLED` | Enable the runner dispatch (default: `false`) |
| `runner.endpoint` | `FEEDBACK_RUNNER_ENDPOINT` | HTTPS URL of your receiver script |
| `runner.secret` | `FEEDBACK_RUNNER_SECRET` | HMAC secret for outbound runner POST |
| `runner.callback_url` | `FEEDBACK_RUNNER_CALLBACK_URL` | Optional explicit ingest URL placed in the task envelope's callback block |
| `ingest.enabled` | `FEEDBACK_BOARD_INGEST_ENABLED` | Must be `true` for post-back to be accepted |
| `ingest.secret` | `FEEDBACK_BOARD_INGEST_SECRET` | HMAC secret for verifying ingest POST-back |

---

## See Also

- Reference receiver: [`docs/examples/runner/receiver.example.php`](examples/runner/receiver.example.php)
- Reference workflow: [`docs/examples/runner/github-actions-feedback-runner.example.yml`](examples/runner/github-actions-feedback-runner.example.yml)
- Reference post-back sender: [`docs/examples/runner/post-back.sh`](examples/runner/post-back.sh) (`jq`-built bodies, retries 429/503 with a fresh signature)
