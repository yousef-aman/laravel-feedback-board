# Feedback Board — Design Spec

**Package (working name):** `yousefaman/feedback-board`
**Date:** 2026-06-26
**Status:** Approved design — ready for implementation planning

---

## ملخّص بالعربية (Arabic summary)

حزمة Laravel (تعمل مع/بدون Filament) تُركَّب بـ `composer require` داخل تطبيق سلّمته لعميلك، فتضيف:
لوحة ملاحظات داخل التطبيق (بأسلوب Trello) يرفع فيها **مستخدمو التطبيق** بلاغات (وصف + صور + ملفات)،
ومسارًا يدير منه **المبرمج** البلاغات ويحلّها ويردّ على العميل، مع إشعارات بريد. التفرّد في **الملكية
الذاتية** (بياناتك على بنيتك، لا سحابة طرف ثالث). المرحلة ٢ تضيف تكامل أدوات (GitHub/Slack/…) ووكيل
AI يقرأ البلاغ ويعالجه حتى فتح PR — عبر عقد runner خارجي يملكه المطوّر، لا مبرمج مدمج في الإنتاج.

---

## 1. Problem statement

The gap between a client and the developer of a delivered system is **how the client reports
issues and how those reports reach, and get resolved by, the developer.** Today this happens over
scattered channels (WhatsApp, email, calls) with no structure, no screenshots-in-context, no
status, and no audit trail.

This package closes that gap **inside the app the client already uses**: a self-hosted, in-app
feedback board the developer `composer require`s into their own Laravel/Filament application.

## 2. Goals & non-goals

**Goals (MVP):**
- In-app feedback board where the app's already-logged-in users submit reports (description +
  screenshots + file attachments + auto-captured technical context).
- A developer resolution workflow (kanban, status transitions, replies, internal notes, activity
  log) inside the same app, gated by authorization.
- Email + in-app notifications to the developer (new report / new reply) and to the client (reply /
  resolved).
- Works **with or without Filament** (Livewire-first UI; optional Filament plugin).
- Self-hosted: the developer owns the data and the infrastructure.
- Arabic-first i18n + RTL out of the box.
- **Cheap forward-compatibility** for Phase 2: a destinations driver layer, an inbound `ingest`
  webhook, and readiness columns — all scaffolded but inactive in the MVP.

**Non-goals (explicitly out of MVP scope):**
- No hosted SaaS, no per-seat billing, no vendor cloud.
- No public/anonymous feedback widget — the reporter is always an authenticated app user.
- No active third-party integrations in MVP (GitHub/Trello/Slack/Linear) — contract only.
- No AI in MVP — the AI contract (A/B/C) is designed but built in Phase 2.
- The package never bundles or runs a coding agent inside production.

## 3. Market positioning (from competitive research)

A multi-agent competitive sweep (Marker.io, BugHerd, Userback, Usersnap, Jam.dev, Canny,
Featurebase, Fider, Astuto, Gleap, FasterFixes, Volet, larafeed, twisted-binary, Sentry Seer,
Linear Agents/Cyrus, Devin, Copilot coding agent, Codex, Sweep, Quackback, shogomuranushi/feedback-widget)
returned a **novelty score of ~62/100**.

- **Not novel:** every individual piece exists. In-app capture, kanban feedback boards, Level A
  triage, the client-note→AI→PR loop (Gleap ships it end-to-end), and self-hosted Laravel feedback
  widgets (Volet, larafeed) are all commoditized.
- **Genuinely novel:** the **exact union as one self-hosted `composer require` package** embedded in
  the developer's own delivered Laravel/Filament app — client reports inside their existing session
  (no separate portal/login), developer owns the data and the AI runner, plus hub/spoke aggregation.
- **Closest threats:** Gleap (adds self-hosted tier → collapses differentiation), Volet (adds an AI
  layer), FasterFixes / twisted-binary (a Laravel adapter away).

**Positioning wedge (do NOT lead on "AI opens a PR" — Gleap owns it):**
> *The self-hosted Laravel/Filament feedback-to-resolution package you `composer require` into the
> app you already delivered. Your client reports inside the app they already log into, your data
> stays on your infra, and (Phase 2) YOUR Claude runner — your key, your CI — does the AI work.
> No vendor cloud, no separate portal, no per-seat SaaS.*

Target niche: **Laravel/Filament agencies & freelancers** delivering bespoke apps to non-technical
clients. Differentiators: data sovereignty, in-their-own-session reporting, developer-owned runner,
hub/spoke at agency scale.

## 4. Architecture

Livewire-first core with a thin optional Filament adapter. The domain core (Models / Actions /
Events) knows nothing about Filament.

```
src/
  FeedbackBoardServiceProvider.php      # spatie/laravel-package-tools
  Models/      FeedbackItem · FeedbackComment · FeedbackAttachment · FeedbackActivity
  Enums/       FeedbackStatus · FeedbackType · FeedbackPriority · ActivityAction · AuthorRole
  Actions/     CreateFeedbackItem · TransitionStatus · ReplyToFeedback · AssignFeedback
  Events/      FeedbackItemCreated · FeedbackStatusChanged · FeedbackReplied
  Livewire/    FeedbackBoard · FeedbackForm · FeedbackItemModal      # framework-agnostic UI
  Filament/    FeedbackBoardPlugin · Resources/FeedbackItemResource · Pages/FeedbackBoardPage
  Destinations/  Contracts/Destination · DestinationManager · Drivers/MailDriver  # driver layer
  Http/Controllers/IngestController.php  # inbound webhook (scaffolded, HMAC-gated, off by default)
  Notifications/  NewFeedbackNotification · FeedbackRepliedNotification · FeedbackResolvedNotification
  Concerns/HasFeedbackReports.php        # optional trait for the host User model
config/feedback-board.php
database/migrations/
routes/web.php
resources/views/    resources/lang/{ar,en}/
tests/              # Pest + Orchestra Testbench
```

**Layering principle:** Models/Actions/Events form the domain core. Livewire renders the shared UI
and works in any Laravel app. The Filament plugin is an adapter that reuses the same Actions. The
destinations layer and the ingest endpoint live in the core as event consumers/producers from day
one so Phase 2 (hub + AI) is a configuration upgrade, not a rewrite.

## 5. Data model

**`feedback_items`** — the report:
| column | type | notes |
|---|---|---|
| `id` | id | |
| `project` | string, default from config | **hub/spoke readiness** |
| `reporter_type` / `reporter_id` | nullableMorphs | host app user (any model/guard) |
| `reporter_name` / `reporter_email` | string | denormalized snapshot (survives user deletion) |
| `title` | string | |
| `description` | text | |
| `type` | enum (`bug`/`feature`/`question`/`other`) | |
| `status` | enum (`FeedbackStatus`) | default `new` |
| `priority` | enum, nullable | |
| `assignee_type` / `assignee_id` | nullableMorphs | developer handling it |
| `meta` | json | auto-captured URL / browser / OS / locale |
| `external_refs` | json | `{github_issue, trello_card, ...}` — **destinations readiness** |
| `ai_state` | json, nullable | triage result / PR link — **AI readiness (Phase 2)** |
| `resolved_at` | timestamp, nullable | |
| timestamps | | |

**`feedback_comments`** — the client↔developer thread:
`id` · `feedback_item_id` · `author_type`/`author_id` (morph) · `author_role` enum
(`reporter`/`developer`/**`ai`**) · `body` text · `is_internal` bool (dev-only, hidden from client) ·
timestamps.

**`feedback_attachments`** — images/files:
`id` · `feedback_item_id` · `feedback_comment_id` nullable · `disk` · `path` · `original_name` ·
`mime` · `size` · timestamps. Stored on a **configurable, private-by-default disk**; served via
gated/signed routes. We do **not** force `spatie/laravel-medialibrary` (optional adapter only).

**`feedback_activities`** — audit / status history (our edge over Volet/larafeed):
`id` · `feedback_item_id` · `actor_type`/`actor_id` nullable · `action` enum · `from_status` /
`to_status` nullable · `properties` json · `created_at`.

The readiness columns (`project`, `external_refs`, `ai_state`, `author_role=ai`) exist in the MVP
schema but are unused until Phase 2.

## 6. UI surfaces

**(a) Client surface** — route `/feedback` plus an optional floating launcher Blade component
`<x-feedback-board::launcher/>` the developer drops into their layout to open the submission modal
from any page (core of the "in their existing session" wedge).
- Submission form: title, type, description, screenshot/file upload; auto-captures current URL /
  browser / OS / locale into `meta`.
- "My reports" list: the reporter sees **only their own** items (policy-scoped), each with current
  status and thread. Simple, friendly, Arabic RTL.

**(b) Developer surface** — a kanban page inside the Filament panel when Filament is present, or the
same Livewire board gated by an ability when it is not.
- Columns map to statuses; drag-and-drop triggers `TransitionStatus`.
- Item detail: full thread, attachments, `meta`, assignee, priority, activity log, and actions
  `Reply` / `Change status` / `Assign` / `Internal note`.
- Board kanban built on an existing Filament kanban primitive (e.g. Mokhosh Kanban / Relaticle
  Flowforge) rather than from scratch.

## 7. Workflow & status model

`FeedbackStatus`: `new` → `in_review` → `in_progress` → `resolved` → `closed`, plus `declined`.
The set is configurable. Every transition goes through the `TransitionStatus` action, which:
1. authorizes the actor, 2. updates the item, 3. writes a `feedback_activities` row,
4. fires `FeedbackStatusChanged`, 5. notifies the relevant parties.
Replies go through `ReplyToFeedback` (creates a comment, fires `FeedbackReplied`, notifies).

## 8. Notifications

- **To the developer:** on new report and on new client reply. Channels: `mail` (default) +
  `database` (Filament bell when present) + the destinations layer (Phase 2). Recipients are
  configurable: an array of emails, or a resolver returning notifiable users (e.g. users holding an
  ability/role).
- **To the client/reporter:** on developer reply and on transition to `resolved`. Channels: `mail` +
  in-app.
- Filament database notifications MUST use `FilamentNotification::make()->getDatabaseMessage()`
  (format `filament`) — a plain `toArray()` payload will not render in the bell.

## 9. Destinations + ingest contract (scaffolded in MVP, active in Phase 2)

**Outbound — `DestinationManager` (Laravel Manager pattern, driver-based):**
- `Destination::send(FeedbackItem $item, DomainEvent $event): void` + `DestinationManager`.
- MVP ships **`MailDriver` only**. Phase 2 adds `Webhook`, `GitHubIssue`, `Slack`, `Trello`,
  `Linear`. Drivers are enabled per-event in config; dispatch is queued.

**Inbound — `POST /feedback-board/ingest`:**
- Lets an external system update an item: add a comment, change status, write a PR link into
  `external_refs` / `ai_state`.
- Auth: **HMAC signature + shared secret + timestamp/nonce (replay protection)** — never session.
  Unsigned requests are rejected. An ingest call may only touch the item it is authorized for.
- Documented as a public contract so any runner (Claude Code GitHub Action, Codex, Cursor) can post
  back. **Off by default** in the MVP.

**Hub/spoke:** the hub is the same package with ingest enabled, receiving from spoke apps whose
`WebhookDriver` points at the hub's ingest URL; the `project` column distinguishes sources.

## 10. AI contract A/B/C (designed now, built in Phase 2)

- **Level A (in-app triage):** a queued `TriageFeedbackItem` job calls the Claude API with the
  developer's own key → fills `ai_state` (type / priority / summary / duplicate detection) and may
  draft a reply comment with `author_role=ai, is_internal=true` until a developer approves it. Runs
  inside the app (a plain HTTP call). Model id is pinned at build time via the `claude-api`
  reference (a cheap tier such as Haiku 4.5 for triage; Sonnet 4.6 if deeper reasoning is needed).
- **Level B/C (external runner):** on the event (or a manual "send to agent" action) the
  `WebhookDriver` emits a structured task payload to the developer's configured runner endpoint. The
  runner (Claude Code Action / Codex / Cursor in CI or the dev's environment) does the work and posts
  back via `ingest` — an analysis comment + suggested diff for B, a PR link + status for C. The
  package never runs the coding agent.

## 11. Security & authorization

- **Authorization:** `FeedbackItem` policies. The reporter may view/comment on **their own** items
  only; the developer acts via an ability. In Filament, `->authorize()` only hides UI — hard
  enforcement uses `abort_unless`/policy checks in the action body (known Filament trap).
- **File uploads:** mime/type/size allowlist; private disk by default; served via gated/signed
  routes (never public); block executable uploads and path traversal.
- **Ingest webhook:** verify HMAC, enforce a nonce/timestamp window against replay, rate-limit.
- **Mass assignment:** guarded models; validated Requests/DTOs inside Actions — never `$request->all()`.
- **Reporter identity is always server-derived** (`auth()->user()`), never taken from client input.
- **Level C blast radius (Phase 2):** AI PRs target a branch only, **no auto-merge**, human gate; the
  runner runs in CI/dev with scoped credentials, **never in production**; secrets are never exposed
  to a feedback item.

## 12. Internationalization

`resources/lang/{ar,en}/` with full Arabic translations and **RTL support** in the client surface
from day one — a deliberate differentiator for the Arabic, non-technical client experience. All
user-facing strings are translatable; the locale is captured into `meta`.

## 13. Configuration (`config/feedback-board.php`)

`project` identifier · enabled `statuses` · developer-notification recipients (array or resolver) ·
attachment disk + allowed mime/size · authorization ability/gate names · `destinations` (driver
config, MVP = mail) · `ingest` (enabled flag + secret) · `ai` (enabled flag + tier, Phase 2) ·
locale/RTL options.

## 14. Testing strategy

- **Pest + Orchestra Testbench** (matching `filament-autosave`); TDD during implementation.
- **Feature:** submission flow · status transitions · authorization (reporter cannot see others'
  items; non-developer cannot reach the board) · reply loop · notifications dispatched · file-upload
  validation · ingest signature (reject unsigned/replayed).
- **Unit:** Actions (`CreateFeedbackItem`, `TransitionStatus`) · `DestinationManager` driver resolution.
- Authorization is tested by **invoking the action directly** and asserting the abort (because
  `callAction` + `assertForbidden` does not catch the Filament authorize trap).
- **CI:** GitHub Actions matrix — PHP 8.2/8.3 × Laravel 11/12 × Filament 4/5.

## 15. Phasing

**Phase 1 (MVP — the defensible wedge):** package skeleton · data model · client surface (form +
launcher + "my reports") · developer surface (Filament kanban / Livewire board) · resolution loop ·
mail + database notifications · `MailDriver` · i18n/RTL · security · tests. **Plus** the scaffolded
(inactive) destinations contract, ingest endpoint, and readiness columns.

**Phase 2:** activate destination drivers (GitHub/Slack/Trello/Linear/Webhook) · AI Level A (in-app
triage) · AI Level B/C (external runner contract) · hub-mode aggregation UI.

## 16. Dependencies

- Required: PHP ^8.2, Laravel ^11 || ^12, Livewire, `spatie/laravel-package-tools`.
- Optional: `filament/filament` ^4 || ^5 (developer surface), a Filament kanban primitive,
  `spatie/laravel-medialibrary` (attachment adapter).
- Dev: `orchestra/testbench`, `pestphp/pest`.

## 17. Risks & mitigations (from research)

- **Thin moat (packaging, not tech).** → Out-execute on the resolution+reply loop, ingest webhook,
  hub/spoke, and Arabic/RTL UX that the Laravel rivals lack; anchor on data sovereignty.
- **Large surface for one package → "half-features".** → Ship the MVP wedge fully polished before any
  Phase 2 work; keep destinations/AI as scaffolded contracts, not half-built features.
- **Level C security/liability.** → Hard authorization + validation (not UI gating), no auto-merge,
  runner never in production.
- **Narrow market / fragile category** (Astuto archived, Height discontinued). → Validate with one or
  two of the developer's own delivered apps before broad investment.

## 18. Open questions / future

- Final package name (working name `yousefaman/feedback-board`).
- Whether to offer an optional shared (all-reports) client board vs strictly "my reports".
- Which Filament kanban primitive to depend on vs vendor a minimal one.
- Phase 2 model-tier defaults and the exact ingest payload schema.
