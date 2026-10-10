# Feedback Experience Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild the reporter and developer surfaces as Filament-native screens: a slide-over report form with drag, paste and picker uploads, a "My reports" page with unread replies and an image conversation, a Trello-style Kanban whose cards show the screenshot as a cover, and a two-column card dialog with image comments, internal notes and a merged activity feed.

**Architecture:** The data layer gains two reporter columns (read marker, reporter-visible activity), correlated unread queries, a cover relation and comment-attachment cleanup. A shared Livewire trait stages uploads one file at a time into a locked list; an Alpine queue partial feeds it from the picker, drop and paste. Shared Blade components (icon, avatar, type tile, slide-over, thumbnail) and a lightbox partial carry the UI; the self-contained stylesheet grows new blocks, an accent-tint technique without `color-mix`, and bridge tokens that a Filament render hook fills with the panel's primary colour. The Filament plugin adds a "My reports" page, translated navigation with icons and badges, and a per-panel `myReports()` switch.

**Tech Stack:** PHP 8.2+, Laravel 12/13, Livewire 3.8.3+/4.3.4+ (bundled Alpine with the Focus plugin), optional Filament 4/5, Pest 3/4, Orchestra Testbench 10/11. No front-end build.

**Spec:** `docs/superpowers/specs/2026-10-10-feedback-experience-design.md`, with the normative mockup `docs/superpowers/specs/assets/2026-10-10-feedback-experience-mockup.html` (open it in a browser; it fixes layout, hierarchy, copy and states).

**Workspace:** the existing worktree `.claude/worktrees/feedback-experience` on branch `feature/feedback-experience` (it already holds the spec commits). Run every command from the worktree root. Its `vendor/` resolves Laravel 13.35 / Livewire 4.4.7 / Filament 5.10.1 / Pest 4.7.8; the suite starts at **356 passed**.

## Global Constraints

- Versions unchanged: `php ^8.2`, `illuminate/contracts ^12.0 || ^13.0`, `livewire/livewire ^3.8.3 || ^4.3.4`, Filament optional `^4.0 || ^5.0`. **No new Composer or npm dependencies, no build step, no GD/Imagick.**
- Every CSS selector starts with `.fb-`, `.dark .fb-`, `.fi .fb-` or `.fi.dark .fb-`. The stylesheet contains no `@import`, `url(`, `!important`, `@layer`, `:has(`, CSS nesting or `color-mix(`. The reduced-motion block stays the last rule.
- Token contract: the stylesheet never declares a public `--fb-*` token. It reads the six public tokens (`--fb-accent`, `--fb-accent-hover`, `--fb-accent-contrast`, `--fb-radius`, `--fb-font`, `--fb-z`) and the three internal bridge tokens (`--fb-panel-accent`, `--fb-panel-accent-hover`, `--fb-panel-accent-contrast`) only with a fallback, the bridge tokens only under `.fi` selectors and always beneath the public ones. No Filament variables (`--primary-*`, `--gray-*`) are read.
- `::before` is reserved for accent tints; other decorations use `::after`. `isolation` is never set on an ancestor of an overlay (slide-over, dialog, lightbox).
- Never set `display` in a rule that can match an element carrying `wire:loading`. An element hidden with the `hidden` attribute must not carry a class that sets `display` (author rules beat the UA's `[hidden]`); remove such elements instead.
- Escape listeners on overlays use `.window`; the lightbox binds Escape on its own root with `.stop`.
- User-written text (titles, descriptions, bodies, names, file names) carries `dir="auto"` or sits in `<bdi>`; technical values (URLs, user agents, locales) are wrapped in `dir="ltr"`.
- **User-controlled strings never appear in `x-*`, `wire:*` or `@…` expressions, nor in `aria-label`/`title`/`alt` attributes.** They go into `data-*` attributes read through `$el.dataset`, or into text (visually hidden text for accessible names). Expressions contain only integers, enum values and package-defined strings.
- All dynamic output is escaped with `{{ }}`; no `{!! !!}` in package views.
- Every element id inside a Livewire component derives from `'fb-'.$this->getId()`. New `fb-`-prefixed ids and browser event names that are not classes go into `$notClasses` in `tests/Unit/StylesheetCoverageTest.php`.
- New views stay one directory level below `resources/views` or `resources/views-filament`.
- Preserved verbatim: the drag-and-drop strings (`dataTransfer` key `id`, `$wire.moveTo(id, status)`, the `getData('id') &&` drop guard, the `types.includes('id')` enter/leave counters, `fb-col--over`, `fb-card--dragging`), the conditional literal `wire:poll.5s` attributes on the triage and runner blocks, `h2#fb-drawer-title` in the developer dialog, `role="status" tabindex="-1"` with its `$nextTick` focus, the `fb-launcher-opened` reset, `data-fb-theme`, `dir`, the `fb-scope` roots, every component name and every existing public Livewire method name.
- Every new translation key exists in `resources/lang/en/feedback-board.php` **and** `resources/lang/ar/feedback-board.php` (the parity test enforces it).
- No closures in `config/feedback-board.php`.
- Code style: `declare(strict_types=1);` in new PHP files, 4-space indent, the existing namespace layout. There is no linter: run `php -l` on every changed PHP file and the full suite `vendor/bin/pest` before each commit; the suite must have no failures.
- Commit messages are conventional (`feat(ui): …`, `test: …`, `docs: …`) and written as the work itself — **no `Co-Authored-By` line and no tool attribution of any kind** (the owner's global rule overrides any harness default).

## Review Focus

The inputs most likely to break for a real user that no single spec test names. Each line names the task that pins it.

1. **A pasted screenshot** (an unnamed clipboard blob) on Livewire 3 and 4, in Chrome and Firefox: it must upload exactly once, get a `screenshot-YYYYMMDD-HHMMSS.<ext>` name that matches its type, and become an image attachment. Task 6 pins the queue and rename contract in the markup and stages a renamed file on the server; the browser pass (Task 12) pastes three images.
2. **A report whose only attachment is an 8–10 MB phone photo**, on a board with many cards: covers are lazy-loaded and the board runs no per-card query. Task 8 counts queries for 2 and 10 cards and asserts `loading="lazy" decoding="async"`.
3. **An RTL panel with a long English title and an English URL** in the same card and dialog: titles stay `dir="auto"`, URLs `dir="ltr"`, and nothing widens the page. Tasks 8 and 9 assert the attributes under the Arabic locale; the browser pass checks overflow at 390 px.
4. **A panel whose primary colour is light** (amber or yellow): button text must stay readable. Task 10 asserts that amber prints its 950 shade as the contrast token and pink prints white.
5. **A first panel visit after `composer update` but before `migrate`**: the panel must still render, with one log warning per process. Task 10 drops the new columns and loads two panel pages.

## File map

| Task | Creates | Modifies |
|---|---|---|
| 1 Data layer | `database/migrations/update_feedback_items_add_reporter_columns.php`, `src/Support/Images.php` | `FeedbackItem`, `FeedbackComment`, `FeedbackAttachment`, `FeedbackStatus`, `ReplyToFeedback`, `TransitionStatus`, `FeedbackItemPolicy`, service provider |
| 2 Activity log | `src/Actions/SetPriority.php`, `src/Support/Person.php` | `ActivityAction`, `ApplyTriageSuggestion`, `AssignFeedback`, `FeedbackActivity`, `FeedbackDetail::setPriority()`, lang |
| 3 Attachment serving | — | `AttachmentController` |
| 4 Composer rules, outbound | — | config, `AttachmentStorage`, `FeedbackRepliedNotification`, `GithubIssueDriver`, lang |
| 5 UI primitives | `src/Support/Icons.php`, components `icon`, `avatar`, `type-icon`, `slide-over`, `thumb`, partial `lightbox` | `FeedbackType`, `Person`, `Ui::colorScheme()`, config comment, stylesheet, lang |
| 6 Report form | `src/Livewire/Concerns/WithStagedUploads.php`, partials `upload-queue`, `report-form-sheet` | `FeedbackForm` + view, launcher, `Ui`, `AttachmentStorage`, stylesheet, lang |
| 7 My reports | partial `pending-images` | `MyReportsBoard` + view, `FeedbackItemModal` + view, trait, stylesheet, lang |
| 8 Kanban | — | `FeedbackKanban` + view, stylesheet, lang |
| 9 Card dialog | — | `FeedbackDetail` + view, kanban view, stylesheet, lang |
| 10 Filament | `src/Filament/Pages/MyFeedbackPage.php`, `src/Filament/PanelColors.php`, `resources/views-filament/filament/my-feedback-page.blade.php` | plugin, board page, resource, `Ui`, lang |
| 11 Standalone, docs | partial `standalone-header` | standalone pages, stylesheet, README, CHANGELOG |
| 12 Verification | — | — (controller-run lanes and browser pass) |

---

### Task 1: Reporter columns, unread queries, report covers and comment-attachment cleanup

**Files:**
- Create: `database/migrations/update_feedback_items_add_reporter_columns.php`
- Create: `src/Support/Images.php`
- Modify: `src/FeedbackBoardServiceProvider.php` (the `hasMigrations()` list)
- Modify: `src/Models/FeedbackItem.php`, `src/Models/FeedbackComment.php`, `src/Models/FeedbackAttachment.php`
- Modify: `src/Enums/FeedbackStatus.php`
- Modify: `src/Actions/ReplyToFeedback.php`, `src/Actions/TransitionStatus.php`
- Modify: `src/Policies/FeedbackItemPolicy.php` (delegate the owner check)
- Modify: `tests/Feature/InstallCommandTest.php`
- Test: `tests/Unit/ImagesTest.php`, `tests/Feature/ReporterActivityTest.php`, `tests/Feature/AttachmentRecordsTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces (later tasks rely on these exact names):
  - `YousefAman\FeedbackBoard\Support\Images` — `const MIMES` (extension ⇒ MIME), `mimes(): list<string>`, `extensions(): list<string>`, `matches(?string $mime, ?string $name): bool`.
  - `FeedbackItem` — casts `reporter_read_at`/`reporter_activity_at` to datetime; scopes `ownedBy(mixed $user)`, `withUnreadCount()` (adds `unread_count`), `hasUnread()`, `latestForReporter()`; methods `isReportedBy(mixed $user): bool`, `markReadBy(mixed $user): bool`, `readMarker(): ?CarbonInterface`, `reporterActivity(): ?CarbonInterface`; relation `coverAttachment(): HasOne`.
  - `FeedbackComment::attachments(): HasMany`; deleting a comment deletes its attachment rows and, after commit, their files.
  - `FeedbackAttachment::isImage(): bool`, `FeedbackAttachment::url(): string`.
  - `FeedbackStatus::openCases(): list<FeedbackStatus>`, `FeedbackStatus::isOpen(): bool`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/ImagesTest.php`:

```php
<?php

use YousefAman\FeedbackBoard\Support\Images;

it('accepts an allow-listed image only when its extension matches its type', function (?string $mime, ?string $name, bool $expected) {
    expect(Images::matches($mime, $name))->toBe($expected);
})->with([
    ['image/png', 'shot.png', true],
    ['image/jpeg', 'photo.JPG', true],
    ['image/jpeg', 'photo.jpeg', true],
    ['image/gif', 'a.gif', true],
    ['image/webp', 'a.webp', true],
    ['image/png', 'shot.jpg', false],
    ['image/svg+xml', 'logo.svg', false],
    ['text/html', 'page.png', false],
    ['image/png', 'no-extension', false],
    [null, 'a.png', false],
    ['image/png', null, false],
]);

it('lists the allow-listed types and extensions', function () {
    expect(Images::mimes())->toBe(['image/jpeg', 'image/png', 'image/gif', 'image/webp'])
        ->and(Images::extensions())->toBe(['jpg', 'jpeg', 'png', 'gif', 'webp']);
});
```

`tests/Feature/ReporterActivityTest.php`:

```php
<?php

use Illuminate\Support\Facades\{DB, Schema};
use YousefAman\FeedbackBoard\Actions\{AssignFeedback, ReplyToFeedback, TransitionStatus};
use YousefAman\FeedbackBoard\Enums\{AuthorRole, FeedbackStatus};
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(fn () => $this->travelTo('2026-10-01 09:00:00'));

function fbReporterActivity(FeedbackItem $item): ?string
{
    return $item->fresh()->reporter_activity_at?->toDateTimeString();
}

it('starts the reporter activity when the report is created', function () {
    expect(fbReporterActivity(FeedbackItem::factory()->create()))->toBe('2026-10-01 09:00:00');
});

it('moves the reporter activity on public replies and status changes only', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $item = FeedbackItem::factory()->create();

    $this->travel(1)->hours();
    app(ReplyToFeedback::class)->handle($item->fresh(), 'root cause', $dev, AuthorRole::Developer, isInternal: true);
    app(AssignFeedback::class)->handle($item->fresh(), $dev, $dev);
    $item->fresh()->forceFill([
        'ai_state' => ['triage' => ['status' => 'pending']],
        'external_refs' => ['github' => ['issue_number' => 1]],
    ])->save();

    expect(fbReporterActivity($item))->toBe('2026-10-01 09:00:00');

    $this->travel(1)->hours();
    app(ReplyToFeedback::class)->handle($item->fresh(), 'fixed', $dev, AuthorRole::Developer);
    expect(fbReporterActivity($item))->toBe('2026-10-01 11:00:00');

    $this->travel(1)->hours();
    app(TransitionStatus::class)->handle($item->fresh(), FeedbackStatus::InProgress, $dev);
    expect(fbReporterActivity($item))->toBe('2026-10-01 12:00:00');
});

it('counts developer and AI replies newer than the last read as unread', function () {
    $me = TestUser::create(['name' => 'Me', 'email' => 'me@x.com']);
    $item = FeedbackItem::factory()->for($me, 'reporter')->create();

    $this->travel(1)->minutes();
    FeedbackComment::factory()->for($item, 'item')->create(['author_role' => AuthorRole::Developer]);
    FeedbackComment::factory()->for($item, 'item')->create(['author_role' => AuthorRole::Ai]);
    FeedbackComment::factory()->for($item, 'item')->create(['author_role' => AuthorRole::Developer, 'is_internal' => true]);
    FeedbackComment::factory()->for($item, 'item')->create(['author_role' => AuthorRole::Reporter]);

    expect((int) FeedbackItem::withUnreadCount()->find($item->id)->unread_count)->toBe(2)
        ->and(FeedbackItem::ownedBy($me)->hasUnread()->count())->toBe(1);

    $this->travel(1)->minutes();

    expect($item->fresh()->markReadBy($me))->toBeTrue()
        ->and((int) FeedbackItem::withUnreadCount()->find($item->id)->unread_count)->toBe(0)
        ->and(FeedbackItem::ownedBy($me)->hasUnread()->count())->toBe(0);
});

it('does not count a reply from the same second as the last read', function () {
    $me = TestUser::create(['name' => 'Me', 'email' => 'me@x.com']);
    $item = FeedbackItem::factory()->for($me, 'reporter')->create();
    FeedbackComment::factory()->for($item, 'item')->create(['author_role' => AuthorRole::Developer]);

    expect((int) FeedbackItem::withUnreadCount()->find($item->id)->unread_count)->toBe(0);
});

it('marks read only for the reporter and leaves updated_at alone', function () {
    $me = TestUser::create(['name' => 'Me', 'email' => 'me@x.com']);
    $other = TestUser::create(['name' => 'Other', 'email' => 'o@x.com']);
    $item = FeedbackItem::factory()->for($me, 'reporter')->create();
    $updatedAt = $item->fresh()->updated_at->toDateTimeString();

    $this->travel(5)->minutes();

    expect($item->markReadBy($other))->toBeFalse()
        ->and($item->markReadBy(null))->toBeFalse()
        ->and($item->fresh()->reporter_read_at)->toBeNull()
        ->and($item->markReadBy($me))->toBeTrue()
        ->and($item->isDirty())->toBeFalse();

    $fresh = $item->fresh();
    expect($fresh->reporter_read_at->toDateTimeString())->toBe('2026-10-01 09:05:00')
        ->and($fresh->updated_at->toDateTimeString())->toBe($updatedAt)
        ->and($fresh->readMarker()->toDateTimeString())->toBe('2026-10-01 09:05:00');
});

it('scopes reports to their reporter and to nobody for a guest', function () {
    $me = TestUser::create(['name' => 'Me', 'email' => 'me@x.com']);
    FeedbackItem::factory()->for($me, 'reporter')->create(['title' => 'Mine']);
    FeedbackItem::factory()->create(['title' => 'Anonymous']);

    expect(FeedbackItem::ownedBy($me)->pluck('title')->all())->toBe(['Mine'])
        ->and(FeedbackItem::ownedBy(null)->count())->toBe(0);
});

it('orders a reporter\'s reports by their latest reporter-visible activity', function () {
    $me = TestUser::create(['name' => 'Me', 'email' => 'me@x.com']);
    $old = FeedbackItem::factory()->for($me, 'reporter')->create(['title' => 'Old']);
    $this->travel(1)->hours();
    FeedbackItem::factory()->for($me, 'reporter')->create(['title' => 'New']);
    $this->travel(1)->hours();
    app(ReplyToFeedback::class)->handle($old->fresh(), 'an answer', null, AuthorRole::Developer);
    $this->travel(1)->hours();
    // A row written around the model (no reporter_activity_at) falls back to its creation time.
    DB::table('feedback_items')->insert([
        'project' => 'default', 'title' => 'Raw', 'type' => 'bug', 'status' => 'new',
        'reporter_type' => $me->getMorphClass(), 'reporter_id' => $me->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(FeedbackItem::ownedBy($me)->latestForReporter()->pluck('title')->all())->toBe(['Raw', 'Old', 'New'])
        ->and(FeedbackItem::where('title', 'Raw')->first()->reporterActivity()->toDateTimeString())->toBe('2026-10-01 12:00:00');
});

it('backfills the reporter columns for reports that predate the upgrade', function () {
    $migration = require __DIR__.'/../../database/migrations/update_feedback_items_add_reporter_columns.php';
    $migration->down();

    DB::table('feedback_items')->insert([
        'project' => 'default', 'title' => 'Before', 'type' => 'bug', 'status' => 'new',
        'created_at' => '2026-01-01 08:00:00', 'updated_at' => '2026-01-02 10:00:00',
    ]);

    $migration->up();

    $row = DB::table('feedback_items')->where('title', 'Before')->first();
    expect($row->reporter_read_at)->toBe('2026-10-01 09:00:00')
        ->and($row->reporter_activity_at)->toBe('2026-01-02 10:00:00')
        ->and(Schema::hasIndex('feedback_comments', 'feedback_comments_unread_index'))->toBeTrue();
});

it('builds the unread and ordering queries with the connection\'s table prefix', function () {
    config()->set('database.connections.prefixed', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'fb_']);
    DB::setDefaultConnection('prefixed');
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
    });
    $files = glob(__DIR__.'/../../database/migrations/*.php');
    sort($files);
    foreach ($files as $file) {
        (require $file)->up();
    }

    $me = TestUser::create(['name' => 'Me', 'email' => 'me@x.com']);
    $item = FeedbackItem::factory()->for($me, 'reporter')->create();
    $this->travel(1)->minutes();
    FeedbackComment::factory()->for($item, 'item')->create(['author_role' => AuthorRole::Developer]);

    expect(DB::select("select name from sqlite_master where name = 'fb_feedback_items'"))->toHaveCount(1)
        ->and((int) FeedbackItem::withUnreadCount()->find($item->id)->unread_count)->toBe(1)
        ->and(FeedbackItem::ownedBy($me)->hasUnread()->count())->toBe(1)
        ->and(FeedbackItem::ownedBy($me)->latestForReporter()->first()->id)->toBe($item->id);
});
```

`tests/Feature/AttachmentRecordsTest.php`:

```php
<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage};
use YousefAman\FeedbackBoard\Models\{FeedbackAttachment, FeedbackComment, FeedbackItem};
use YousefAman\FeedbackBoard\Services\AttachmentStorage;

beforeEach(fn () => Storage::fake('local'));

it('uses the report\'s first own allow-listed image as its cover', function () {
    $item = FeedbackItem::factory()->create();
    $storage = app(AttachmentStorage::class);
    $comment = FeedbackComment::factory()->for($item, 'item')->create();

    $storage->store(UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'), $item);
    $storage->store(UploadedFile::fake()->image('from-comment.png'), $item, $comment);
    $first = $storage->store(UploadedFile::fake()->image('first.png'), $item);
    $storage->store(UploadedFile::fake()->image('second.png'), $item);

    $loaded = FeedbackItem::with('coverAttachment')->find($item->id);

    expect($loaded->coverAttachment->id)->toBe($first->id)
        ->and($loaded->coverAttachment->isImage())->toBeTrue()
        ->and($loaded->coverAttachment->url())->toBe(route('feedback-board.attachments.show', $first));
});

it('has no cover when the report has only files, comment images or non-allow-listed images', function () {
    $item = FeedbackItem::factory()->create();
    $comment = FeedbackComment::factory()->for($item, 'item')->create();
    app(AttachmentStorage::class)->store(UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'), $item);
    app(AttachmentStorage::class)->store(UploadedFile::fake()->image('in-comment.png'), $item, $comment);
    FeedbackAttachment::create([
        'feedback_item_id' => $item->id, 'disk' => 'local', 'path' => 'x/logo.svg',
        'original_name' => 'logo.svg', 'mime' => 'image/svg+xml', 'size' => 10,
    ]);

    expect(FeedbackItem::with('coverAttachment')->find($item->id)->coverAttachment)->toBeNull();
});

it('deletes a comment\'s attachments and, after the commit, their files', function () {
    $item = FeedbackItem::factory()->create();
    $note = FeedbackComment::factory()->for($item, 'item')->create(['is_internal' => true]);
    $attachment = app(AttachmentStorage::class)->store(UploadedFile::fake()->image('note.png'), $item, $note);

    DB::transaction(fn () => $note->delete());

    expect(FeedbackAttachment::find($attachment->id))->toBeNull();
    Storage::disk('local')->assertMissing($attachment->path);
});

it('keeps the attachment and its file when the comment delete rolls back', function () {
    $item = FeedbackItem::factory()->create();
    $note = FeedbackComment::factory()->for($item, 'item')->create(['is_internal' => true]);
    $attachment = app(AttachmentStorage::class)->store(UploadedFile::fake()->image('note.png'), $item, $note);

    try {
        DB::transaction(function () use ($note) {
            $note->delete();

            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(FeedbackAttachment::find($attachment->id)?->feedback_comment_id)->toBe($note->id);
    Storage::disk('local')->assertExists($attachment->path);
});

it('splits statuses into open and done', function () {
    expect(array_map(fn ($s) => $s->value, \YousefAman\FeedbackBoard\Enums\FeedbackStatus::openCases()))->toBe(['new', 'in_review', 'in_progress'])
        ->and(\YousefAman\FeedbackBoard\Enums\FeedbackStatus::Resolved->isOpen())->toBeFalse()
        ->and(\YousefAman\FeedbackBoard\Enums\FeedbackStatus::InReview->isOpen())->toBeTrue();
});
```

In `tests/Feature/InstallCommandTest.php`, replace `publishedFeedbackMigrations()` and the two `toHaveCount(4)` expectations:

```php
function publishedFeedbackMigrations(): array
{
    $files = [
        ...(glob(database_path('migrations/*_create_feedback_*_table.php')) ?: []),
        ...(glob(database_path('migrations/*_update_feedback_items_add_reporter_columns.php')) ?: []),
    ];
    sort($files);

    return $files;
}
```

Rename the first test to `'publishes the config and the five migrations, then prints the next steps'`, change both `->toHaveCount(4)` to `->toHaveCount(5)`, and add to the first test's final `expect(...)` chain:

```php
        ->and(basename(last(publishedFeedbackMigrations())))->toEndWith('_update_feedback_items_add_reporter_columns.php')
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/ImagesTest.php tests/Feature/ReporterActivityTest.php tests/Feature/AttachmentRecordsTest.php tests/Feature/InstallCommandTest.php`
Expected: FAIL — `Class "YousefAman\FeedbackBoard\Support\Images" not found`, `no such column: reporter_activity_at`, `Call to undefined method ... ownedBy()`, the install count is 4.

- [ ] **Step 3: Add the migration and register it**

Create `database/migrations/update_feedback_items_add_reporter_columns.php` (the name sorts after the `create_*` files, which the tests and `loadMigrationsFrom()` load in filename order):

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('feedback_items', function (Blueprint $t) {
            $t->timestamp('reporter_read_at')->nullable();
            $t->timestamp('reporter_activity_at')->nullable();
        });

        // No reply sent before the upgrade turns unread, and each reporter's list keeps its order.
        DB::table('feedback_items')->update([
            'reporter_read_at' => now(),
            'reporter_activity_at' => DB::raw(DB::getQueryGrammar()->wrap('updated_at')),
        ]);

        Schema::table('feedback_comments', function (Blueprint $t) {
            $t->index(['feedback_item_id', 'is_internal', 'created_at'], 'feedback_comments_unread_index');
        });
    }

    public function down(): void {
        Schema::table('feedback_comments', function (Blueprint $t) {
            $t->dropIndex('feedback_comments_unread_index');
        });

        Schema::table('feedback_items', function (Blueprint $t) {
            $t->dropColumn(['reporter_read_at', 'reporter_activity_at']);
        });
    }
};
```

In `src/FeedbackBoardServiceProvider.php`, append the new name to `hasMigrations()` (order matters: spatie/laravel-package-tools stamps published files one second apart in this order):

```php
            ->hasMigrations([
                'create_feedback_items_table',
                'create_feedback_comments_table',
                'create_feedback_attachments_table',
                'create_feedback_activities_table',
                'update_feedback_items_add_reporter_columns',
            ])
```

- [ ] **Step 4: Add `Images` and the status groups**

Create `src/Support/Images.php`:

```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Support;

/** The only images the package renders inline: thumbnails, covers, previews and the lightbox. */
final class Images
{
    /** Extension => the only MIME type an image with that extension may have. */
    public const MIMES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];

    /** @return list<string> */
    public static function mimes(): array
    {
        return array_values(array_unique(self::MIMES));
    }

    /** @return list<string> */
    public static function extensions(): array
    {
        return array_keys(self::MIMES);
    }

    // Both must agree: the stored MIME comes from the file's content, the extension from the
    // client's file name, and browsers pick a renderer from either.
    public static function matches(?string $mime, ?string $name): bool
    {
        if ($mime === null || $name === null) {
            return false;
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return (self::MIMES[$extension] ?? null) === strtolower($mime);
    }
}
```

In `src/Enums/FeedbackStatus.php`, add after `isTerminal()`:

```php
    /** @return list<self> the statuses a report is still being worked in */
    public static function openCases(): array
    {
        return [self::New, self::InReview, self::InProgress];
    }

    public function isOpen(): bool
    {
        return in_array($this, self::openCases(), true);
    }
```

- [ ] **Step 5: Extend the models**

Replace `src/Models/FeedbackItem.php` with:

```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{HasMany, HasOne, MorphTo};
use YousefAman\FeedbackBoard\Database\Factories\FeedbackItemFactory;
use YousefAman\FeedbackBoard\Enums\{AuthorRole, FeedbackPriority, FeedbackStatus, FeedbackType};
use YousefAman\FeedbackBoard\Support\Images;

class FeedbackItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'project', 'reporter_type', 'reporter_id', 'reporter_name', 'reporter_email',
        'title', 'description', 'type', 'status', 'priority',
        'assignee_type', 'assignee_id', 'meta', 'resolved_at',
    ];

    protected $casts = [
        'type' => FeedbackType::class,
        'status' => FeedbackStatus::class,
        'priority' => FeedbackPriority::class,
        'meta' => 'array', 'external_refs' => 'array', 'ai_state' => 'array',
        'resolved_at' => 'datetime',
        'reporter_read_at' => 'datetime',
        'reporter_activity_at' => 'datetime',
    ];

    public function reporter(): MorphTo { return $this->morphTo(); }
    public function assignee(): MorphTo { return $this->morphTo(); }
    public function comments(): HasMany { return $this->hasMany(FeedbackComment::class); }
    public function attachments(): HasMany { return $this->hasMany(FeedbackAttachment::class); }
    public function activities(): HasMany { return $this->hasMany(FeedbackActivity::class)->latest('created_at'); }

    /** The report's first own allow-listed image (never a comment's): the Kanban cover. */
    public function coverAttachment(): HasOne
    {
        return $this->hasOne(FeedbackAttachment::class)->ofMany(['id' => 'min'], function (Builder $query) {
            $query->whereNull('feedback_comment_id')->whereIn('mime', Images::mimes());
        });
    }

    public function scopeOwnedBy(Builder $query, mixed $user): Builder
    {
        if (! $user instanceof Model) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('reporter_type', $user->getMorphClass())->where('reporter_id', $user->getKey());
    }

    public function scopeWithUnreadCount(Builder $query): Builder
    {
        return $query->withCount(['comments as unread_count' => fn (Builder $comments) => static::unreadComments($comments)]);
    }

    public function scopeHasUnread(Builder $query): Builder
    {
        return $query->whereHas('comments', fn (Builder $comments) => static::unreadComments($comments));
    }

    /** Newest reporter-visible activity first; a NULL falls back to creation on every database. */
    public function scopeLatestForReporter(Builder $query): Builder
    {
        $grammar = $query->getQuery()->getGrammar();
        $table = $this->getTable();

        return $query
            ->orderByRaw('coalesce('.$grammar->wrap($table.'.reporter_activity_at').', '.$grammar->wrap($table.'.created_at').') desc')
            ->orderByDesc($table.'.id');
    }

    public function isReportedBy(mixed $user): bool
    {
        return $user instanceof Model
            && $this->reporter_type === $user->getMorphClass()
            && (string) $this->reporter_id === (string) $user->getKey();
    }

    /** Records that the reporter has seen the replies so far. Leaves updated_at alone. */
    public function markReadBy(mixed $user): bool
    {
        if (! $this->isReportedBy($user)) {
            return false;
        }

        $now = $this->freshTimestamp();
        static::query()->whereKey($this->getKey())->toBase()->update(['reporter_read_at' => $now]);
        $this->forceFill(['reporter_read_at' => $now])->syncOriginalAttribute('reporter_read_at');

        return true;
    }

    /** Replies after this moment are unread: the last read, or the report's creation. */
    public function readMarker(): ?CarbonInterface
    {
        return $this->reporter_read_at ?? $this->created_at;
    }

    /** The "last update" the reporter sees. */
    public function reporterActivity(): ?CarbonInterface
    {
        return $this->reporter_activity_at ?? $this->created_at;
    }

    /**
     * Non-internal comments by anyone but the reporter (developer and AI replies, which also
     * e-mail the reporter), newer than the reporter's last read. Second precision; a reply in
     * the same second as the read is treated as read.
     */
    protected static function unreadComments(Builder $comments): void
    {
        $grammar = $comments->getQuery()->getGrammar();
        $items = (new static)->getTable();
        $table = $comments->getModel()->getTable();

        $comments->where($table.'.is_internal', false)
            ->where($table.'.author_role', '!=', AuthorRole::Reporter->value)
            ->whereRaw(
                $grammar->wrap($table.'.created_at').' > coalesce('
                .$grammar->wrap($items.'.reporter_read_at').', '
                .$grammar->wrap($items.'.created_at').')'
            );
    }

    protected static function booted(): void
    {
        // Covers factories and host code too: the reporter's "last update" starts at creation.
        static::creating(fn (FeedbackItem $item) => $item->reporter_activity_at ??= $item->freshTimestamp());
    }

    protected static function newFactory(): FeedbackItemFactory { return FeedbackItemFactory::new(); }
}
```

Replace `src/Models/FeedbackComment.php` with:

```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany, MorphTo};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use YousefAman\FeedbackBoard\Database\Factories\FeedbackCommentFactory;
use YousefAman\FeedbackBoard\Enums\AuthorRole;

class FeedbackComment extends Model
{
    use HasFactory;

    protected $fillable = ['feedback_item_id', 'author_type', 'author_id', 'author_role', 'body', 'is_internal'];
    protected $casts = ['author_role' => AuthorRole::class, 'is_internal' => 'bool'];

    /** @var list<array{0: string, 1: string}> disk and path of each attachment deleted with this comment */
    protected array $removedFiles = [];

    public function item(): BelongsTo { return $this->belongsTo(FeedbackItem::class, 'feedback_item_id'); }
    public function author(): MorphTo { return $this->morphTo(); }
    public function attachments(): HasMany { return $this->hasMany(FeedbackAttachment::class); }

    protected static function booted(): void
    {
        // Without this the nullOnDelete foreign key would turn a deleted (possibly internal)
        // comment's images into report-level attachments.
        static::deleting(function (FeedbackComment $comment) {
            $comment->removedFiles = $comment->attachments()->get(['id', 'disk', 'path'])
                ->map(fn (FeedbackAttachment $attachment) => [$attachment->disk, $attachment->path])
                ->all();

            $comment->attachments()->delete();
        });

        // Disk deletes cannot roll back, so they wait for the commit.
        static::deleted(function (FeedbackComment $comment) {
            $files = $comment->removedFiles;

            DB::afterCommit(function () use ($files) {
                foreach ($files as [$disk, $path]) {
                    rescue(fn () => Storage::disk($disk)->delete($path), report: true);
                }
            });
        });
    }

    protected static function newFactory(): FeedbackCommentFactory { return FeedbackCommentFactory::new(); }
}
```

Replace `src/Models/FeedbackAttachment.php` with:

```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use YousefAman\FeedbackBoard\Support\Images;

class FeedbackAttachment extends Model
{
    protected $fillable = ['feedback_item_id', 'feedback_comment_id', 'disk', 'path', 'original_name', 'mime', 'size'];

    public function item(): BelongsTo { return $this->belongsTo(FeedbackItem::class, 'feedback_item_id'); }
    public function comment(): BelongsTo { return $this->belongsTo(FeedbackComment::class, 'feedback_comment_id'); }

    /** An allow-listed image whose stored type and name agree; served inline, everything else downloads. */
    public function isImage(): bool
    {
        return Images::matches($this->mime, $this->original_name);
    }

    public function url(): string
    {
        return route('feedback-board.attachments.show', $this);
    }
}
```

In `src/Policies/FeedbackItemPolicy.php`, replace the body of `owns()`:

```php
    protected function owns(?Model $user, FeedbackItem $item): bool
    {
        return $item->isReportedBy($user);
    }
```

- [ ] **Step 6: Write the reporter activity from the actions**

In `src/Actions/ReplyToFeedback.php`, inside the transaction right after the comment is created:

```php
            $comment = $item->comments()->create([
                'author_type' => $author?->getMorphClass(),
                'author_id' => $author?->getKey(),
                'author_role' => $role,
                'body' => $body,
                'is_internal' => $isInternal,
            ]);

            // The reporter sees public replies (including ingest and AI comments) as activity.
            if (! $isInternal) {
                $item->forceFill(['reporter_activity_at' => $item->freshTimestamp()])->save();
            }
```

In `src/Actions/TransitionStatus.php`, set it before the save:

```php
            $item->status = $to;
            $item->resolved_at = $to === FeedbackStatus::Resolved ? now() : ($to->isTerminal() ? $item->resolved_at : null);
            $item->reporter_activity_at = $item->freshTimestamp();
            $item->save();
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/ImagesTest.php tests/Feature/ReporterActivityTest.php tests/Feature/AttachmentRecordsTest.php tests/Feature/InstallCommandTest.php`
Expected: PASS.

Run: `php -l` on every changed PHP file, then `vendor/bin/pest`.
Expected: no failures.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/update_feedback_items_add_reporter_columns.php src/Support/Images.php src/FeedbackBoardServiceProvider.php src/Models src/Enums/FeedbackStatus.php src/Actions/ReplyToFeedback.php src/Actions/TransitionStatus.php src/Policies/FeedbackItemPolicy.php tests/Unit/ImagesTest.php tests/Feature/ReporterActivityTest.php tests/Feature/AttachmentRecordsTest.php tests/Feature/InstallCommandTest.php
git commit -m "feat(data): reporter read and activity columns, unread queries, report covers and comment-attachment cleanup"
```

---

### Task 2: Activity log — priority and type changes, assignee types, full sentences

**Files:**
- Create: `src/Actions/SetPriority.php`
- Create: `src/Support/Person.php`
- Modify: `src/Enums/ActivityAction.php`
- Modify: `src/Actions/ApplyTriageSuggestion.php`, `src/Actions/AssignFeedback.php`
- Modify: `src/Models/FeedbackActivity.php`
- Modify: `src/Livewire/FeedbackDetail.php` (`setPriority()` only)
- Modify: `resources/lang/en/feedback-board.php`, `resources/lang/ar/feedback-board.php`
- Test: `tests/Feature/ActivityLogTest.php`

**Interfaces:**
- Consumes: Task 1's `reporter_activity_at` (asserted unchanged by priority writes).
- Produces:
  - `ActivityAction::PriorityChanged` (`priority_changed`), `ActivityAction::TypeChanged` (`type_changed`).
  - `SetPriority::handle(FeedbackItem $item, ?FeedbackPriority $priority, ?Model $actor): FeedbackItem` — logs `{from, to}`, no-op when unchanged. Callers authorize.
  - `AssignFeedback` stores `properties = {assignee_id, assignee_type}`.
  - `FeedbackActivity::sentence(?string $actor, ?string $assignee = null): string` and `FeedbackActivity::assigneeNames(iterable $activities): array<int, string>` (activity id ⇒ assignee name; pass activities with `actor` loaded).
  - `Person::name(?Model $model, int|string|null $id = null): ?string` — name, then e-mail, then `#id`.
  - `FeedbackDetail::setPriority('')` clears the priority.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/ActivityLogTest.php`:

```php
<?php

use Livewire\Livewire;
use YousefAman\FeedbackBoard\Actions\{ApplyTriageSuggestion, AssignFeedback, SetPriority};
use YousefAman\FeedbackBoard\Enums\{ActivityAction, FeedbackPriority, FeedbackStatus, FeedbackType};
use YousefAman\FeedbackBoard\Livewire\FeedbackDetail;
use YousefAman\FeedbackBoard\Models\{FeedbackActivity, FeedbackItem};
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(fn () => config()->set('feedback-board.authorization.developer_resolver', fn ($u) => $u->name === 'Dev'));

it('logs a priority change and a clear, and nothing when the value is unchanged', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $item = FeedbackItem::factory()->create(['priority' => null]);
    $before = $item->fresh()->reporter_activity_at;
    $action = app(SetPriority::class);

    $this->travel(1)->hours();
    $action->handle($item, FeedbackPriority::High, $dev);
    $action->handle($item, FeedbackPriority::High, $dev);
    $action->handle($item, null, $dev);

    $rows = $item->activities()->where('action', ActivityAction::PriorityChanged)->reorder('id')->get();

    expect($item->fresh()->priority)->toBeNull()
        ->and($rows)->toHaveCount(2)
        ->and($rows[0]->properties)->toBe(['from' => null, 'to' => 'high'])
        ->and($rows[1]->properties)->toBe(['from' => 'high', 'to' => null])
        ->and($item->fresh()->reporter_activity_at)->toEqual($before);
});

it('sets and clears the priority from the developer detail', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $item = FeedbackItem::factory()->create(['priority' => FeedbackPriority::Low]);

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->call('setPriority', '')->assertHasNoErrors()
        ->call('setPriority', 'nope')->assertStatus(422);

    expect($item->fresh()->priority)->toBeNull()
        ->and($item->activities()->where('action', ActivityAction::PriorityChanged)->count())->toBe(1);
});

it('logs type and priority changes applied from a triage suggestion, once', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $item = FeedbackItem::factory()->create(['type' => FeedbackType::Bug, 'priority' => null]);
    $item->forceFill(['ai_state' => ['triage' => [
        'status' => 'completed', 'suggested_type' => 'feature', 'suggested_priority' => 'urgent',
    ]]])->save();

    app(ApplyTriageSuggestion::class)->handle($item, $dev, ['type', 'priority']);
    app(ApplyTriageSuggestion::class)->handle($item, $dev, ['type', 'priority']);

    $type = $item->activities()->where('action', ActivityAction::TypeChanged)->get();

    expect($type)->toHaveCount(1)
        ->and($type[0]->properties)->toBe(['from' => 'bug', 'to' => 'feature'])
        ->and($item->activities()->where('action', ActivityAction::PriorityChanged)->count())->toBe(1)
        ->and($item->fresh()->type)->toBe(FeedbackType::Feature)
        ->and($item->fresh()->priority)->toBe(FeedbackPriority::Urgent);
});

it('stores the assignee type with an assignment', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $item = FeedbackItem::factory()->create();

    app(AssignFeedback::class)->handle($item, $dev, $dev);
    app(AssignFeedback::class)->handle($item, null, $dev);

    $rows = $item->activities()->where('action', ActivityAction::Assigned)->reorder('id')->get();

    expect($rows[0]->properties)->toBe(['assignee_id' => $dev->id, 'assignee_type' => $dev->getMorphClass()])
        ->and($rows[1]->properties)->toBe(['assignee_id' => null, 'assignee_type' => null]);
});

it('writes full activity sentences, with a fallback for a missing actor', function () {
    $item = FeedbackItem::factory()->create();
    $make = fn (array $attributes) => FeedbackActivity::create(['feedback_item_id' => $item->id, ...$attributes]);

    expect($make(['action' => ActivityAction::Created])->sentence('Lena'))->toBe('Lena created the report')
        ->and($make(['action' => ActivityAction::Created])->sentence(null))->toBe('Someone created the report')
        ->and($make(['action' => ActivityAction::StatusChanged, 'from_status' => FeedbackStatus::New, 'to_status' => FeedbackStatus::InReview])->sentence('Dev'))
            ->toBe('Dev moved the report from “New” to “In review”')
        ->and($make(['action' => ActivityAction::StatusChanged, 'to_status' => FeedbackStatus::Resolved])->sentence('Dev'))
            ->toBe('Dev moved the report to “Resolved”')
        ->and($make(['action' => ActivityAction::PriorityChanged, 'properties' => ['from' => null, 'to' => 'high']])->sentence('Dev'))
            ->toBe('Dev changed the priority to “High”')
        ->and($make(['action' => ActivityAction::PriorityChanged, 'properties' => ['from' => 'high', 'to' => null]])->sentence('Dev'))
            ->toBe('Dev cleared the priority')
        ->and($make(['action' => ActivityAction::TypeChanged, 'properties' => ['from' => 'bug', 'to' => 'feature']])->sentence('Dev'))
            ->toBe('Dev changed the type to “Feature request”')
        ->and($make(['action' => ActivityAction::Assigned, 'properties' => ['assignee_id' => null]])->sentence('Dev'))
            ->toBe('Dev removed the assignee')
        ->and($make(['action' => ActivityAction::Replied])->sentence('Dev'))->toBe('Dev replied');

    app()->setLocale('ar');

    expect($make(['action' => ActivityAction::Assigned, 'properties' => ['assignee_id' => null]])->sentence('Dev'))
        ->toBe('ألغى Dev الإسناد');
});

it('resolves assignee names, and for old rows only when the actor assigned themselves', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $sam = TestUser::create(['name' => null, 'email' => 'sam@x.com']);
    $item = FeedbackItem::factory()->create();
    $make = fn (array $properties) => FeedbackActivity::create([
        'feedback_item_id' => $item->id, 'action' => ActivityAction::Assigned,
        'actor_type' => $dev->getMorphClass(), 'actor_id' => $dev->id, 'properties' => $properties,
    ]);

    $typed = $make(['assignee_id' => $sam->id, 'assignee_type' => $sam->getMorphClass()]);
    $oldSelf = $make(['assignee_id' => $dev->id]);
    $oldOther = $make(['assignee_id' => 99]);
    $gone = $make(['assignee_id' => 98, 'assignee_type' => $dev->getMorphClass()]);

    $names = FeedbackActivity::assigneeNames(FeedbackActivity::with('actor')->whereKey([$typed->id, $oldSelf->id, $oldOther->id, $gone->id])->get());

    expect($names)->toEqual([$typed->id => 'sam@x.com', $oldSelf->id => 'Dev', $oldOther->id => '#99', $gone->id => '#98'])
        ->and($typed->sentence('Dev', $names[$typed->id]))->toBe('Dev assigned the report to sam@x.com');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/ActivityLogTest.php`
Expected: FAIL — `Class "YousefAman\FeedbackBoard\Actions\SetPriority" not found`, undefined constant `ActivityAction::PriorityChanged`.

- [ ] **Step 3: Add the enum cases, `Person` and `SetPriority`**

In `src/Enums/ActivityAction.php`, add after `case Assigned = 'assigned';`:

```php
    case PriorityChanged = 'priority_changed';
    case TypeChanged = 'type_changed';
```

Create `src/Support/Person.php`:

```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Support;

use Illuminate\Database\Eloquent\Model;

/** Display names for the people on a report: users of the host app, whatever their columns. */
final class Person
{
    /** The name, then the e-mail, then "#id"; null when there is nothing to show. */
    public static function name(?Model $model, int|string|null $id = null): ?string
    {
        foreach (['name', 'email'] as $attribute) {
            $value = data_get($model, $attribute);

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        $id ??= $model?->getKey();

        return $id === null || $id === '' ? null : '#'.$id;
    }
}
```

Create `src/Actions/SetPriority.php`:

```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use YousefAman\FeedbackBoard\Enums\{ActivityAction, FeedbackPriority};
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class SetPriority
{
    /** Sets or clears (null) the priority and logs the change; an unchanged value writes nothing. */
    public function handle(FeedbackItem $item, ?FeedbackPriority $priority, ?Model $actor): FeedbackItem
    {
        $from = $item->priority;

        if ($from === $priority) {
            return $item;
        }

        return DB::transaction(function () use ($item, $from, $priority, $actor) {
            $item->priority = $priority;
            $item->save();

            $item->activities()->create([
                'actor_type' => $actor?->getMorphClass(),
                'actor_id' => $actor?->getKey(),
                'action' => ActivityAction::PriorityChanged,
                'properties' => ['from' => $from?->value, 'to' => $priority?->value],
            ]);

            return $item;
        });
    }
}
```

- [ ] **Step 4: Log triage changes and the assignee type**

Replace `src/Actions/ApplyTriageSuggestion.php` with:

```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use YousefAman\FeedbackBoard\Enums\{ActivityAction, FeedbackPriority, FeedbackType};
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy;

class ApplyTriageSuggestion
{
    public function __construct(private SetPriority $priority) {}

    /**
     * Apply a suggestion to canonical columns. Whitelists type/priority, only applies values that
     * pass tryFrom, and logs each change. Writes type/priority and their activity rows only — no
     * ai_state — so it is disjoint from concurrent triage writes (no lock needed).
     *
     * @param array<int, string> $fields  subset of ['type', 'priority']
     */
    public function handle(FeedbackItem $item, ?Model $actor, array $fields): void
    {
        abort_unless(FeedbackItemPolicy::isDeveloper($actor), 403);

        $fresh = FeedbackItem::findOrFail($item->id);
        $triage = $fresh->ai_state['triage'] ?? null;
        abort_unless(is_array($triage) && ($triage['status'] ?? null) === 'completed', 422);

        if (in_array('type', $fields, true)
            && ($type = FeedbackType::tryFrom((string) ($triage['suggested_type'] ?? ''))) !== null) {
            $this->setType($fresh, $type, $actor);
        }

        if (in_array('priority', $fields, true)
            && ($priority = FeedbackPriority::tryFrom((string) ($triage['suggested_priority'] ?? ''))) !== null) {
            $this->priority->handle($fresh, $priority, $actor);
        }
    }

    private function setType(FeedbackItem $item, FeedbackType $type, ?Model $actor): void
    {
        $from = $item->type;

        if ($from === $type) {
            return;
        }

        DB::transaction(function () use ($item, $from, $type, $actor) {
            $item->type = $type;
            $item->save();

            $item->activities()->create([
                'actor_type' => $actor?->getMorphClass(),
                'actor_id' => $actor?->getKey(),
                'action' => ActivityAction::TypeChanged,
                'properties' => ['from' => $from?->value, 'to' => $type->value],
            ]);
        });
    }
}
```

In `src/Actions/AssignFeedback.php`, change the activity properties:

```php
                'properties' => ['assignee_id' => $assignee?->getKey(), 'assignee_type' => $assignee?->getMorphClass()],
```

- [ ] **Step 5: Add `sentence()` and `assigneeNames()`**

Replace `src/Models/FeedbackActivity.php` with:

```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, MorphTo, Relation};
use YousefAman\FeedbackBoard\Enums\{ActivityAction, FeedbackPriority, FeedbackStatus, FeedbackType};
use YousefAman\FeedbackBoard\Support\Person;

class FeedbackActivity extends Model
{
    public $timestamps = false;
    protected $fillable = ['feedback_item_id', 'actor_type', 'actor_id', 'action', 'from_status', 'to_status', 'properties', 'created_at'];
    protected $casts = [
        'action' => ActivityAction::class,
        'from_status' => FeedbackStatus::class,
        'to_status' => FeedbackStatus::class,
        'properties' => 'array',
        'created_at' => 'datetime',
    ];

    public function item(): BelongsTo { return $this->belongsTo(FeedbackItem::class, 'feedback_item_id'); }
    public function actor(): MorphTo { return $this->morphTo(); }

    /** One-line text for the item timeline. */
    public function summary(): string
    {
        $properties = (array) $this->properties;

        return match (true) {
            $this->action === ActivityAction::StatusChanged && $this->to_status !== null
                => $this->action->label().': '.$this->to_status->label(),
            // AssignFeedback records an unassignment as Assigned with assignee_id = null.
            $this->action === ActivityAction::Assigned
                && array_key_exists('assignee_id', $properties) && $properties['assignee_id'] === null
                => __('feedback-board::feedback-board.activity.unassigned'),
            default => $this->action->label(),
        };
    }

    /**
     * A full line for the activity feed. Names are resolved (and eager-loaded) by the caller;
     * a missing actor reads as "Someone".
     */
    public function sentence(?string $actor, ?string $assignee = null): string
    {
        $key = 'feedback-board::feedback-board.activity.sentence.';
        $actor = filled($actor) ? $actor : __('feedback-board::feedback-board.activity.someone');
        $properties = (array) $this->properties;
        $to = $properties['to'] ?? null;

        return match ($this->action) {
            ActivityAction::Created => __($key.'created', ['actor' => $actor]),
            ActivityAction::StatusChanged => $this->from_status !== null
                ? __($key.'status_changed', ['actor' => $actor, 'from' => $this->from_status->label(), 'to' => $this->to_status?->label() ?? ''])
                : __($key.'status_set', ['actor' => $actor, 'to' => $this->to_status?->label() ?? '']),
            ActivityAction::Assigned => array_key_exists('assignee_id', $properties) && $properties['assignee_id'] === null
                ? __($key.'unassigned', ['actor' => $actor])
                : __($key.'assigned', ['actor' => $actor, 'assignee' => filled($assignee)
                    ? $assignee
                    : (isset($properties['assignee_id']) ? '#'.$properties['assignee_id'] : __('feedback-board::feedback-board.activity.someone'))]),
            ActivityAction::PriorityChanged => ($priority = FeedbackPriority::tryFrom((string) $to)) !== null
                ? __($key.'priority_changed', ['actor' => $actor, 'to' => $priority->label()])
                : __($key.'priority_cleared', ['actor' => $actor]),
            ActivityAction::TypeChanged => __($key.'type_changed', ['actor' => $actor, 'to' => FeedbackType::tryFrom((string) $to)?->label() ?? (string) $to]),
            ActivityAction::Replied => __($key.'replied', ['actor' => $actor]),
        };
    }

    /**
     * Assignee names for a page of activities, one query per assignee model type. Rows written
     * before assignee_type was stored resolve only when the actor assigned themselves ("Assign to
     * me" was the only way to assign then); otherwise they read "#id".
     *
     * @param  iterable<FeedbackActivity>  $activities  with `actor` loaded
     * @return array<int, string>  activity id => name
     */
    public static function assigneeNames(iterable $activities): array
    {
        $assignments = [];

        foreach ($activities as $activity) {
            $properties = (array) $activity->properties;

            if ($activity->action === ActivityAction::Assigned && ($properties['assignee_id'] ?? null) !== null) {
                $assignments[] = [$activity, $properties['assignee_id'], $properties['assignee_type'] ?? null];
            }
        }

        $models = [];

        foreach (collect($assignments)->filter(fn ($row) => is_string($row[2]) && $row[2] !== '')->groupBy(fn ($row) => $row[2]) as $type => $rows) {
            $class = Relation::getMorphedModel($type) ?? $type;

            if (! is_subclass_of($class, Model::class)) {
                continue;
            }

            foreach ($class::query()->whereKey($rows->pluck(1)->unique()->values()->all())->get() as $model) {
                $models[$type][(string) $model->getKey()] = $model;
            }
        }

        $names = [];

        foreach ($assignments as [$activity, $id, $type]) {
            $model = is_string($type) ? ($models[$type][(string) $id] ?? null) : null;

            if ($model === null && $type === null && $activity->actor instanceof Model && (string) $activity->actor_id === (string) $id) {
                $model = $activity->actor;
            }

            $names[$activity->id] = Person::name($model) ?? '#'.$id;
        }

        return $names;
    }

    protected static function booted(): void
    {
        static::creating(fn ($a) => $a->created_at ??= now());
    }
}
```

- [ ] **Step 6: Let the detail clear the priority through `SetPriority`**

In `src/Livewire/FeedbackDetail.php`, add `use YousefAman\FeedbackBoard\Actions\SetPriority;` and replace `setPriority()`:

```php
    public function setPriority(string $priority, SetPriority $action): void
    {
        $this->guard();
        $p = $priority === '' ? null : (FeedbackPriority::tryFrom($priority) ?? abort(422));
        $action->handle($this->item, $p, auth()->user());
        $this->item->refresh();
    }
```

- [ ] **Step 7: Add the translations**

In `resources/lang/en/feedback-board.php`, replace the `'activity' => [...]` line with:

```php
    'activity' => [
        'created' => 'Created', 'status_changed' => 'Status changed', 'replied' => 'Replied', 'assigned' => 'Assigned', 'unassigned' => 'Unassigned',
        'priority_changed' => 'Priority changed', 'type_changed' => 'Type changed',
        'someone' => 'Someone',
        'sentence' => [
            'created' => ':actor created the report',
            'status_changed' => ':actor moved the report from “:from” to “:to”',
            'status_set' => ':actor moved the report to “:to”',
            'assigned' => ':actor assigned the report to :assignee',
            'unassigned' => ':actor removed the assignee',
            'priority_changed' => ':actor changed the priority to “:to”',
            'priority_cleared' => ':actor cleared the priority',
            'type_changed' => ':actor changed the type to “:to”',
            'replied' => ':actor replied',
        ],
    ],
```

In `resources/lang/ar/feedback-board.php`, replace the `'activity' => [...]` line with:

```php
    'activity' => [
        'created' => 'أُنشئ', 'status_changed' => 'تغيّرت الحالة', 'replied' => 'رُدّ عليه', 'assigned' => 'أُسند', 'unassigned' => 'أُلغي الإسناد',
        'priority_changed' => 'تغيّرت الأولوية', 'type_changed' => 'تغيّر النوع',
        'someone' => 'شخص ما',
        'sentence' => [
            'created' => 'أنشأ :actor البلاغ',
            'status_changed' => 'نقل :actor البلاغ من «:from» إلى «:to»',
            'status_set' => 'نقل :actor البلاغ إلى «:to»',
            'assigned' => 'أسند :actor البلاغ إلى :assignee',
            'unassigned' => 'ألغى :actor الإسناد',
            'priority_changed' => 'غيّر :actor الأولوية إلى «:to»',
            'priority_cleared' => 'أزال :actor الأولوية',
            'type_changed' => 'غيّر :actor النوع إلى «:to»',
            'replied' => 'ردّ :actor',
        ],
    ],
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/ActivityLogTest.php tests/Feature/Triage/TriageActionsTest.php tests/Unit/EnumsTest.php tests/Unit/DataLayerTest.php`
Expected: PASS.

Run: `php -l` on the changed PHP files, then `vendor/bin/pest`.
Expected: no failures.

- [ ] **Step 9: Commit**

```bash
git add src/Enums/ActivityAction.php src/Actions/SetPriority.php src/Actions/ApplyTriageSuggestion.php src/Actions/AssignFeedback.php src/Models/FeedbackActivity.php src/Support/Person.php src/Livewire/FeedbackDetail.php resources/lang tests/Feature/ActivityLogTest.php
git commit -m "feat(activity): log priority and type changes, store assignee types, write full activity sentences"
```

---

### Task 3: Serve allow-listed images inline; guard internal-note images; 404 for missing files

**Files:**
- Modify: `src/Http/Controllers/AttachmentController.php`
- Test: `tests/Feature/AttachmentTest.php`

**Interfaces:**
- Consumes: `FeedbackAttachment::isImage()`, `FeedbackComment` relation `comment()` (Task 1).
- Produces: `GET feedback-board.attachments.show` renders allow-listed images inline with `Content-Type` from the allow-list, `X-Content-Type-Options: nosniff`, `Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox`, `Cache-Control: private, max-age=3600`. Everything else (SVG included) downloads. An internal note's attachment needs a developer. A missing file is a 404.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/AttachmentTest.php` (add `use YousefAman\FeedbackBoard\Enums\AuthorRole;` and `use YousefAman\FeedbackBoard\Models\{FeedbackAttachment, FeedbackComment};` to the imports):

```php
function fbClientWithItem(): array
{
    config()->set('feedback-board.authorization.developer_resolver', fn ($u) => $u->name === 'Dev');
    $client = TestUser::create(['name' => 'Client', 'email' => 'c@x.com']);

    return [$client, FeedbackItem::factory()->for($client, 'reporter')->create()];
}

it('serves allow-listed images inline under a locked-down policy', function () {
    [$client, $item] = fbClientWithItem();
    $attachment = app(AttachmentStorage::class)->store(UploadedFile::fake()->image('shot.png'), $item);

    $response = $this->actingAs($client)->get(route('feedback-board.attachments.show', $attachment))->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox")
        ->and($response->headers->get('Cache-Control'))->toContain('private')->toContain('max-age=3600');
});

it('downloads SVG, PDF and type/extension mismatches instead of rendering them', function (string $name, string $mime) {
    [$client, $item] = fbClientWithItem();
    $attachment = FeedbackAttachment::create([
        'feedback_item_id' => $item->id, 'disk' => 'local', 'path' => 'feedback-board/'.$item->id.'/'.$name,
        'original_name' => $name, 'mime' => $mime, 'size' => 3,
    ]);
    Storage::disk('local')->put($attachment->path, 'abc');

    $response = $this->actingAs($client)->get(route('feedback-board.attachments.show', $attachment))->assertOk();

    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment');
})->with([
    ['logo.svg', 'image/svg+xml'],
    ['spec.pdf', 'application/pdf'],
    ['photo.png', 'image/jpeg'],
    ['page.png', 'text/html'],
]);

it('returns 404 when the file is gone from the disk', function () {
    [$client, $item] = fbClientWithItem();
    $attachment = app(AttachmentStorage::class)->store(UploadedFile::fake()->image('shot.png'), $item);
    Storage::disk('local')->delete($attachment->path);

    $this->actingAs($client)->get(route('feedback-board.attachments.show', $attachment))->assertNotFound();
});

it('serves an internal note\'s images to developers only', function () {
    [$client, $item] = fbClientWithItem();
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $note = FeedbackComment::factory()->for($item, 'item')->create(['is_internal' => true, 'author_role' => AuthorRole::Developer]);
    $reply = FeedbackComment::factory()->for($item, 'item')->create(['is_internal' => false, 'author_role' => AuthorRole::Developer]);
    $secret = app(AttachmentStorage::class)->store(UploadedFile::fake()->image('secret.png'), $item, $note);
    $public = app(AttachmentStorage::class)->store(UploadedFile::fake()->image('public.png'), $item, $reply);

    $this->actingAs($client)->get(route('feedback-board.attachments.show', $secret))->assertForbidden();
    $this->actingAs($client)->get(route('feedback-board.attachments.show', $public))->assertOk();
    $this->actingAs($dev)->get(route('feedback-board.attachments.show', $secret))->assertOk();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/AttachmentTest.php`
Expected: FAIL — images download with `attachment`, the internal-note image is served to the reporter, the missing file throws a 500.

- [ ] **Step 3: Implement**

Replace `src/Http/Controllers/AttachmentController.php` with:

```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Http\Controllers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use YousefAman\FeedbackBoard\Models\FeedbackAttachment;
use YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy;

class AttachmentController
{
    // Allow-listed images render inline (thumbnails, covers, the lightbox) under a policy that
    // allows no script and sandboxes the document; everything else, SVG included, downloads.
    private const IMAGE_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        'Cache-Control' => 'private, max-age=3600',
    ];

    public function show(FeedbackAttachment $attachment): StreamedResponse
    {
        abort_unless(Gate::allows('view', $attachment->item), 403);

        // Internal notes are developer-only, and so are their images.
        if ($attachment->comment?->is_internal) {
            abort_unless(FeedbackItemPolicy::isDeveloper(auth()->user()), 403);
        }

        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists($attachment->path), 404);

        if ($attachment->isImage()) {
            return $disk->response($attachment->path, $attachment->original_name, [
                ...self::IMAGE_HEADERS,
                'Content-Type' => strtolower((string) $attachment->mime), // allow-listed by isImage()
            ]);
        }

        return $disk->download($attachment->path, $attachment->original_name);
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/AttachmentTest.php`
Expected: PASS.

Run: `php -l src/Http/Controllers/AttachmentController.php`, then `vendor/bin/pest`.
Expected: no failures.

- [ ] **Step 5: Commit**

```bash
git add src/Http/Controllers/AttachmentController.php tests/Feature/AttachmentTest.php
git commit -m "feat(attachments): serve allow-listed images inline, guard internal-note images, 404 missing files"
```

---

### Task 4: Composer image rules, the file limit, and image-only replies on outbound channels

**Files:**
- Modify: `config/feedback-board.php` (`attachments.max_files`)
- Modify: `src/Services/AttachmentStorage.php`
- Modify: `src/Notifications/FeedbackRepliedNotification.php`
- Modify: `src/Destinations/Drivers/GithubIssueDriver.php`
- Modify: `resources/lang/en/feedback-board.php`, `resources/lang/ar/feedback-board.php` (`mail.reply_images`)
- Test: `tests/Feature/AttachmentRulesTest.php` (new), `tests/Feature/NotificationTest.php`, `tests/Feature/Destinations/GithubIssueDriverTest.php`, `tests/Feature/Destinations/WebhookPayloadTest.php`

**Interfaces:**
- Consumes: `Images::extensions()` (Task 1).
- Produces:
  - `AttachmentStorage::imageMimes(): list<string>` — the configured extensions that are allow-listed images, in `Images::extensions()` order.
  - `AttachmentStorage::imageRules(): array` — `['file', 'mimes:<imageMimes>', 'max:<max_kb>']`.
  - `AttachmentStorage::maxFiles(): int` — `attachments.max_files`, at least 1 (default 10).
  - An image-only reply (empty body) mails `mail.reply_images`, posts no GitHub comment, and keeps `body: ''` in the webhook payload.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/AttachmentRulesTest.php`:

```php
<?php

use YousefAman\FeedbackBoard\Services\AttachmentStorage;

it('limits composer uploads to the configured image types', function () {
    config()->set('feedback-board.attachments.allowed_mimes', ['PNG', 'jpg', 'pdf', 'svg']);
    $storage = app(AttachmentStorage::class);

    expect($storage->imageMimes())->toBe(['jpg', 'png'])
        ->and($storage->imageRules())->toBe(['file', 'mimes:jpg,png', 'max:10240']);
});

it('has no composer image types when the host allows none', function () {
    config()->set('feedback-board.attachments.allowed_mimes', ['pdf', 'txt']);

    expect(app(AttachmentStorage::class)->imageMimes())->toBe([]);
});

it('reads the file-count limit from config, never below one', function (mixed $value, int $expected) {
    config()->set('feedback-board.attachments.max_files', $value);

    expect(app(AttachmentStorage::class)->maxFiles())->toBe($expected);
})->with([[10, 10], [3, 3], [0, 1], [-4, 1], ['5', 5]]);

it('ships a ten-file default', function () {
    expect(config('feedback-board.attachments.max_files'))->toBe(10);
});
```

Append to `tests/Feature/NotificationTest.php`:

```php
it('mails a fallback line for an image-only reply', function () {
    $client = TestUser::create(['name' => 'C', 'email' => 'c@x.com']);
    $item = FeedbackItem::factory()->for($client, 'reporter')->create();

    $comment = app(ReplyToFeedback::class)->handle($item, '', null, AuthorRole::Developer);
    $mail = (new FeedbackRepliedNotification($comment))->toMail($client);

    expect($mail->introLines)->toBe([__('feedback-board::feedback-board.mail.reply_images')])
        ->and($mail->introLines[0])->toBe('A reply with images was added.');
});
```

Append to `tests/Feature/Destinations/GithubIssueDriverTest.php`:

```php
it('skips the auto-comment for an image-only reply', function () {
    Http::fake();
    $item = FeedbackItem::factory()->create([
        'external_refs' => ['github' => ['issue_number' => 7, 'node_id' => 'N_1', 'html_url' => 'u', 'repo' => 'acme/app', 'posted' => []]],
    ]);
    $comment = FeedbackComment::factory()->create(['feedback_item_id' => $item->id, 'body' => '', 'is_internal' => false]);

    (new GithubIssueDriver())->send($item, new FeedbackReplied($comment), 'dlv-images');

    Http::assertNothingSent();
});
```

Append to `tests/Feature/Destinations/WebhookPayloadTest.php`:

```php
it('carries an empty body for an image-only reply', function () {
    $item = FeedbackItem::factory()->create();
    $comment = FeedbackComment::factory()->create([
        'feedback_item_id' => $item->id, 'body' => '', 'is_internal' => false, 'author_role' => AuthorRole::Developer,
    ]);

    expect(WebhookPayload::build($item, new FeedbackReplied($comment), 'd')['change'])
        ->toBe(['author_role' => 'developer', 'body' => '']);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/AttachmentRulesTest.php tests/Feature/NotificationTest.php tests/Feature/Destinations/GithubIssueDriverTest.php tests/Feature/Destinations/WebhookPayloadTest.php`
Expected: FAIL — undefined `imageMimes()`, the mail line is empty, GitHub receives a request. The webhook test already passes (it pins existing behaviour).

- [ ] **Step 3: Implement**

In `config/feedback-board.php`, add to the `attachments` array after `allowed_mimes`:

```php
        // Files per report form and per comment (comment composers accept the image types above only).
        'max_files' => 10,
```

In `src/Services/AttachmentStorage.php`, add `use YousefAman\FeedbackBoard\Support\Images;` and, after `allowedRules()`:

```php
    /**
     * The configured extensions that are allow-listed images: what comment composers accept.
     *
     * @return list<string>
     */
    public function imageMimes(): array
    {
        $configured = array_map(
            fn ($mime) => strtolower(trim((string) $mime)),
            (array) config('feedback-board.attachments.allowed_mimes', []),
        );

        return array_values(array_intersect(Images::extensions(), $configured));
    }

    public function imageRules(): array
    {
        $max = (int) config('feedback-board.attachments.max_kb', 10240);

        return ['file', 'mimes:'.implode(',', $this->imageMimes()), 'max:'.$max];
    }

    public function maxFiles(): int
    {
        return max(1, (int) config('feedback-board.attachments.max_files', 10));
    }
```

In `src/Notifications/FeedbackRepliedNotification.php`, replace `toMail()`:

```php
    public function toMail(object $notifiable): MailMessage
    {
        $body = trim((string) $this->comment->body);

        return (new MailMessage)
            ->subject(__('feedback-board::feedback-board.mail.reply_subject'))
            ->line($body !== '' ? $body : __('feedback-board::feedback-board.mail.reply_images'));
    }
```

In `src/Destinations/Drivers/GithubIssueDriver.php`, after the internal-note guard in `send()`:

```php
        // GitHub rejects an empty comment (422); an image-only reply has no text to mirror.
        if ($event instanceof FeedbackReplied && trim((string) $event->comment->body) === '') {
            return;
        }
```

In `src/Destinations/Support/WebhookPayload.php`, extend the doc comment of `build()` with one line (no code change):

```php
     * An image-only reply carries body ''; its images stay in the app.
```

In both language files, replace the `'mail' => [...]` block. English:

```php
    'mail' => [
        'new_subject' => 'New feedback: :title', 'new_line' => 'A new report was submitted.',
        'reply_subject' => 'New reply on your feedback',
        'reply_images' => 'A reply with images was added.',
        'resolved_subject' => 'Resolved: :title', 'resolved_line' => 'Your report was resolved.',
    ],
```

Arabic:

```php
    'mail' => [
        'new_subject' => 'ملاحظة جديدة: :title', 'new_line' => 'تم رفع ملاحظة جديدة.',
        'reply_subject' => 'رد جديد على ملاحظتك',
        'reply_images' => 'أُضيف رد يحتوي على صور.',
        'resolved_subject' => 'تم الحل: :title', 'resolved_line' => 'تم حل ملاحظتك.',
    ],
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/AttachmentRulesTest.php tests/Feature/NotificationTest.php tests/Feature/Destinations/GithubIssueDriverTest.php tests/Feature/Destinations/WebhookPayloadTest.php tests/Smoke/ConfigSerializableTest.php`
Expected: PASS.

Run: `php -l` on the changed PHP files, then `vendor/bin/pest`.
Expected: no failures.

- [ ] **Step 5: Commit**

```bash
git add config/feedback-board.php src/Services/AttachmentStorage.php src/Notifications/FeedbackRepliedNotification.php src/Destinations/Drivers/GithubIssueDriver.php src/Destinations/Support/WebhookPayload.php resources/lang tests/Feature/AttachmentRulesTest.php tests/Feature/NotificationTest.php tests/Feature/Destinations
git commit -m "feat(attachments): image rules and a file limit for composers; outbound channels handle image-only replies"
```

---

### Task 5: UI primitives — icons, avatars, type tiles, slide-over, thumbnails, lightbox, tints, panel bridge tokens, light standalone theme

**Files:**
- Create: `src/Support/Icons.php`
- Create: `resources/views/components/icon.blade.php`, `avatar.blade.php`, `type-icon.blade.php`, `slide-over.blade.php`, `thumb.blade.php`
- Create: `resources/views/partials/lightbox.blade.php`
- Modify: `src/Enums/FeedbackType.php` (`icon()`, `hint()`, `color()`)
- Modify: `src/Support/Person.php` (`initial()`, `hue()`)
- Modify: `src/Support/Ui.php` (`colorScheme()`)
- Modify: `config/feedback-board.php` (the `ui.theme` comment)
- Modify: `resources/dist/feedback-board.css`
- Modify: `resources/lang/en/feedback-board.php`, `resources/lang/ar/feedback-board.php`
- Test: `tests/Feature/UiComponentsTest.php` (new), `tests/Unit/StylesheetTest.php`, `tests/Unit/StylesheetCoverageTest.php`, `tests/Unit/UiTest.php`, `tests/Feature/StandalonePagesTest.php`

**Interfaces:**
- Consumes: `Person::name()` (Task 2), `FeedbackAttachment::url()` (Task 1).
- Produces (every later UI task uses these verbatim):
  - `<x-feedback-board::icon name="…" />` → `<svg class="fb-icon …" … aria-hidden="true"><path d="…"/></svg>`; names are the keys of `Icons::PATHS` (below). `Icons::path(string $name): string` throws `InvalidArgumentException` for an unknown name.
  - `<x-feedback-board::avatar :name="$name" size="sm|lg" />` → `<span class="fb-avatar fb-avatar--{1-4} [fb-avatar--lg]" aria-hidden="true">X</span>`; `Person::initial(string): string`, `Person::hue(string): int` (1–4).
  - `<x-feedback-board::type-icon :type="$type" class="fb-type-icon--sm" />` → a tinted tile with the type's icon. `FeedbackType::icon(): string` (an `Icons` name), `FeedbackType::hint(): string`, `FeedbackType::color(): string` (`danger|primary|info|gray`, for Filament badges).
  - `<x-feedback-board::slide-over id="…" labelledby="…" [wide] [show="open"] [close="open = false"]>…</x-feedback-board::slide-over>` — `show` (a boolean expression) and `close` (a statement) are package-defined Alpine code from the caller's scope. Escape (`.window`), the backdrop and the bubbling browser event `fb-overlay-close` run `close`. The panel is `role="dialog" aria-modal="true"` with `x-trap.noscroll`. Focus returns to the opener (x-trap), or to the page's `h1` when nothing else holds focus (deep links).
  - Slide-over content classes: `fb-slide-over__head`, `fb-slide-over__titles`, `fb-slide-over__title`, `fb-slide-over__sub`; the scrolling content root `fb-sheet` with `fb-sheet__body` and the sticky `fb-sheet__foot`.
  - `<x-feedback-board::thumb :attachment="$a" />` → a `<button class="fb-thumb">` with `data-fb-src`/`data-fb-name`, `x-on:click="zoom($el)"`, a lazy `<img alt="">` and visually hidden "Enlarge NAME". Wrap thumbnails in `<div class="fb-thumbs">`.
  - `@include('feedback-board::partials.lightbox')` — needs, in an ancestor `x-data`: `lightbox: null, zoom(el) { this.lightbox = { src: el.dataset.fbSrc, name: el.dataset.fbName } }, closeLightbox() { this.lightbox = null }`.
  - CSS classes: `fb-icon`, `fb-avatar` (+ `--1..4`, `--lg`), `fb-type-icon` (+ `--bug|--feature|--question|--other`, `--sm`), `fb-tint`, `fb-round-btn`, `fb-thumbs`, `fb-thumb`, `fb-lightbox` (+ `__bar`, `__name`, `__img`), the slide-over and sheet classes above.
  - Private tokens `--_fb-tint-opacity` (light `0.1`, dark `0.16`) and `--_fb-mono`.
  - `Ui::colorScheme()` returns `light` for `class`, `light dark` for `system`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/UiComponentsTest.php`:

```php
<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Blade, Storage};
use YousefAman\FeedbackBoard\Enums\FeedbackType;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Services\AttachmentStorage;
use YousefAman\FeedbackBoard\Support\{Icons, Person};

it('renders inline Heroicons and rejects unknown names', function () {
    $html = Blade::render('<x-feedback-board::icon name="x-mark" class="fb-launcher__icon" />');

    expect($html)->toContain('class="fb-icon fb-launcher__icon"')
        ->toContain('aria-hidden="true"')
        ->toContain('<path d="M6 18 18 6M6 6l12 12"/>');
    expect(fn () => Icons::path('nope'))->toThrow(InvalidArgumentException::class);
});

it('gives every report type an icon, a hint and a Filament colour', function () {
    foreach (FeedbackType::cases() as $type) {
        expect(Icons::path($type->icon()))->toBeString()
            ->and($type->hint())->not->toStartWith('feedback-board::')
            ->and($type->color())->toBeIn(['danger', 'primary', 'info', 'gray']);
    }
});

it('draws an avatar from one letter and a stable hue', function () {
    expect(Person::initial('lena'))->toBe('L')
        ->and(Person::initial('لانا'))->toBe('ل')
        ->and(Person::initial('  '))->toBe('?')
        ->and(Person::hue('Lena'))->toBe(Person::hue('Lena'))
        ->and(Person::hue('Lena'))->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(4);

    expect(Blade::render('<x-feedback-board::avatar name="Lena" size="lg" />'))
        ->toContain('class="fb-avatar fb-avatar--'.Person::hue('Lena').' fb-avatar--lg" aria-hidden="true">L</span>');
});

it('renders a type tile', function () {
    expect(Blade::render('<x-feedback-board::type-icon :type="$type" class="fb-type-icon--sm" />', ['type' => FeedbackType::Bug]))
        ->toContain('class="fb-type-icon fb-type-icon--bug fb-type-icon--sm" aria-hidden="true"');
});

it('renders the slide-over dialog contract', function () {
    $html = Blade::render('<x-feedback-board::slide-over id="fb-test-sheet" labelledby="fb-test-title" wide>Body</x-feedback-board::slide-over>');

    expect($html)->toContain('class="fb-slide-over fb-slide-over--wide" id="fb-test-sheet"')
        ->toContain('x-show="open" x-cloak')
        ->toContain("x-init=\"\$watch('open', (value) => value || refocus())\"")
        ->toContain('x-on:keydown.escape.window="open && (open = false)"')
        ->toContain('x-on:fb-overlay-close.stop="open = false"')
        ->toContain('<div class="fb-slide-over__backdrop" x-on:click="open = false"></div>')
        ->toContain('role="dialog" aria-modal="true" aria-labelledby="fb-test-title" x-trap.noscroll="open"')
        ->toContain('Body');
});

it('lets the caller drive a slide-over from the server', function () {
    $html = Blade::render('<x-feedback-board::slide-over id="fb-test-sheet" labelledby="fb-test-title" show="true" close="$wire.close()">Body</x-feedback-board::slide-over>');

    expect($html)->toContain('x-on:keydown.escape.window="true && ($wire.close())"')
        ->toContain('x-trap.noscroll="true"')
        ->not->toContain('fb-slide-over--wide');
});

it('renders a thumbnail whose file name reaches only data attributes and text', function () {
    Storage::fake('local');
    $item = FeedbackItem::factory()->create();
    $image = app(AttachmentStorage::class)->store(UploadedFile::fake()->image('shot.png'), $item);

    expect(Blade::render('<x-feedback-board::thumb :attachment="$image" />', ['image' => $image]))
        ->toContain('class="fb-thumb"')
        ->toContain('data-fb-src="'.$image->url().'"')
        ->toContain('data-fb-name="shot.png"')
        ->toContain('x-on:click="zoom($el)"')
        ->toContain('alt="" loading="lazy" decoding="async"')
        ->toContain('<span class="fb-sr-only">Enlarge shot.png</span>');
});

it('renders a lightbox that closes on Escape without closing the dialog under it', function () {
    expect(view('feedback-board::partials.lightbox')->render())
        ->toContain('x-on:keydown.escape.stop="closeLightbox()"')
        ->toContain('x-trap.noscroll="lightbox"')
        ->toContain('aria-label="Image preview"')
        ->toContain('download x-bind:href="lightbox ? lightbox.src : null"');
});
```

In `tests/Unit/StylesheetTest.php`:

1. In `'scopes every selector to a package class'`, change the pattern to `'/^(\.dark |\.fi |\.fi\.dark )?\.fb-/'`.
2. Replace `'never declares a public --fb- token and reads each one only with a fallback'` with:

```php
it('never declares a public --fb- token and reads each one only with a fallback', function () {
    $css = (string) preg_replace('#/\*.*?\*/#s', '', fbStylesheet());
    preg_match_all('/(?<![\w-])(--fb-[a-z0-9-]+)\s*:/', $css, $declared);
    preg_match_all('/var\(\s*(--fb-[a-z0-9-]+)\s*([,)])/', $css, $reads);

    expect($declared[1])->toBe([])
        ->and(array_values(array_unique($reads[1])))->toEqualCanonicalizing([
            '--fb-accent', '--fb-accent-hover', '--fb-accent-contrast', '--fb-radius', '--fb-font', '--fb-z',
            // Internal bridge tokens printed by the Filament plugin's render hook; not public API.
            '--fb-panel-accent', '--fb-panel-accent-hover', '--fb-panel-accent-contrast',
        ])
        ->and(array_unique($reads[2]))->toBe([',']);
});

it('reads the panel bridge tokens only inside a Filament panel and beneath the public tokens', function () {
    foreach (fbCssRules(fbStylesheet()) as $rule) {
        if (! str_contains($rule['body'], '--fb-panel-')) {
            continue;
        }

        foreach (fbSplitSelectors($rule['selector']) as $selector) {
            expect($selector)->toMatch('/^\.fi[ .]/');
        }

        preg_match_all('/var\(\s*--fb-panel-/', $rule['body'], $bridge);
        preg_match_all('/var\(\s*--fb-accent[a-z-]*\s*,\s*var\(\s*--fb-panel-/', $rule['body'], $nested);
        expect(count($nested[0]))->toBe(count($bridge[0]));
    }
});

it('follows the panel accent in both themes and out-ranks the generic dark tokens', function () {
    $rules = collect(fbCssRules(fbStylesheet()));
    $tokens = ['--_fb-accent', '--_fb-accent-hover', '--_fb-accent-contrast', '--_fb-link', '--_fb-ring'];

    $light = $rules->first(fn ($r) => $r['at'] === null && $r['selector'] === '.fi .fb-scope');
    $dark = $rules->first(fn ($r) => $r['at'] === null && str_contains($r['selector'], '.fi.dark .fb-scope[data-fb-theme="class"]'));
    $system = $rules->first(fn ($r) => str_contains((string) $r['at'], 'prefers-color-scheme: dark')
        && $r['selector'] === '.fi .fb-scope[data-fb-theme="system"]');

    foreach ([$light, $dark, $system] as $rule) {
        expect($rule)->not->toBeNull();

        foreach ($tokens as $token) {
            expect(fbDeclarations($rule['body'])[$token] ?? '')->toContain('var(--fb-panel-');
        }
    }

    expect(fbSplitSelectors($dark['selector']))->toBe(['.fi.dark .fb-scope[data-fb-theme="class"]', '.fi .fb-scope[data-fb-theme="dark"]'])
        ->and(fbDeclarations($dark['body']))->toBe(fbDeclarations($system['body']));
});
```

3. Replace `'declares the same dark tokens for the class/forced and the system/standalone selectors'` with:

```php
it('declares the same dark tokens for the class/forced and the system selectors', function () {
    $rules = collect(fbCssRules(fbStylesheet()));

    $outside = $rules->first(fn ($r) => $r['at'] === null && str_contains($r['selector'], '.fb-scope[data-fb-theme="dark"]'));
    $inside = $rules->first(fn ($r) => str_contains((string) $r['at'], 'prefers-color-scheme: dark')
        && str_contains($r['selector'], '.fb-scope[data-fb-theme="system"]'));

    expect($outside)->not->toBeNull()
        ->and($inside)->not->toBeNull()
        ->and(fbSplitSelectors($outside['selector']))->toBe(['.dark .fb-scope[data-fb-theme="class"]', '.fb-scope[data-fb-theme="dark"]'])
        ->and(fbSplitSelectors($inside['selector']))->toBe(['.fb-scope[data-fb-theme="system"]'])
        ->and(fbDeclarations($inside['body']))->toBe(fbDeclarations($outside['body']))
        ->and($outside['body'])->toContain('color-scheme: dark')
        ->and($inside['body'])->toContain('color-scheme: dark');
});

it('keeps the standalone pages light under the class theme', function () {
    expect(fbStylesheet())->not->toContain('.fb-standalone[data-fb-theme="class"]')
        ->and(fbStylesheet())->not->toContain('.fb-standalone [data-fb-theme="class"]');
});

it('reserves ::before for accent tints', function () {
    foreach (fbCssRules(fbStylesheet()) as $rule) {
        $before = array_filter(
            fbSplitSelectors($rule['selector']),
            fn (string $selector) => str_contains($selector, '::before') && $selector !== '.fb-scope *::before',
        );

        if ($before !== []) {
            expect($rule['body'])->toContain('opacity: var(--_fb-tint-opacity)');
        }
    }
});
```

In `tests/Unit/StylesheetCoverageTest.php`:
- add `'fb-overlay-close'` to `$notClasses`;
- in the dynamic test, add `use YousefAman\FeedbackBoard\Enums\FeedbackType;` and append to `$expected`:

```php
        ...array_map(fn ($t) => 'fb-type-icon--'.$t->value, FeedbackType::cases()),
        ...array_map(fn ($n) => 'fb-avatar--'.$n, range(1, 4)),
```

In `tests/Unit/UiTest.php`, change the `maps the theme to the page color-scheme` dataset to `[['class', 'light'], ['system', 'light dark'], ['light', 'light'], ['dark', 'dark']]`.

In `tests/Feature/StandalonePagesTest.php`, change `'<meta name="color-scheme" content="light dark">'` to `'<meta name="color-scheme" content="light">'` and append:

```php
it('follows the operating system on standalone pages only with the system theme', function () {
    $me = TestUser::create(['name' => 'Me', 'email' => 'me@x.com']);
    config()->set('feedback-board.ui.theme', 'system');

    $html = $this->actingAs($me)->get(route('feedback-board.page'))->assertOk()->getContent();

    expect($html)->toContain('<meta name="color-scheme" content="light dark">')
        ->and($html)->toContain('data-fb-theme="system"');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/UiComponentsTest.php tests/Unit/StylesheetTest.php tests/Unit/StylesheetCoverageTest.php tests/Unit/UiTest.php tests/Feature/StandalonePagesTest.php`
Expected: FAIL — unknown components, `Class "…\Support\Icons" not found`, no bridge tokens, standalone dark selectors present, `class` maps to `light dark`, `.fb-activity__item::before` is not a tint.

- [ ] **Step 3: Icons, type metadata, avatars, colour scheme**

Create `src/Support/Icons.php`:

```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Support;

use InvalidArgumentException;

/** Heroicons v2 outline paths (24 px, one path each), drawn inline by <x-feedback-board::icon>. */
final class Icons
{
    private const PATHS = [
        'arrow-down-tray' => 'M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3',
        'arrow-up-tray' => 'M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5',
        'chat-bubble-bottom-center-text' => 'M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 0 1 .865-.501 48.172 48.172 0 0 0 3.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z',
        'chat-bubble-left-right' => 'M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155',
        'chat-bubble-oval-left-ellipsis' => 'M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z',
        'check-circle' => 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
        'clock' => 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
        'code-bracket' => 'M17.25 6.75 22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3-4.5 16.5',
        'document' => 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z',
        'ellipsis-horizontal-circle' => 'M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
        'exclamation-triangle' => 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z',
        'home' => 'm2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25',
        'information-circle' => 'm11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z',
        'light-bulb' => 'M12 18v-5.25m0 0a6.01 6.01 0 0 0 1.5-.189m-1.5.189a6.01 6.01 0 0 1-1.5-.189m3.75 7.478a12.06 12.06 0 0 1-4.5 0m3.75 2.383a14.406 14.406 0 0 1-3 0M14.25 18v-.192c0-.983.658-1.823 1.508-2.316a7.5 7.5 0 1 0-7.517 0c.85.493 1.509 1.333 1.509 2.316V18',
        'link' => 'M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244',
        'lock-closed' => 'M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z',
        'paper-airplane' => 'M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5',
        'paper-clip' => 'm18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13',
        'photo' => 'm2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z',
        'plus' => 'M12 4.5v15m7.5-7.5h-15',
        'question-mark-circle' => 'M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z',
        'queue-list' => 'M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 0 1 0 3.75H5.625a1.875 1.875 0 0 1 0-3.75Z',
        'x-mark' => 'M6 18 18 6M6 6l12 12',
    ];

    public static function path(string $name): string
    {
        return self::PATHS[$name] ?? throw new InvalidArgumentException("Unknown feedback-board icon [{$name}].");
    }
}
```

In `src/Enums/FeedbackType.php`, add after `label()`:

```php
    /** An Icons name for the type tile. */
    public function icon(): string
    {
        return match ($this) {
            self::Bug => 'exclamation-triangle',
            self::Feature => 'light-bulb',
            self::Question => 'question-mark-circle',
            self::Other => 'ellipsis-horizontal-circle',
        };
    }

    /** One line under the type in the report form. */
    public function hint(): string
    {
        return __('feedback-board::feedback-board.type_hint.'.$this->value);
    }

    /** Filament badge colour (the resource table); the package's own views use type tiles. */
    public function color(): string
    {
        return match ($this) {
            self::Bug => 'danger',
            self::Feature => 'primary',
            self::Question => 'info',
            self::Other => 'gray',
        };
    }
```

In `src/Support/Person.php`, add after `name()`:

```php
    /** One letter for an avatar: a single code point, never two joined Arabic letters. */
    public static function initial(string $name): string
    {
        $letter = mb_strtoupper(mb_substr(trim($name), 0, 1));

        return $letter !== '' ? $letter : '?';
    }

    /** A stable 1–4 avatar hue from the name. */
    public static function hue(string $name): int
    {
        return crc32($name) % 4 + 1;
    }
```

In `src/Support/Ui.php`, replace `colorScheme()`:

```php
    // For the standalone pages' <meta name="color-scheme">. With `class` there is no host page to
    // set the dark class, so the standalone pages are light; `system` follows the OS.
    public static function colorScheme(): string
    {
        return match (self::theme()) {
            'dark' => 'dark',
            'system' => 'light dark',
            default => 'light',
        };
    }
```

In `config/feedback-board.php`, replace the `'class'` lines of the `ui.theme` comment:

```php
        //   'class'  — dark when an ancestor element has the class "dark" (Filament panels, Laravel's
        //              Livewire/Flux starter kits, Tailwind's class strategy). The package's standalone
        //              pages have no host to set that class, so they stay light.
```

- [ ] **Step 4: Create the components and the lightbox partial**

`resources/views/components/icon.blade.php`:

```blade
@props(['name'])
<svg {{ $attributes->class(['fb-icon']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ \YousefAman\FeedbackBoard\Support\Icons::path($name) }}"/></svg>
```

`resources/views/components/avatar.blade.php`:

```blade
@props(['name', 'size' => 'sm'])
@php($fbAvatarName = filled($name) ? (string) $name : '?')
<span {{ $attributes->class(['fb-avatar', 'fb-avatar--'.\YousefAman\FeedbackBoard\Support\Person::hue($fbAvatarName), 'fb-avatar--lg' => $size === 'lg']) }} aria-hidden="true">{{ \YousefAman\FeedbackBoard\Support\Person::initial($fbAvatarName) }}</span>
```

`resources/views/components/type-icon.blade.php`:

```blade
@props(['type'])
<span {{ $attributes->class(['fb-type-icon', 'fb-type-icon--'.$type->value]) }} aria-hidden="true"><x-feedback-board::icon :name="$type->icon()" /></span>
```

`resources/views/components/slide-over.blade.php`:

```blade
{{--
    An off-canvas dialog on the inline-end edge (left in RTL). `show` (a boolean) and `close` (a
    statement) are Alpine code from the caller's scope: pass package-defined code only, never user
    data. Children close it with the bubbling browser event `fb-overlay-close`. x-trap returns focus
    to the opener; refocus() moves it to the page heading when nothing else holds it (deep links).
--}}
@props(['id', 'labelledby', 'wide' => false, 'show' => 'open', 'close' => 'open = false'])
<div {{ $attributes->class(['fb-slide-over', 'fb-slide-over--wide' => $wide]) }} id="{{ $id }}"
     x-data="{ refocus() { setTimeout(() => { if (document.activeElement && document.activeElement !== document.body) return; const heading = document.querySelector('h1'); if (heading) { heading.setAttribute('tabindex', '-1'); heading.focus(); } }, 50) }, destroy() { this.refocus() } }"
     x-show="{{ $show }}" x-cloak
     x-init="$watch('{{ $show }}', (value) => value || refocus())"
     x-on:keydown.escape.window="{{ $show }} && ({{ $close }})"
     x-on:fb-overlay-close.stop="{{ $close }}">
    <div class="fb-slide-over__backdrop" x-on:click="{{ $close }}"></div>
    <div class="fb-slide-over__panel" role="dialog" aria-modal="true" aria-labelledby="{{ $labelledby }}" x-trap.noscroll="{{ $show }}">
        {{ $slot }}
    </div>
</div>
```

`resources/views/components/thumb.blade.php`:

```blade
{{-- The file name reaches only data-* (read through $el.dataset) and text, never an expression. --}}
@props(['attachment'])
<button type="button" {{ $attributes->class(['fb-thumb']) }} data-fb-src="{{ $attachment->url() }}" data-fb-name="{{ $attachment->original_name }}" x-on:click="zoom($el)">
    <img src="{{ $attachment->url() }}" alt="" loading="lazy" decoding="async">
    <span class="fb-sr-only">{{ __('feedback-board::feedback-board.images.enlarge', ['name' => $attachment->original_name]) }}</span>
</button>
```

`resources/views/partials/lightbox.blade.php`:

```blade
{{--
    Needs, in an ancestor x-data: lightbox: null, zoom(el) { … }, closeLightbox() { … }.
    Escape is bound here with .stop, so closing the lightbox never closes the dialog under it.
--}}
<div class="fb-lightbox" x-show="lightbox" x-cloak role="dialog" aria-modal="true" aria-label="{{ __('feedback-board::feedback-board.images.preview') }}"
     x-trap.noscroll="lightbox" x-on:keydown.escape.stop="closeLightbox()" x-on:click.self="closeLightbox()">
    <div class="fb-lightbox__bar">
        <button type="button" class="fb-round-btn" x-on:click="closeLightbox()">
            <x-feedback-board::icon name="x-mark" />
            <span class="fb-sr-only">{{ __('feedback-board::feedback-board.images.close') }}</span>
        </button>
        <span class="fb-lightbox__name" dir="auto" x-text="lightbox ? lightbox.name : ''"></span>
        <a class="fb-btn fb-btn--sm" download x-bind:href="lightbox ? lightbox.src : null">
            <x-feedback-board::icon name="arrow-down-tray" />{{ __('feedback-board::feedback-board.images.download') }}
        </a>
    </div>
    <img class="fb-lightbox__img" alt="" x-bind:src="lightbox ? lightbox.src : ''">
</div>
```

- [ ] **Step 5: Stylesheet — tokens, panel bridge, light standalone theme**

In `resources/dist/feedback-board.css`:

a) In the light `.fb-scope` token block, after `--_fb-internal-border: #f59e0b;`, add:

```css
    --_fb-tint-opacity: 0.1;
    --_fb-mono: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
```

b) Replace the `.fi .fb-scope` rule and its comment with:

```css
/* Inside a Filament panel: launcher (35) above the top bar (30) and below Filament modals (40);
   package overlays (45) above the sidebar and below Filament notifications (50).
   The accent follows the panel's primary colour through internal bridge tokens printed by the
   plugin's render hook; a public token still wins, and without the hook the fallbacks keep indigo. */
.fi .fb-scope {
    --_fb-z: var(--fb-z, 35);
    --_fb-accent: var(--fb-accent, var(--fb-panel-accent, #4f46e5));
    --_fb-accent-hover: var(--fb-accent-hover, var(--fb-panel-accent-hover, #4338ca));
    --_fb-accent-contrast: var(--fb-accent-contrast, var(--fb-panel-accent-contrast, #fff));
    --_fb-link: var(--fb-accent, var(--fb-panel-accent, #4f46e5));
    --_fb-ring: var(--fb-accent, var(--fb-panel-accent, #6366f1));
}
```

c) In **both** dark token blocks (`.dark .fb-scope[data-fb-theme="class"], .fb-scope[data-fb-theme="dark"]` and the one inside `@media (prefers-color-scheme: dark)`), add after `--_fb-internal-border: #d97706;`:

```css
    --_fb-tint-opacity: 0.16;
```

d) Inside `@media (prefers-color-scheme: dark)`, reduce the selector list to the system theme only (standalone pages with `class` are now light):

```css
@media (prefers-color-scheme: dark) {
    .fb-scope[data-fb-theme="system"] {
```

e) Directly after that media block, add:

```css
/* Dark panel: these out-rank the generic dark blocks above. Links and focus rings use the lighter
   500 shade, which stays readable on the dark surfaces. */
.fi.dark .fb-scope[data-fb-theme="class"],
.fi .fb-scope[data-fb-theme="dark"] {
    --_fb-accent: var(--fb-accent, var(--fb-panel-accent, #4f46e5));
    --_fb-accent-hover: var(--fb-accent-hover, var(--fb-panel-accent-hover, #4338ca));
    --_fb-accent-contrast: var(--fb-accent-contrast, var(--fb-panel-accent-contrast, #fff));
    --_fb-link: var(--fb-accent, var(--fb-panel-accent-hover, #a5b4fc));
    --_fb-ring: var(--fb-accent, var(--fb-panel-accent-hover, #818cf8));
}

@media (prefers-color-scheme: dark) {
    .fi .fb-scope[data-fb-theme="system"] {
        --_fb-accent: var(--fb-accent, var(--fb-panel-accent, #4f46e5));
        --_fb-accent-hover: var(--fb-accent-hover, var(--fb-panel-accent-hover, #4338ca));
        --_fb-accent-contrast: var(--fb-accent-contrast, var(--fb-panel-accent-contrast, #fff));
        --_fb-link: var(--fb-accent, var(--fb-panel-accent-hover, #a5b4fc));
        --_fb-ring: var(--fb-accent, var(--fb-panel-accent-hover, #818cf8));
    }
}
```

f) In the Buttons section, after `.fb-btn--sm`, add:

```css
.fb-btn .fb-icon {
    inline-size: 1.125rem;
    block-size: 1.125rem;
}
```

g) Move the activity dot to `::after` (`::before` is reserved for tints): rename the selector `.fb-activity__item::before` to `.fb-activity__item::after`.

h) Delete the two `@keyframes` blocks `fb-fade-in` (in the launcher/modal section, with its comment) and `fb-slide-in` (end of the drawer section); they move into the overlay section below.

- [ ] **Step 6: Stylesheet — new blocks**

Insert after the "Containers and text" section (after `.fb-empty`):

```css
/* ---------- Icons, avatars and type tiles ---------- */

.fb-icon {
    inline-size: 1.25rem;
    block-size: 1.25rem;
    flex: none;
}

.fb-avatar {
    display: inline-grid;
    place-items: center;
    inline-size: 1.5rem;
    block-size: 1.5rem;
    border-radius: 999px;
    box-shadow: 0 0 0 2px var(--_fb-surface);
    font-size: 0.6875rem;
    font-weight: 700;
    line-height: 1;
    flex: none;
}

.fb-avatar--lg {
    inline-size: 2rem;
    block-size: 2rem;
    font-size: 0.8125rem;
}

/* A fixed four-hue palette, picked by a stable hash of the name. */
.fb-avatar--1 {
    background: var(--_fb-info-bg);
    color: var(--_fb-info-fg);
}

.fb-avatar--2 {
    background: var(--_fb-success-bg);
    color: var(--_fb-success-fg);
}

.fb-avatar--3 {
    background: var(--_fb-warning-bg);
    color: var(--_fb-warning-fg);
}

.fb-avatar--4 {
    background: var(--_fb-danger-bg);
    color: var(--_fb-danger-fg);
}

.fb-type-icon {
    display: inline-grid;
    place-items: center;
    inline-size: 2.25rem;
    block-size: 2.25rem;
    border-radius: 0.625rem;
    flex: none;
}

.fb-type-icon .fb-icon {
    inline-size: 1.1875rem;
    block-size: 1.1875rem;
}

.fb-type-icon--sm {
    inline-size: 2.125rem;
    block-size: 2.125rem;
    border-radius: 0.5625rem;
}

.fb-type-icon--bug {
    background: var(--_fb-danger-bg);
    color: var(--_fb-danger-fg);
}

.fb-type-icon--feature {
    color: var(--_fb-text);
}

.fb-type-icon--question {
    background: var(--_fb-info-bg);
    color: var(--_fb-info-fg);
}

.fb-type-icon--other {
    background: var(--_fb-gray-bg);
    color: var(--_fb-gray-fg);
}

/* ---------- Accent tints ----------
   Works for any host or panel accent in both themes, without color-mix(): the tinted element
   isolates a stacking context and paints the accent at a low opacity on its ::before, which is
   reserved for tints (other decorations use ::after). Text on a tint keeps the normal foreground
   colour. Never put a tint on an ancestor of an overlay. */

.fb-tint,
.fb-type-icon--feature {
    position: relative;
    isolation: isolate;
}

.fb-tint::before,
.fb-type-icon--feature::before {
    content: "";
    position: absolute;
    inset: 0;
    z-index: -1;
    border-radius: inherit;
    background: var(--_fb-accent);
    opacity: var(--_fb-tint-opacity);
    pointer-events: none;
}

/* ---------- Overlays: slide-over, sheet, thumbnails, lightbox ---------- */

/* CSS (not x-transition, whose inline styles reduced motion cannot override) restarts on every x-show. */
@keyframes fb-fade-in {
    from {
        opacity: 0;
    }
}

@keyframes fb-slide-in {
    from {
        transform: translateX(var(--_fb-slide-from, 100%));
    }
}

.fb-slide-over {
    position: fixed;
    inset: 0;
    z-index: calc(var(--_fb-z) + 10);
    display: flex;
    justify-content: flex-end;
}

.fb-slide-over__backdrop {
    position: absolute;
    inset: 0;
    background: var(--_fb-backdrop);
    animation: fb-fade-in 0.2s ease-out;
}

.fb-slide-over__panel {
    position: relative;
    display: flex;
    flex-direction: column;
    inline-size: min(28.75rem, 100%);
    block-size: 100%;
    background: var(--_fb-surface);
    color: var(--_fb-text);
    box-shadow: var(--_fb-shadow-lg);
    animation: fb-slide-in 0.28s cubic-bezier(0.2, 0.8, 0.2, 1);
}

.fb-slide-over--wide .fb-slide-over__panel {
    inline-size: min(35rem, 100%);
}

.fb-scope[dir="rtl"] .fb-slide-over__panel {
    --_fb-slide-from: -100%;
}

.fb-slide-over__head {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    padding: 1.25rem 1.375rem 1rem;
    border-block-end: 1px solid var(--_fb-border);
    flex: none;
}

.fb-slide-over__titles {
    display: grid;
    flex: 1;
    gap: 0.125rem;
    min-inline-size: 0;
}

.fb-slide-over__title {
    font-size: 1.125rem;
    font-weight: 700;
    line-height: 1.4;
    overflow-wrap: anywhere;
}

.fb-slide-over__sub {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.375rem;
    color: var(--_fb-text-muted);
    font-size: 0.8125rem;
}

.fb-sheet {
    display: flex;
    flex: 1;
    flex-direction: column;
    min-block-size: 0;
}

.fb-sheet__body {
    display: grid;
    flex: 1;
    align-content: start;
    gap: 1.25rem;
    min-block-size: 0;
    overflow-y: auto;
    overscroll-behavior: contain;
    padding: 1.25rem 1.375rem 1.5rem;
}

.fb-sheet__foot {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.625rem;
    padding: 0.875rem 1.375rem;
    border-block-start: 1px solid var(--_fb-border);
    background: var(--_fb-surface);
    flex: none;
}

.fb-round-btn {
    display: inline-grid;
    place-items: center;
    inline-size: 2.125rem;
    block-size: 2.125rem;
    padding: 0;
    border: 0;
    border-radius: 999px;
    background: var(--_fb-surface);
    color: var(--_fb-text);
    box-shadow: 0 1px 4px rgb(0 0 0 / 0.18);
    cursor: pointer;
}

.fb-round-btn:hover {
    background: var(--_fb-surface-muted);
}

.fb-thumbs {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(6rem, 1fr));
    gap: 0.375rem;
}

.fb-thumb {
    display: block;
    padding: 0;
    overflow: hidden;
    border: 0;
    border-radius: var(--_fb-radius-sm);
    background: var(--_fb-surface-muted);
    box-shadow: 0 0 0 1px var(--_fb-border);
    cursor: zoom-in;
}

.fb-thumb img {
    display: block;
    inline-size: 100%;
    aspect-ratio: 4 / 3;
    object-fit: cover;
    object-position: top;
}

.fb-lightbox {
    position: fixed;
    inset: 0;
    z-index: calc(var(--_fb-z) + 20);
    display: grid;
    grid-template-rows: auto minmax(0, 1fr);
    gap: 0.75rem;
    padding: 0.875rem 1.5rem 1.5rem;
    background: rgb(9 9 11 / 0.88);
}

.fb-lightbox__bar {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    color: #f4f4f5;
}

.fb-lightbox__name {
    flex: 1;
    min-inline-size: 0;
    overflow: hidden;
    font-weight: 600;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.fb-lightbox__img {
    justify-self: center;
    align-self: center;
    max-inline-size: 100%;
    max-block-size: 100%;
    border-radius: var(--_fb-radius-sm);
    box-shadow: var(--_fb-shadow-lg);
}

@media (max-width: 35rem) {
    .fb-slide-over__head,
    .fb-sheet__body,
    .fb-sheet__foot {
        padding-inline: 1rem;
    }
}
```

Insert directly before the reduced-motion block (keep that block last):

```css
/* ---------- Forced colours: tints and box-shadows disappear, so pressed controls keep a ring ---------- */

@media (forced-colors: active) {
    .fb-scope [aria-pressed="true"] {
        outline: 2px solid Highlight;
        outline-offset: 1px;
    }
}
```

- [ ] **Step 7: Translations**

In `resources/lang/en/feedback-board.php`, after the `'type' => [...]` line, add:

```php
    'type_hint' => [
        'bug' => "Something doesn't work as it should",
        'feature' => 'An idea to improve the app',
        'question' => 'How to use something',
        'other' => 'Any other note',
    ],
    'images' => [
        'enlarge' => 'Enlarge :name',
        'preview' => 'Image preview',
        'close' => 'Close the preview',
        'download' => 'Download',
    ],
```

In `resources/lang/ar/feedback-board.php`:
- change `'feature' => 'طلب ميزة'` to `'feature' => 'اقتراح'` and `'in_progress' => 'قيد العمل'` to `'in_progress' => 'قيد التنفيذ'` (the mockup's copy);
- change `'developer' => 'فريق الدعم'` to `'developer' => 'فريق الدعم الفني'`;
- after the `'type' => [...]` line, add:

```php
    'type_hint' => [
        'bug' => 'شيء لا يعمل كما يجب',
        'feature' => 'فكرة لتحسين النظام',
        'question' => 'استفسار عن طريقة الاستخدام',
        'other' => 'أي ملاحظة غير ذلك',
    ],
    'images' => [
        'enlarge' => 'تكبير :name',
        'preview' => 'معاينة الصورة',
        'close' => 'إغلاق المعاينة',
        'download' => 'تنزيل',
    ],
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/UiComponentsTest.php tests/Unit/StylesheetTest.php tests/Unit/StylesheetCoverageTest.php tests/Unit/UiTest.php tests/Feature/StandalonePagesTest.php tests/Feature/LocalizationTest.php`
Expected: PASS.

Run: `php -l` on the changed PHP files, then `vendor/bin/pest`.
Expected: no failures.

- [ ] **Step 9: Commit**

```bash
git add src/Support/Icons.php src/Support/Person.php src/Support/Ui.php src/Enums/FeedbackType.php config/feedback-board.php resources/views/components resources/views/partials/lightbox.blade.php resources/dist/feedback-board.css resources/lang tests/Feature/UiComponentsTest.php tests/Unit tests/Feature/StandalonePagesTest.php
git commit -m "feat(ui): shared icons, avatars, type tiles, slide-over, thumbnails and lightbox; accent tints, panel bridge tokens and light standalone pages"
```

---

### Task 6: Staged uploads, the redesigned report form and the launcher slide-over

**Files:**
- Create: `src/Livewire/Concerns/WithStagedUploads.php`
- Create: `resources/views/partials/upload-queue.blade.php`, `resources/views/partials/report-form-sheet.blade.php`
- Modify: `src/Livewire/FeedbackForm.php`; rewrite `resources/views/livewire/feedback-form.blade.php` and `resources/views/components/launcher.blade.php`
- Modify: `src/Support/Ui.php` (`reportsUrl()`, `reportLink()`), `src/Services/AttachmentStorage.php` (`humanSize()`)
- Modify: `resources/dist/feedback-board.css`, both language files
- Test: `tests/Feature/FeedbackFormTest.php` (replaced), `tests/Feature/LivewireRegistrationTest.php`, `tests/Unit/UiTest.php`, `tests/Unit/StylesheetCoverageTest.php`

**Interfaces:**
- Consumes: Task 4's `AttachmentStorage::allowedRules()`, `maxFiles()`; Task 5's components, `fb-sheet` classes, tint rule.
- Produces:
  - Trait `YousefAman\FeedbackBoard\Livewire\Concerns\WithStagedUploads` (uses `WithFileUploads`): `public $upload` (staging slot), `#[Locked] public array $files` (list of `TemporaryUploadedFile`), `updatedUpload(mixed $value): void`, `removeFile(int $index): void`, `protected storeStagedFiles(AttachmentStorage $storage, FeedbackItem $item, ?FeedbackComment $comment = null): bool`, and the abstract `protected uploadRules(): array`. Errors go to the `upload` key.
  - Partial `feedback-board::partials.upload-queue`: Alpine members for an `x-data="{ @include(…) }"` object — `uploading`, `progress`, `dragging`, `queue`, `draining`, `enqueue(files)`, `drain()`, `named(file)`, `pasted(event)`, `picked(event)`, `dropped(event)`.
  - Partial `feedback-board::partials.report-form-sheet` (`$titleId`): the slide-over head plus the form, for any slide-over.
  - `FeedbackForm`: `#[Locked] ?int $sentId`, `#[Locked] string $reportsUrl` (set in `mount()` from `Ui::reportsUrl()`), `#[Locked] string $pageUrl`, `#[Locked] bool $attachmentsFailed`.
  - `Ui::reportsUrl(?int $reportId = null): string` (standalone route; Task 10 adds the panel page) and `Ui::reportLink(string $base, int $reportId): string`.
  - `AttachmentStorage::humanSize(int $bytes): string`.
  - CSS: `fb-form`, `fb-fieldset`, `fb-legend`, `fb-required`, `fb-types`, `fb-type` (+ `__input`, `__card`, `__text`, `__label`, `__hint`), `fb-file-input`, `fb-dropzone` (+ `--over`, `__icon`, `__title`, `__browse`, `__hint`), `fb-uploads`, `fb-upload` (+ `__thumb`, `__meta`, `__name`, `__size`), `fb-context-note` (+ `__url`), `fb-sent` (+ `__icon`, `__title`, `__body`, `__ref`, `__actions`), `fb-sheet__link`, `fb-btn--lg`.

- [ ] **Step 1: Write the failing tests**

Replace `tests/Feature/FeedbackFormTest.php` with:

```php
<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Blade, Exceptions, Storage};
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use YousefAman\FeedbackBoard\Enums\FeedbackType;
use YousefAman\FeedbackBoard\Livewire\FeedbackForm;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Services\AttachmentStorage;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(fn () => Storage::fake('local'));

function fbFormUser(): TestUser
{
    return TestUser::create(['name' => 'Lena', 'email' => 'l@x.com']);
}

/** Livewire's temporary uploads (they persist between tests, so compare counts). */
function fbTemporaryUploadCount(): int
{
    return count(FileUploadConfiguration::storage()->allFiles());
}

it('submits a report with staged attachments and the authenticated user as reporter', function () {
    $user = fbFormUser();

    Livewire::actingAs($user)->test(FeedbackForm::class)
        ->set('title', 'Cannot export')
        ->set('type', FeedbackType::Feature->value)
        ->set('description', 'need excel')
        ->set('upload', UploadedFile::fake()->image('a.png'))
        ->assertCount('files', 1)
        ->assertSet('upload', null)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('feedback-submitted')
        ->assertCount('files', 0)
        ->assertSet('type', 'bug');

    $item = FeedbackItem::first();
    expect($item->title)->toBe('Cannot export')
        ->and($item->type)->toBe(FeedbackType::Feature)
        ->and($item->reporter->is($user))->toBeTrue()
        ->and($item->attachments)->toHaveCount(1)
        ->and($item->attachments->first()->original_name)->toBe('a.png')
        ->and($item->meta)->toHaveKey('locale');
});

it('validates required title', function () {
    Livewire::actingAs(fbFormUser())->test(FeedbackForm::class)->set('title', '')->call('submit')->assertHasErrors('title');
});

it('appends staged uploads one at a time, in order', function () {
    $component = Livewire::actingAs(fbFormUser())->test(FeedbackForm::class)
        ->set('upload', UploadedFile::fake()->image('one.png'))
        ->set('upload', UploadedFile::fake()->create('two.pdf', 10, 'application/pdf'))
        ->assertCount('files', 2)
        ->assertSet('upload', null);

    expect(collect($component->get('files'))->map->getClientOriginalName()->all())->toBe(['one.png', 'two.pdf']);
});

it('rejects a disallowed staged file, deletes it and clears the stage', function () {
    $component = Livewire::actingAs(fbFormUser())->test(FeedbackForm::class);
    $before = fbTemporaryUploadCount();

    $component->set('upload', UploadedFile::fake()->create('virus.exe', 10))
        ->assertHasErrors('upload')
        ->assertSee('virus.exe')
        ->assertSet('upload', null)
        ->assertCount('files', 0);

    expect(fbTemporaryUploadCount())->toBe($before);
});

it('rejects uploads past the file limit and deletes them', function () {
    config()->set('feedback-board.attachments.max_files', 1);
    $component = Livewire::actingAs(fbFormUser())->test(FeedbackForm::class)->set('upload', UploadedFile::fake()->image('one.png'));
    $before = fbTemporaryUploadCount();

    $component->set('upload', UploadedFile::fake()->image('two.png'))
        ->assertHasErrors('upload')
        ->assertSee(__('feedback-board::feedback-board.form.max_files_reached', ['count' => 1]))
        ->assertCount('files', 1);

    expect(fbTemporaryUploadCount())->toBe($before);
});

it('removes a pending file and deletes it', function () {
    $component = Livewire::actingAs(fbFormUser())->test(FeedbackForm::class)
        ->set('upload', UploadedFile::fake()->image('a.png'))
        ->set('upload', UploadedFile::fake()->image('b.png'));
    $before = fbTemporaryUploadCount();

    $component->call('removeFile', 0)->assertCount('files', 1);

    expect(collect($component->get('files'))->map->getClientOriginalName()->all())->toBe(['b.png'])
        ->and(fbTemporaryUploadCount())->toBe($before - 1);
});

it('never lets the client set the staged files', function () {
    $this->withoutExceptionHandling();

    expect(fn () => Livewire::actingAs(fbFormUser())->test(FeedbackForm::class)->set('files', []))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('previews only allow-listed images among the pending files', function () {
    config()->set('feedback-board.attachments.allowed_mimes', ['png', 'pdf', 'svg']);

    $html = Livewire::actingAs(fbFormUser())->test(FeedbackForm::class)
        ->set('upload', UploadedFile::fake()->image('shot.png'))
        ->set('upload', UploadedFile::fake()->create('spec.pdf', 5, 'application/pdf'))
        ->set('upload', UploadedFile::fake()->create('logo.svg', 1, 'image/svg+xml'))
        ->assertCount('files', 3)
        ->html();

    expect(preg_match_all('/<span class="fb-upload__thumb">\s*<img /', $html))->toBe(1)
        ->and(preg_match_all('/<span class="fb-upload__thumb">\s*<svg /', $html))->toBe(2)
        ->and($html)->toContain('<span class="fb-upload__name" dir="auto">logo.svg</span>');
});

it('stores a renamed pasted screenshot as an image attachment', function () {
    Livewire::actingAs(fbFormUser())->test(FeedbackForm::class)
        ->set('title', 'Pasted')
        ->set('upload', UploadedFile::fake()->image('screenshot-20261011-093000.jpg'))
        ->call('submit')
        ->assertHasNoErrors();

    $attachment = FeedbackItem::first()->attachments->first();
    expect($attachment->original_name)->toBe('screenshot-20261011-093000.jpg')
        ->and($attachment->mime)->toBe('image/jpeg')
        ->and($attachment->isImage())->toBeTrue();
});

it('confirms a submission with its reference and a link that opens it', function () {
    $component = Livewire::actingAs(fbFormUser())->test(FeedbackForm::class)
        ->assertSet('sent', false)
        ->set('title', 'Broken link')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('sent', true);

    $id = FeedbackItem::first()->id;

    $component->assertSet('sentId', $id)
        ->assertSee(__('feedback-board::feedback-board.form.sent_title'))
        ->assertSeeHtml('<span class="fb-sent__ref" dir="ltr">#'.$id.'</span>')
        ->assertSeeHtml('href="'.route('feedback-board.page').'?report='.$id.'"')
        ->assertSee(__('feedback-board::feedback-board.form.send_another'))
        ->assertDontSeeHtml('wire:submit="submit"');

    $component->set('sent', false)
        ->assertSeeHtml('wire:submit="submit"')
        ->assertDontSee(__('feedback-board::feedback-board.form.sent_title'));
});

it('stays on the form when validation fails', function () {
    Livewire::actingAs(fbFormUser())->test(FeedbackForm::class)
        ->set('sent', true)
        ->set('title', '')
        ->call('submit')
        ->assertHasErrors('title')
        ->assertSet('sent', false)
        ->assertSeeHtml('class="fb-error"');
});

it('keeps the report and says so when an attachment cannot be stored', function () {
    Exceptions::fake();
    $this->partialMock(AttachmentStorage::class, fn ($mock) => $mock->shouldReceive('store')->andThrow(new RuntimeException('disk full')));

    Livewire::actingAs(fbFormUser())->test(FeedbackForm::class)
        ->set('title', 'With a broken disk')
        ->set('upload', UploadedFile::fake()->image('shot.png'))
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('sent', true)
        ->assertSee(__('feedback-board::feedback-board.form.attachments_failed'));

    expect(FeedbackItem::count())->toBe(1);
    Exceptions::assertReported(RuntimeException::class);
});

it('binds the type through four radio cards in a fieldset', function () {
    $component = Livewire::actingAs(fbFormUser())->test(FeedbackForm::class);
    $html = $component->html();

    expect($html)->toContain('<fieldset class="fb-fieldset">')
        ->toContain('<legend class="fb-label fb-legend">'.__('feedback-board::feedback-board.form.type').'</legend>')
        ->toContain(FeedbackType::Question->hint())
        ->and(substr_count($html, 'type="radio"'))->toBe(4)
        ->and(substr_count($html, 'wire:model="type"'))->toBe(4);

    $component->set('title', 'How do I export?')->set('type', 'question')->call('submit')->assertHasNoErrors();

    expect(FeedbackItem::first()->type)->toBe(FeedbackType::Question);
});

it('labels every field and describes the attachment limits from config', function () {
    config()->set('feedback-board.attachments.allowed_mimes', ['png', 'pdf']);
    config()->set('feedback-board.attachments.max_kb', 512); // below any realistic upload_max_filesize
    config()->set('feedback-board.attachments.max_files', 4);

    Livewire::actingAs(fbFormUser())->test(FeedbackForm::class)
        ->assertSee(__('feedback-board::feedback-board.form.title'))
        ->assertSee(__('feedback-board::feedback-board.form.type'))
        ->assertSee(__('feedback-board::feedback-board.form.attachments'))
        ->assertSee('Allowed: PNG, PDF · up to 0.5 MB each')
        ->assertSee('Up to 4 files')
        ->assertSee(__('feedback-board::feedback-board.form.context'))
        ->assertSeeHtml('class="fb-scope fb-form fb-sheet"')
        ->assertSeeHtml('data-fb-theme="class"');
});

it('feeds the picker, drop and paste through one sequential upload queue', function () {
    $html = Livewire::actingAs(fbFormUser())->test(FeedbackForm::class)->html();

    expect($html)->toContain('x-on:paste="pasted($event)"')
        ->toContain('x-on:change="picked($event)"')
        ->toContain('x-on:drop.prevent="dropped($event)"')
        ->toContain("await new Promise((done) => this.\$wire.upload('upload', file, done, done, (event) => { this.progress = event.detail.progress }, done));")
        ->toContain("const types = { 'image/jpeg': 'jpg', 'image/png': 'png', 'image/gif': 'gif', 'image/webp': 'webp' };")
        ->toContain("const generic = ['', 'image.png', 'image.jpg', 'image.jpeg', 'image.gif', 'image.webp'];")
        ->toContain('x-bind:disabled="uploading > 0" wire:loading.attr="disabled" wire:target="submit"')
        ->not->toContain('wire:model="files"')
        ->not->toContain('uploadMultiple');
});

it('derives every element id and the radio group name from the component id', function () {
    $this->actingAs(fbFormUser());

    $html = Blade::render("@livewire('feedback-board::feedback-form')\n@livewire('feedback-board::feedback-form')");

    preg_match_all('/\sid="([^"]+)"/', $html, $ids);
    preg_match_all('/\sname="([^"]+)"/', $html, $names);

    expect($ids[1])->not->toBeEmpty()
        ->and(array_count_values($ids[1]))->each->toBe(1)
        ->and(array_values(array_unique($names[1])))->toHaveCount(2);
});

it('moves focus to the sent panel after a successful submit', function () {
    Livewire::actingAs(fbFormUser())->test(FeedbackForm::class)
        ->set('title', 'Cannot export')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSeeHtml('role="status" tabindex="-1" x-init="$nextTick(() => $el.focus())"');
});

it('formats file sizes for the upload list', function (int $bytes, string $text) {
    expect(AttachmentStorage::humanSize($bytes))->toBe($text);
})->with([[200, '1 KB'], [245760, '240 KB'], [1572864, '1.5 MB']]);

it('formats a size limit in megabytes without a trailing zero', function (int $kb, string $size) {
    expect(AttachmentStorage::megabytes($kb))->toBe($size);
})->with([[10240, '10'], [512, '0.5'], [1536, '1.5'], [1000, '1'], [102400, '100']]);

it('reads PHP ini size values in kilobytes', function (string $value, ?int $kb) {
    expect(AttachmentStorage::iniSizeToKb($value))->toBe($kb);
})->with([['2M', 2048], ['512K', 512], ['1G', 1048576], ['2097152', 2048], ['8m', 8192], ['0', null], ['', null], ['abc', null]]);

it('never promises more than PHP accepts', function () {
    config()->set('feedback-board.attachments.max_kb', 10_000_000);
    $php = AttachmentStorage::iniSizeToKb((string) ini_get('upload_max_filesize'));
    $livewire = 12288; // Livewire's default temporary-upload `max:` rule

    expect(app(AttachmentStorage::class)->limits()['size'])
        ->toBe(AttachmentStorage::megabytes(min(array_filter([10_000_000, $php, $livewire]))));
});

it('never promises more than Livewire accepts for temporary uploads', function () {
    config()->set('feedback-board.attachments.max_kb', 5120);
    config()->set('livewire.temporary_file_upload.rules', 'required|file|max:256');

    expect(app(AttachmentStorage::class)->limits()['size'])->toBe('0.3');
});
```

In `tests/Feature/LivewireRegistrationTest.php`, replace `'renders the launcher as a themed, direction-aware dialog trigger'` with:

```php
it('renders the launcher as a themed, direction-aware slide-over trigger', function () {
    $this->actingAs(TestUser::create(['name' => 'Client', 'email' => 'c@x.com']));
    config()->set('feedback-board.ui.theme', 'dark');
    app()->setLocale('ar');

    $html = Blade::render('<x-feedback-board::launcher />');

    expect($html)->toContain('class="fb-scope"')
        ->and($html)->toContain('data-fb-theme="dark"')
        ->and($html)->toContain('dir="rtl"')
        ->and($html)->toContain('aria-haspopup="dialog"')
        ->and($html)->toContain('<span class="fb-launcher__label">أبلغ عن مشكلة</span>')
        ->and($html)->toContain('class="fb-slide-over" id="fb-launcher-panel"')
        ->and($html)->toContain('role="dialog" aria-modal="true" aria-labelledby="fb-launcher-title" x-trap.noscroll="open"')
        ->and($html)->toContain('id="fb-launcher-title"')
        ->and($html)->toContain('x-on:keydown.escape.window="open && (open = false)"')
        ->and($html)->not->toContain('x-transition')
        ->and($html)->not->toContain('fb-modal')
        ->and($html)->toContain("\$dispatch('fb-launcher-opened')")
        ->and($html)->toContain("x-on:fb-launcher-opened.window=\"\$wire.sent && \$wire.set('sent', false)\"")
        ->and($html)->toContain('wire:model="title" maxlength="255" aria-required="true" dir="auto" placeholder="مثال: زر الحفظ لا يستجيب في صفحة الطلبات"')
        ->and($html)->toContain('aria-label="إغلاق"');
});
```

In `tests/Unit/UiTest.php`, append:

```php
it('links "My reports" on the standalone page, optionally opening one report', function () {
    expect(Ui::reportsUrl())->toBe(route('feedback-board.page'))
        ->and(Ui::reportsUrl(5))->toBe(route('feedback-board.page').'?report=5')
        ->and(Ui::reportLink('https://app.test/admin/my-feedback?tab=x', 7))->toBe('https://app.test/admin/my-feedback?tab=x&report=7');
});
```

In `tests/Unit/StylesheetCoverageTest.php`, add `'fb-launcher-panel'` to `$notClasses`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/FeedbackFormTest.php tests/Feature/LivewireRegistrationTest.php tests/Unit/UiTest.php`
Expected: FAIL — no `upload` property, `files` is not locked, no radio cards, no `Ui::reportsUrl()`, the launcher still renders `fb-modal`.

- [ ] **Step 3: The staging trait and the upload queue**

Create `src/Livewire/Concerns/WithStagedUploads.php`:

```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Livewire\Concerns;

use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};
use YousefAman\FeedbackBoard\Services\AttachmentStorage;

/**
 * One upload path for the picker, drop and paste: the browser sends each file on its own to the
 * `upload` slot ($wire.upload('upload', file)), and updatedUpload() validates it and moves it into
 * the locked `files` list. The client can never set `files` directly. The same JavaScript runs on
 * Livewire 3 and 4, with no uploadMultiple() (its S3 restriction and single large POST).
 */
trait WithStagedUploads
{
    use WithFileUploads;

    /** The staging slot for one finished upload; always empty between requests. */
    public $upload = null;

    /** @var list<TemporaryUploadedFile> */
    #[Locked]
    public array $files = [];

    /** The validation rules for one staged file. */
    abstract protected function uploadRules(): array;

    public function updatedUpload(mixed $value): void
    {
        try {
            if (! $value instanceof TemporaryUploadedFile) {
                return;
            }

            $validator = Validator::make(
                ['upload' => $value],
                ['upload' => $this->uploadRules()],
                [],
                ['upload' => $value->getClientOriginalName()],
            );

            if ($validator->fails()) {
                $value->delete();
                $this->addError('upload', $validator->errors()->first('upload'));

                return;
            }

            $max = app(AttachmentStorage::class)->maxFiles();

            if (count($this->files) >= $max) {
                $value->delete();
                $this->addError('upload', __('feedback-board::feedback-board.form.max_files_reached', ['count' => $max]));

                return;
            }

            $this->files[] = $value;
        } finally {
            $this->upload = null;
        }
    }

    public function removeFile(int $index): void
    {
        $file = $this->files[$index] ?? null;

        if ($file instanceof TemporaryUploadedFile) {
            $file->delete();
        }

        unset($this->files[$index]);
        $this->files = array_values($this->files);
    }

    /**
     * Stores the staged files once their record exists (after its transaction: disk writes cannot
     * roll back) and empties the list. A failing file is reported and skipped; false tells the
     * view to say so.
     */
    protected function storeStagedFiles(AttachmentStorage $storage, FeedbackItem $item, ?FeedbackComment $comment = null): bool
    {
        $stored = true;

        foreach ($this->files as $file) {
            try {
                $storage->store($file, $item, $comment);
            } catch (\Throwable $e) {
                report($e);
                $stored = false;
            }
        }

        $this->files = [];

        return $stored;
    }
}
```

Create `resources/views/partials/upload-queue.blade.php` (it is printed inside an `x-data="{ … }"` attribute, so it must contain no double quotes):

```blade
{{-- Alpine members shared by the report form and both composers; printed inside x-data="{ … }", so no double quotes. --}}
uploading: 0,
progress: 0,
dragging: false,
queue: [],
draining: false,
enqueue(files) {
    for (const file of Array.from(files || [])) {
        this.queue.push(this.named(file));
        this.uploading++;
    }
    this.drain();
},
async drain() {
    if (this.draining) return;
    this.draining = true;
    while (this.queue.length > 0) {
        const file = this.queue.shift();
        this.progress = 0;
        /* Each file resolves on finish, error or cancel, so a failure never stalls the rest. The next one starts
           from this continuation, never inside Livewire's callback (that runs before its own queue check). */
        await new Promise((done) => this.$wire.upload('upload', file, done, done, (event) => { this.progress = event.detail.progress }, done));
        this.uploading--;
    }
    this.progress = 0;
    this.draining = false;
},
named(file) {
    const types = { 'image/jpeg': 'jpg', 'image/png': 'png', 'image/gif': 'gif', 'image/webp': 'webp' };
    const generic = ['', 'image.png', 'image.jpg', 'image.jpeg', 'image.gif', 'image.webp'];
    const extension = types[file.type];
    if (! extension || ! generic.includes(file.name || '')) return file;
    const now = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    const stamp = now.getFullYear() + pad(now.getMonth() + 1) + pad(now.getDate()) + '-' + pad(now.getHours()) + pad(now.getMinutes()) + pad(now.getSeconds());
    return new File([file], 'screenshot-' + stamp + '.' + extension, { type: file.type });
},
pasted(event) {
    const data = event.clipboardData;
    let files = Array.from((data && data.files) || []);
    if (files.length === 0) {
        files = Array.from((data && data.items) || []).filter((item) => item.kind === 'file').map((item) => item.getAsFile()).filter(Boolean);
    }
    if (files.length === 0) return;
    event.preventDefault();
    this.enqueue(files);
},
picked(event) {
    this.enqueue(event.target.files);
    event.target.value = '';
},
dropped(event) {
    this.dragging = false;
    this.enqueue(event.dataTransfer ? event.dataTransfer.files : []);
},
```

- [ ] **Step 4: `Ui` links and `AttachmentStorage::humanSize()`**

In `src/Support/Ui.php`, add after `safeUrl()`:

```php
    /** "My reports" on the standalone page, optionally opening one report. */
    public static function reportsUrl(?int $reportId = null): string
    {
        $url = route('feedback-board.page');

        return $reportId === null ? $url : self::reportLink($url, $reportId);
    }

    /** Appends the deep-link query that opens one report. */
    public static function reportLink(string $base, int $reportId): string
    {
        return $base.(str_contains($base, '?') ? '&' : '?').'report='.$reportId;
    }
```

In `src/Services/AttachmentStorage.php`, add after `megabytes()`:

```php
    /** 245760 → "240 KB", 1572864 → "1.5 MB" (at least 1 KB). */
    public static function humanSize(int $bytes): string
    {
        $kb = max(1, (int) round($bytes / 1024));

        return $kb < 1024
            ? __('feedback-board::feedback-board.files.kb', ['size' => $kb])
            : __('feedback-board::feedback-board.files.mb', ['size' => self::megabytes($kb)]);
    }
```

- [ ] **Step 5: The form component**

Replace `src/Livewire/FeedbackForm.php` with:

```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use YousefAman\FeedbackBoard\Actions\CreateFeedbackItem;
use YousefAman\FeedbackBoard\Enums\FeedbackType;
use YousefAman\FeedbackBoard\Livewire\Concerns\WithStagedUploads;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Services\AttachmentStorage;
use YousefAman\FeedbackBoard\Support\Ui;

class FeedbackForm extends Component
{
    use WithStagedUploads;

    public string $title = '';
    public string $description = '';
    public string $type = 'bug';
    public bool $sent = false;

    /** The reference shown once the report exists. */
    #[Locked]
    public ?int $sentId = null;

    /** "My reports" (in the current Filament panel when there is one), fixed at mount. */
    #[Locked]
    public string $reportsUrl = '';

    /** The page the form was opened on, for the context notice. */
    #[Locked]
    public string $pageUrl = '';

    #[Locked]
    public bool $attachmentsFailed = false;

    public function mount(): void
    {
        $this->reportsUrl = Ui::reportsUrl();
        $this->pageUrl = url()->current();
    }

    public function submit(CreateFeedbackItem $create, AttachmentStorage $storage): void
    {
        $this->authorize('create', FeedbackItem::class);
        $this->sent = false;

        $data = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['required', 'in:bug,feature,question,other'],
            'files' => ['array', 'max:'.$storage->maxFiles()],
            'files.*' => $storage->allowedRules(),
        ]);

        $item = $create->handle([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'type' => FeedbackType::from($data['type']),
            'meta' => [
                'url' => request()->headers->get('referer'),
                'user_agent' => request()->userAgent(),
                'locale' => app()->getLocale(),
            ],
        ], auth()->user());

        $this->attachmentsFailed = ! $this->storeStagedFiles($storage, $item);

        $this->reset('title', 'description', 'type');
        $this->resetErrorBag();
        $this->sentId = $item->id;
        $this->sent = true;
        $this->dispatch('feedback-submitted', id: $item->id);
    }

    protected function uploadRules(): array
    {
        return app(AttachmentStorage::class)->allowedRules();
    }

    public function render(): View
    {
        $storage = app(AttachmentStorage::class);
        $limits = $storage->limits();

        return view('feedback-board::livewire.feedback-form', [
            'uid' => 'fb-'.$this->getId(),
            'types' => FeedbackType::cases(),
            'attachmentTypes' => $limits['types'],
            'attachmentSize' => $limits['size'],
            'maxFiles' => $storage->maxFiles(),
            'followUrl' => $this->sentId === null ? $this->reportsUrl : Ui::reportLink($this->reportsUrl, $this->sentId),
        ]);
    }
}
```

- [ ] **Step 6: The form, sheet and launcher views**

Replace `resources/views/livewire/feedback-form.blade.php` with:

```blade
<div class="fb-scope fb-form fb-sheet" data-fb-theme="{{ \YousefAman\FeedbackBoard\Support\Ui::theme() }}" dir="{{ __('feedback-board::feedback-board.dir') }}"
     x-data="{ @include('feedback-board::partials.upload-queue') }"
     x-on:fb-launcher-opened.window="$wire.sent && $wire.set('sent', false)">
    @include('feedback-board::partials.assets')
    @if ($sent)
        <div class="fb-sheet__body">
            <div class="fb-sent" role="status" tabindex="-1" x-init="$nextTick(() => $el.focus())">
                <span class="fb-sent__icon"><x-feedback-board::icon name="check-circle" /></span>
                <p class="fb-sent__title">{{ __('feedback-board::feedback-board.form.sent_title') }}</p>
                <p class="fb-sent__body">{{ __('feedback-board::feedback-board.form.sent_ref') }} <span class="fb-sent__ref" dir="ltr">#{{ $sentId }}</span>. {{ __('feedback-board::feedback-board.form.sent_body') }}</p>
                @if ($attachmentsFailed)
                    <p class="fb-notice fb-notice--warning">{{ __('feedback-board::feedback-board.form.attachments_failed') }}</p>
                @endif
                <div class="fb-sent__actions">
                    <a class="fb-btn fb-btn--primary" href="{{ $followUrl }}">{{ __('feedback-board::feedback-board.form.follow') }}</a>
                    <button type="button" class="fb-btn" wire:click="$set('sent', false)">{{ __('feedback-board::feedback-board.form.send_another') }}</button>
                </div>
            </div>
        </div>
    @else
        <form wire:submit="submit" class="fb-sheet" x-on:paste="pasted($event)">
            <div class="fb-sheet__body">
                <fieldset class="fb-fieldset">
                    <legend class="fb-label fb-legend">{{ __('feedback-board::feedback-board.form.type') }}</legend>
                    <div class="fb-types">
                        @foreach ($types as $type)
                            <label class="fb-type">
                                <input type="radio" class="fb-type__input" name="{{ $uid }}-type" value="{{ $type->value }}" wire:model="type">
                                <span class="fb-type__card">
                                    <x-feedback-board::type-icon :type="$type" class="fb-type-icon--sm" />
                                    <span class="fb-type__text">
                                        <span class="fb-type__label">{{ $type->label() }}</span>
                                        <span class="fb-type__hint">{{ $type->hint() }}</span>
                                    </span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('type') <span class="fb-error">{{ $message }}</span> @enderror
                </fieldset>

                <div class="fb-field">
                    <label class="fb-label" for="{{ $uid }}-title">{{ __('feedback-board::feedback-board.form.title') }} <span class="fb-required" aria-hidden="true">*</span></label>
                    <input type="text" id="{{ $uid }}-title" class="fb-input" wire:model="title" maxlength="255" aria-required="true" dir="auto" placeholder="{{ __('feedback-board::feedback-board.form.title_placeholder') }}" />
                    @error('title') <span class="fb-error">{{ $message }}</span> @enderror
                </div>

                <div class="fb-field">
                    <label class="fb-label" for="{{ $uid }}-description">{{ __('feedback-board::feedback-board.form.description') }}</label>
                    <textarea id="{{ $uid }}-description" class="fb-textarea" wire:model="description" rows="4" dir="auto" placeholder="{{ __('feedback-board::feedback-board.form.description_placeholder') }}"></textarea>
                    @error('description') <span class="fb-error">{{ $message }}</span> @enderror
                </div>

                <div class="fb-field">
                    <span class="fb-label">{{ __('feedback-board::feedback-board.form.attachments') }}</span>
                    <label class="fb-dropzone" x-bind:class="{ 'fb-dropzone--over': dragging }"
                           x-on:dragover.prevent="dragging = true" x-on:dragleave="dragging = false" x-on:drop.prevent="dropped($event)">
                        <input type="file" class="fb-file-input" multiple x-on:change="picked($event)">
                        <x-feedback-board::icon name="arrow-up-tray" class="fb-dropzone__icon" />
                        <span class="fb-dropzone__title">{{ __('feedback-board::feedback-board.form.drop') }} <span class="fb-dropzone__browse">{{ __('feedback-board::feedback-board.form.browse') }}</span></span>
                        <span class="fb-dropzone__hint">{{ __('feedback-board::feedback-board.form.paste_hint') }} · {{ __('feedback-board::feedback-board.form.attachments_hint', ['types' => $attachmentTypes, 'size' => $attachmentSize]) }} · {{ __('feedback-board::feedback-board.form.max_files', ['count' => $maxFiles]) }}</span>
                    </label>
                    <p class="fb-hint" x-show="uploading > 0" x-cloak>{{ __('feedback-board::feedback-board.form.uploading') }} <span x-text="progress + '%'"></span></p>
                    @if ($files !== [])
                        <ul class="fb-uploads">
                            @foreach ($files as $index => $file)
                                <li class="fb-upload" wire:key="{{ $uid }}-file-{{ $index }}-{{ $file->getFilename() }}">
                                    <span class="fb-upload__thumb">
                                        @if (\YousefAman\FeedbackBoard\Support\Images::matches($file->getMimeType(), $file->getClientOriginalName()))
                                            <img src="{{ $file->temporaryUrl() }}" alt="">
                                        @else
                                            <x-feedback-board::icon name="document" />
                                        @endif
                                    </span>
                                    <span class="fb-upload__meta">
                                        <span class="fb-upload__name" dir="auto">{{ $file->getClientOriginalName() }}</span>
                                        <span class="fb-upload__size">{{ \YousefAman\FeedbackBoard\Services\AttachmentStorage::humanSize((int) $file->getSize()) }}</span>
                                    </span>
                                    <button type="button" class="fb-icon-btn" wire:click="removeFile({{ $index }})">
                                        <x-feedback-board::icon name="x-mark" />
                                        <span class="fb-sr-only">{{ __('feedback-board::feedback-board.form.remove_file', ['name' => $file->getClientOriginalName()]) }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @foreach (['upload', 'files', 'files.*'] as $field)
                        @error($field) <span class="fb-error">{{ $message }}</span> @enderror
                    @endforeach
                </div>

                <div class="fb-context-note">
                    <x-feedback-board::icon name="information-circle" />
                    <p>{{ __('feedback-board::feedback-board.form.context') }} <span class="fb-context-note__url" dir="ltr">{{ $pageUrl }}</span></p>
                </div>
            </div>

            <div class="fb-sheet__foot">
                <button type="submit" class="fb-btn fb-btn--primary fb-btn--lg" x-bind:disabled="uploading > 0" wire:loading.attr="disabled" wire:target="submit">
                    <x-feedback-board::icon name="paper-airplane" />{{ __('feedback-board::feedback-board.form.submit') }}
                </button>
                <button type="button" class="fb-btn fb-btn--lg" x-on:click="$dispatch('fb-overlay-close')">{{ __('feedback-board::feedback-board.form.cancel') }}</button>
                <a class="fb-link fb-sheet__link" href="{{ $reportsUrl }}">{{ __('feedback-board::feedback-board.form.view_reports') }}</a>
            </div>
        </form>
    @endif
</div>
```

Create `resources/views/partials/report-form-sheet.blade.php`:

```blade
{{-- The new-report sheet inside a slide-over: heading, intro and close, then the form. Needs $titleId. --}}
<div class="fb-slide-over__head">
    <div class="fb-slide-over__titles">
        <h2 id="{{ $titleId }}" class="fb-slide-over__title">{{ __('feedback-board::feedback-board.form.heading') }}</h2>
        <p class="fb-slide-over__sub">{{ __('feedback-board::feedback-board.form.intro') }}</p>
    </div>
    <button type="button" class="fb-icon-btn" x-on:click="$dispatch('fb-overlay-close')" aria-label="{{ __('feedback-board::feedback-board.close') }}">
        <x-feedback-board::icon name="x-mark" />
    </button>
</div>
@livewire('feedback-board::feedback-form')
```

Replace `resources/views/components/launcher.blade.php` with:

```blade
<div class="fb-scope" data-fb-theme="{{ \YousefAman\FeedbackBoard\Support\Ui::theme() }}" dir="{{ __('feedback-board::feedback-board.dir') }}"
     x-data="{ open: false }">
    @include('feedback-board::partials.assets')
    <button type="button" class="fb-launcher" x-on:click="open = true; $dispatch('fb-launcher-opened')" aria-haspopup="dialog" x-bind:aria-expanded="open">
        <x-feedback-board::icon name="chat-bubble-oval-left-ellipsis" class="fb-launcher__icon" />
        <span class="fb-launcher__label">{{ __('feedback-board::feedback-board.launcher') }}</span>
    </button>
    <x-feedback-board::slide-over id="fb-launcher-panel" labelledby="fb-launcher-title">
        @include('feedback-board::partials.report-form-sheet', ['titleId' => 'fb-launcher-title'])
    </x-feedback-board::slide-over>
</div>
```

- [ ] **Step 7: Stylesheet**

In `resources/dist/feedback-board.css`:

a) Buttons: after `.fb-btn .fb-icon`, add:

```css
.fb-btn--lg {
    min-height: 2.75rem;
    padding: 0.625rem 1.125rem;
    font-size: 0.9375rem;
}
```

b) Fields: delete the `.fb-file` and `.fb-file::file-selector-button` rules.

c) Launcher: rename the section comment to `/* ---------- Launcher ---------- */`. Delete `.fb-modal`, `.fb-modal__panel`, `.fb-modal__header`, `.fb-modal__title`, `.fb-modal__body`. Replace the section's `@media (max-width: 40rem)` block (only the icon shows below 560 px) with:

```css
@media (max-width: 35rem) {
    .fb-launcher {
        inset-block-end: calc(1rem + env(safe-area-inset-bottom, 0px));
        inset-inline-end: 1rem;
        justify-content: center;
        inline-size: 3.25rem;
        block-size: 3.25rem;
        padding: 0;
    }

    .fb-launcher__label {
        position: absolute;
        width: 1px;
        height: 1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
    }
}
```

d) Replace the whole "Submission form" section (`.fb-form, .fb-form__fields`, `.fb-form__actions` and every `.fb-form__success*` rule) with:

```css
/* ---------- Report form ---------- */

.fb-form {
    background: var(--_fb-surface);
}

.fb-fieldset {
    display: grid;
    gap: 0.5rem;
    min-inline-size: 0;
    margin: 0;
    padding: 0;
    border: 0;
}

.fb-legend {
    margin-block-end: 0.5rem;
    padding: 0;
}

.fb-required {
    color: var(--_fb-danger-fg);
}

.fb-types {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.625rem;
}

.fb-type {
    position: relative;
    display: block;
    min-inline-size: 0;
    cursor: pointer;
}

.fb-type__input {
    position: absolute;
    inline-size: 1px;
    block-size: 1px;
    margin: 0;
    opacity: 0;
}

.fb-type__card {
    position: relative;
    isolation: isolate;
    display: flex;
    align-items: flex-start;
    gap: 0.625rem;
    block-size: 100%;
    padding: 0.75rem;
    border: 1px solid var(--_fb-border-strong);
    border-radius: 0.6875rem;
    background: var(--_fb-surface);
    transition: border-color 0.15s ease;
}

.fb-type:hover .fb-type__card {
    border-color: var(--_fb-text-muted);
}

.fb-type__input:checked + .fb-type__card {
    border-color: var(--_fb-accent);
    box-shadow: inset 0 0 0 1px var(--_fb-accent);
}

.fb-type__input:focus-visible + .fb-type__card {
    outline: 2px solid var(--_fb-ring);
    outline-offset: 2px;
}

.fb-type__text {
    display: grid;
    min-inline-size: 0;
}

.fb-type__label {
    font-size: 0.875rem;
    font-weight: 600;
}

.fb-type__hint {
    color: var(--_fb-text-muted);
    font-size: 0.75rem;
    line-height: 1.5;
}

/* Visually hidden but focusable: its label shows the focus ring through :focus-within. */
.fb-file-input {
    position: absolute;
    inline-size: 1px;
    block-size: 1px;
    overflow: hidden;
    opacity: 0;
}

.fb-dropzone {
    position: relative;
    display: grid;
    justify-items: center;
    gap: 0.375rem;
    padding: 1.125rem 0.875rem;
    border: 1.5px dashed var(--_fb-border-strong);
    border-radius: 0.6875rem;
    background: var(--_fb-bg);
    color: var(--_fb-text-muted);
    text-align: center;
    cursor: pointer;
}

.fb-dropzone:hover,
.fb-dropzone--over {
    border-color: var(--_fb-accent);
    color: var(--_fb-text);
}

.fb-dropzone:focus-within {
    outline: 2px solid var(--_fb-ring);
    outline-offset: 2px;
}

.fb-dropzone__icon {
    inline-size: 1.625rem;
    block-size: 1.625rem;
}

.fb-dropzone__title {
    color: var(--_fb-text);
    font-size: 0.875rem;
    font-weight: 600;
}

.fb-dropzone__browse {
    color: var(--_fb-link);
}

.fb-dropzone__hint {
    font-size: 0.75rem;
}

.fb-uploads {
    display: grid;
    gap: 0.5rem;
}

.fb-upload {
    display: flex;
    align-items: center;
    gap: 0.625rem;
    min-inline-size: 0;
    padding: 0.5rem;
    border: 1px solid var(--_fb-border);
    border-radius: 0.625rem;
    background: var(--_fb-surface);
}

.fb-upload__thumb {
    display: grid;
    place-items: center;
    inline-size: 2.5rem;
    block-size: 2.5rem;
    overflow: hidden;
    border-radius: 0.4375rem;
    background: var(--_fb-surface-muted);
    color: var(--_fb-text-muted);
    flex: none;
}

.fb-upload__thumb img {
    inline-size: 100%;
    block-size: 100%;
    object-fit: cover;
    object-position: top;
}

.fb-upload__meta {
    display: grid;
    flex: 1;
    min-inline-size: 0;
}

.fb-upload__name {
    overflow: hidden;
    font-size: 0.8125rem;
    font-weight: 500;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.fb-upload__size {
    color: var(--_fb-text-muted);
    font-size: 0.75rem;
}

.fb-context-note {
    display: flex;
    align-items: flex-start;
    gap: 0.625rem;
    padding: 0.75rem 0.875rem;
    border: 1px solid var(--_fb-border);
    border-radius: 0.625rem;
    background: var(--_fb-bg);
    font-size: 0.8125rem;
}

.fb-context-note .fb-icon {
    inline-size: 1.125rem;
    block-size: 1.125rem;
    margin-block-start: 0.125rem;
    color: var(--_fb-text-muted);
}

.fb-context-note__url {
    display: block;
    color: var(--_fb-text-muted);
    font-family: var(--_fb-mono);
    font-size: 0.75rem;
    overflow-wrap: anywhere;
    unicode-bidi: isolate;
}

.fb-sheet__link {
    margin-inline-start: auto;
    font-size: 0.8125rem;
    font-weight: 600;
    text-decoration: none;
}

.fb-sent {
    display: grid;
    justify-items: center;
    gap: 0.625rem;
    padding: 2rem 0.625rem 0.625rem;
    text-align: center;
}

.fb-sent:focus {
    outline: none;
}

.fb-sent__icon {
    display: grid;
    place-items: center;
    inline-size: 4rem;
    block-size: 4rem;
    border-radius: 999px;
    background: var(--_fb-success-bg);
    color: var(--_fb-success-fg);
}

.fb-sent__icon .fb-icon {
    inline-size: 2rem;
    block-size: 2rem;
}

.fb-sent__title {
    font-size: 1.25rem;
    font-weight: 700;
}

.fb-sent__body {
    max-inline-size: 34ch;
    color: var(--_fb-text-muted);
}

.fb-sent__ref {
    color: var(--_fb-text);
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}

.fb-sent__actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 0.625rem;
    margin-block-start: 0.625rem;
}

@media (max-width: 35rem) {
    .fb-types {
        grid-template-columns: minmax(0, 1fr);
    }
}
```

e) Tints: add the checked type card to the `::before` selector list, so the rule reads:

```css
.fb-tint::before,
.fb-type-icon--feature::before,
.fb-type__input:checked + .fb-type__card::before {
```

f) Forced colours: extend the selector of the rule inside `@media (forced-colors: active)`:

```css
    .fb-scope [aria-pressed="true"],
    .fb-type__input:checked + .fb-type__card {
```

- [ ] **Step 8: Translations**

In `resources/lang/en/feedback-board.php`, keep `'launcher' => 'Report an issue',` and replace the whole `'form' => [...]` block with:

```php
    'form' => [
        'heading' => 'Report an issue',
        'intro' => 'Describe what happened. Your report reaches the support team, and you can follow the reply from “My reports”.',
        'type' => 'Report type',
        'title' => 'Title', 'title_placeholder' => 'Example: the Save button does nothing on the orders page',
        'description' => 'Details', 'description_placeholder' => 'What were you trying to do, and what happened instead?',
        'attachments' => 'Attachments',
        'drop' => 'Drag images or files here, or', 'browse' => 'choose from your device',
        'paste_hint' => 'or paste a screenshot directly',
        'attachments_hint' => 'Allowed: :types · up to :size MB each',
        'max_files' => 'Up to :count files',
        'max_files_reached' => 'You can attach up to :count files.',
        'remove_file' => 'Remove :name',
        'uploading' => 'Uploading…',
        'context' => 'We attach the page you were on and your browser automatically, so we can find the problem faster.',
        'submit' => 'Send report', 'cancel' => 'Cancel', 'view_reports' => 'My previous reports',
        'sent_title' => 'We received your report',
        'sent_ref' => 'Report number',
        'sent_body' => "We'll notify you when someone replies, and you can always find it in “My reports”.",
        'follow' => 'Follow the report', 'send_another' => 'New report',
        'attachments_failed' => 'Some attachments could not be saved.',
    ],
    'files' => ['kb' => ':size KB', 'mb' => ':size MB'],
```

In `resources/lang/ar/feedback-board.php`, change `'launcher' => 'الإبلاغ عن مشكلة',` to `'launcher' => 'أبلغ عن مشكلة',` and replace the whole `'form' => [...]` block with:

```php
    'form' => [
        'heading' => 'الإبلاغ عن مشكلة',
        'intro' => 'صف ما حدث، وسيصل بلاغك لفريق الدعم الفني وتتابع الرد من «بلاغاتي».',
        'type' => 'نوع البلاغ',
        'title' => 'العنوان', 'title_placeholder' => 'مثال: زر الحفظ لا يستجيب في صفحة الطلبات',
        'description' => 'التفاصيل', 'description_placeholder' => 'ماذا كنت تحاول أن تفعل؟ وماذا حدث بدلًا من ذلك؟',
        'attachments' => 'المرفقات',
        'drop' => 'اسحب الصور أو الملفات هنا، أو', 'browse' => 'اختر من جهازك',
        'paste_hint' => 'أو الصق لقطة الشاشة مباشرة',
        'attachments_hint' => 'المسموح: :types · حتى :size ميغابايت للملف',
        'max_files' => 'حتى :count ملفات',
        'max_files_reached' => 'يمكنك إرفاق :count ملفات كحد أقصى.',
        'remove_file' => 'حذف :name',
        'uploading' => 'جارٍ الرفع…',
        'context' => 'سنرفق تلقائيًا رابط الصفحة التي كنت فيها ونوع المتصفح، لنصل للمشكلة أسرع.',
        'submit' => 'إرسال البلاغ', 'cancel' => 'إلغاء', 'view_reports' => 'بلاغاتي السابقة',
        'sent_title' => 'وصلنا بلاغك',
        'sent_ref' => 'رقم البلاغ',
        'sent_body' => 'سنرسل لك إشعارًا عند الرد عليه، وتجده دائمًا في «بلاغاتي».',
        'follow' => 'متابعة البلاغ', 'send_another' => 'بلاغ جديد',
        'attachments_failed' => 'تعذّر حفظ بعض المرفقات.',
    ],
    'files' => ['kb' => ':size ك.ب', 'mb' => ':size م.ب'],
```

- [ ] **Step 9: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/FeedbackFormTest.php tests/Feature/LivewireRegistrationTest.php tests/Unit/UiTest.php tests/Unit/StylesheetTest.php tests/Unit/StylesheetCoverageTest.php tests/Feature/LocalizationTest.php`
Expected: PASS.

Run: `php -l` on the changed PHP files, then `vendor/bin/pest`.
Expected: no failures. (`MyReportsBoard` still renders the form inline until Task 7; its tests stay green.)

- [ ] **Step 10: Commit**

```bash
git add src/Livewire/Concerns src/Livewire/FeedbackForm.php src/Support/Ui.php src/Services/AttachmentStorage.php resources/views resources/dist/feedback-board.css resources/lang tests/Feature/FeedbackFormTest.php tests/Feature/LivewireRegistrationTest.php tests/Unit
git commit -m "feat(form): slide-over report form with radio-card types and one staged upload path for picker, drop and paste"
```

---

### Task 7: My reports — filters, unread replies, deep link, and the reporter's conversation with images

**Files:**
- Create: `resources/views/partials/pending-images.blade.php`
- Modify: `src/Livewire/MyReportsBoard.php`, `src/Livewire/FeedbackItemModal.php` and rewrite both views
- Modify: `src/Livewire/Concerns/WithStagedUploads.php` (composer rules)
- Modify: `resources/dist/feedback-board.css`, both language files
- Test: `tests/Feature/MyReportsTest.php` (replaced), `tests/Feature/StandalonePagesTest.php`, `tests/Unit/StylesheetCoverageTest.php`

**Interfaces:**
- Consumes: Task 1 scopes and `markReadBy()`/`readMarker()`/`reporterActivity()`; Task 5 components and lightbox; Task 6 trait, queue partial and `report-form-sheet`.
- Produces:
  - `MyReportsBoard`: `#[Url(as: 'report')] public $report` (untyped), `#[Locked] string $filter` with `filterBy(string)`, `#[Locked] bool $inPanel` (mount parameter), `#[Locked] ?string $readBefore`, `open(int)`, `close()`, `const FILTERS = ['all', 'open', 'done']`. The root listens for the browser event `fb-open-report-form` (window) and opens its own form slide-over.
  - `FeedbackItemModal::mount(FeedbackItem $item, ?string $readBefore = null, ?string $titleId = null)`; `reply()` accepts `body` and/or staged images.
  - Trait additions: `protected composerRules(AttachmentStorage $storage): array`, `protected composerMessages(): array`.
  - Partial `feedback-board::partials.pending-images` (needs `$files`, `$uid`), reused by Task 9.
  - CSS: `fb-head` (+ `__titles`, `__title`, `__sub`), `fb-segmented` (+ `__btn`), `fb-count`, `fb-rows` (+ `__head`), `fb-row` (+ `__main`, `__text`, `__title`, `__ref`, `__time`, `__replies`, `__replies--new`, `__count`), `fb-unread`, `fb-steps`, `fb-step` (+ `--done`, `--current`, `__bar`), `fb-banner`, `fb-meta` (+ `__url`), `fb-doc-links`, `fb-doc-link`, `fb-chat`, `fb-msg` (+ `--mine`, `--theirs`, `__who`, `__author`, `__bubble`), `fb-new`, `fb-waiting`, `fb-reply` (+ `__row`, `__attach`, `__input`), `fb-pending` (+ `__item`, `__remove`), `fb-item`.

- [ ] **Step 1: Write the failing tests**

Replace `tests/Feature/MyReportsTest.php` with:

```php
<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use YousefAman\FeedbackBoard\Actions\TransitionStatus;
use YousefAman\FeedbackBoard\Enums\{AuthorRole, FeedbackStatus};
use YousefAman\FeedbackBoard\Livewire\{FeedbackItemModal, MyReportsBoard};
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};
use YousefAman\FeedbackBoard\Services\AttachmentStorage;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    config()->set('feedback-board.authorization.developer_resolver', fn ($u) => $u->name === 'Dev');
    Storage::fake('local');
    $this->travelTo('2026-10-01 09:00:00');
});

function fbReporter(): TestUser
{
    return TestUser::create(['name' => 'Me', 'email' => 'me@x.com']);
}

it('shows only the current user reports', function () {
    $me = fbReporter();
    FeedbackItem::factory()->for($me, 'reporter')->create(['title' => 'Mine']);
    FeedbackItem::factory()->create(['title' => 'Theirs']);

    Livewire::actingAs($me)->test(MyReportsBoard::class)->assertSee('Mine')->assertDontSee('Theirs');
});

it('lets the reporter reply on their own item', function () {
    $me = fbReporter();
    $item = FeedbackItem::factory()->for($me, 'reporter')->create();

    Livewire::actingAs($me)->test(FeedbackItemModal::class, ['item' => $item])
        ->set('body', 'any update?')->call('reply')->assertHasNoErrors();

    expect($item->comments()->where('author_role', AuthorRole::Reporter->value)->exists())->toBeTrue();
});

it('opens a report from the list in a wide slide-over and closes it', function () {
    $me = fbReporter();
    $item = FeedbackItem::factory()->for($me, 'reporter')->create(['title' => 'Mine']);

    Livewire::actingAs($me)->test(MyReportsBoard::class)
        ->call('open', $item->id)
        ->assertSet('report', $item->id)
        ->assertSeeLivewire('feedback-board::feedback-item-modal')
        ->assertSeeHtml('class="fb-slide-over fb-slide-over--wide"')
        ->assertSeeHtml('x-on:fb-overlay-close.stop="$wire.close()"')
        ->assertSeeHtml('x-on:keydown.escape.window="true && ($wire.close())"')
        ->call('close')
        ->assertSet('report', null)
        ->assertDontSeeLivewire('feedback-board::feedback-item-modal');
});

it('lists reports as keyboard-reachable rows with type, reference, status and public reply count', function () {
    $me = fbReporter();
    $item = FeedbackItem::factory()->for($me, 'reporter')->create(['title' => 'Mine']);
    FeedbackComment::factory()->for($item, 'item')->create(['author_role' => AuthorRole::Developer]);
    FeedbackComment::factory()->for($item, 'item')->create(['author_role' => AuthorRole::Developer, 'is_internal' => true]);

    Livewire::actingAs($me)->test(MyReportsBoard::class)
        ->assertSeeHtml('class="fb-scope fb-reports"')
        ->assertSeeHtml('<button type="button" class="fb-row"')
        ->assertSeeHtml('class="fb-type-icon fb-type-icon--bug"')
        ->assertSeeHtml('<span class="fb-row__title" dir="auto">Mine</span>')
        ->assertSeeHtml('<span class="fb-row__ref">#'.$item->id.' · Bug</span>')
        ->assertSeeHtml('fb-badge fb-badge--gray')
        ->assertSeeHtml('<span class="fb-row__count">1</span>')
        ->assertDontSeeHtml('style="cursor:pointer"');
});

it('filters reports into open and done with counts', function () {
    $me = fbReporter();
    FeedbackItem::factory()->for($me, 'reporter')->create(['title' => 'Fresh', 'status' => FeedbackStatus::New]);
    FeedbackItem::factory()->for($me, 'reporter')->create(['title' => 'Looking', 'status' => FeedbackStatus::InReview]);
    FeedbackItem::factory()->for($me, 'reporter')->create(['title' => 'Fixed', 'status' => FeedbackStatus::Resolved]);

    $component = Livewire::actingAs($me)->test(MyReportsBoard::class);

    expect($component->viewData('counts'))->toBe(['all' => 3, 'open' => 2, 'done' => 1]);

    $component->call('filterBy', 'done')
        ->assertSee('Fixed')->assertDontSee('Fresh')
        ->assertSeeHtml('aria-pressed="true" wire:click="filterBy(\'done\')"');

    $component->call('filterBy', 'open')->assertSee('Fresh')->assertSee('Looking')->assertDontSee('Fixed');
    $component->call('filterBy', 'nonsense')->assertSet('filter', 'all')->assertSee('Fixed');
});

it('shows an empty state for each filter', function () {
    $component = Livewire::actingAs(fbReporter())->test(MyReportsBoard::class)
        ->assertSee(__('feedback-board::feedback-board.board.empty_reports'));

    $component->call('filterBy', 'open')->assertSee(__('feedback-board::feedback-board.reports.empty_open'));
    $component->call('filterBy', 'done')->assertSee(__('feedback-board::feedback-board.reports.empty_done'));
});

it('shows its own heading and New report button outside a panel, and hides them inside one', function () {
    $me = fbReporter();

    Livewire::actingAs($me)->test(MyReportsBoard::class)
        ->assertSeeHtml('<h1 class="fb-head__title">'.__('feedback-board::feedback-board.board.my_reports').'</h1>')
        ->assertSeeHtml('x-on:click="$dispatch(\'fb-open-report-form\')"')
        ->assertSeeHtml('x-on:fb-open-report-form.window="formOpen = true; $dispatch(\'fb-launcher-opened\')"')
        ->assertSeeHtml('x-trap.noscroll="formOpen"')
        ->assertSeeLivewire('feedback-board::feedback-form');

    Livewire::actingAs($me)->test(MyReportsBoard::class, ['inPanel' => true])
        ->assertDontSeeHtml('class="fb-head"')
        ->assertSeeHtml('x-on:fb-open-report-form.window=');
});

it('shows the conversation chat-style without internal notes, even to a developer', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $item = FeedbackItem::factory()->for($dev, 'reporter')->create(['description' => "line one\nline two"]);
    FeedbackComment::factory()->for($item, 'item')->create([
        'body' => 'Mine to you', 'author_role' => AuthorRole::Reporter, 'author_type' => $dev->getMorphClass(), 'author_id' => $dev->id,
    ]);
    FeedbackComment::factory()->for($item, 'item')->create(['body' => 'We fixed it', 'author_role' => AuthorRole::Developer]);
    FeedbackComment::factory()->for($item, 'item')->create(['body' => 'secret root cause', 'author_role' => AuthorRole::Developer, 'is_internal' => true]);

    Livewire::actingAs($dev)->test(FeedbackItemModal::class, ['item' => $item])
        ->assertSeeHtml('class="fb-scope fb-item fb-sheet"')
        ->assertSeeHtml('<p class="fb-prose" dir="auto">line one')
        ->assertSeeHtml('class="fb-msg fb-msg--mine"')
        ->assertSee(__('feedback-board::feedback-board.item.you'))
        ->assertSeeHtml('class="fb-msg fb-msg--theirs"')
        ->assertSee(AuthorRole::Developer->label())
        ->assertSee('We fixed it')
        ->assertDontSee('secret root cause');
});

it('shows the status steps, and a dated banner for closed and declined reports', function () {
    $me = fbReporter();
    $item = FeedbackItem::factory()->for($me, 'reporter')->create(['status' => FeedbackStatus::InProgress]);

    Livewire::actingAs($me)->test(FeedbackItemModal::class, ['item' => $item])
        ->assertSeeHtml('class="fb-steps"')
        ->assertSeeHtml('class="fb-step fb-step--current" aria-current="step"')
        ->assertSeeHtml('class="fb-step fb-step--done"')
        ->assertDontSeeHtml('fb-banner');

    $this->travel(1)->hours();
    app(TransitionStatus::class)->handle($item->fresh(), FeedbackStatus::Declined, null);

    Livewire::actingAs($me)->test(FeedbackItemModal::class, ['item' => $item->fresh()])
        ->assertDontSeeHtml('class="fb-steps"')
        ->assertSeeHtml('class="fb-notice fb-banner fb-notice--danger"')
        ->assertSee(__('feedback-board::feedback-board.item.declined_banner'))
        ->assertSeeHtml('<time datetime="2026-10-01T10:00:00+00:00">');
});

it('marks a report read when it is opened, so its row loses the dot, and tags the newer replies', function () {
    $me = fbReporter();
    $item = FeedbackItem::factory()->for($me, 'reporter')->create(['title' => 'Mine']);
    $this->travel(1)->minutes();
    FeedbackComment::factory()->for($item, 'item')->create(['body' => 'Older reply', 'author_role' => AuthorRole::Developer]);
    $item->fresh()->markReadBy($me);
    $this->travel(1)->minutes();
    FeedbackComment::factory()->for($item, 'item')->create(['body' => 'Newer reply', 'author_role' => AuthorRole::Developer]);
    $updatedAt = $item->fresh()->updated_at;

    $board = Livewire::actingAs($me)->test(MyReportsBoard::class)
        ->assertSeeHtml('<span class="fb-unread" aria-hidden="true"></span>')
        ->assertSee(__('feedback-board::feedback-board.reports.new_reply'));

    $this->travel(1)->minutes();
    $board->call('open', $item->id)
        ->assertDontSeeHtml('<span class="fb-unread" aria-hidden="true"></span>')
        ->assertSet('readBefore', '2026-10-01T09:01:00+00:00');

    expect($item->fresh()->reporter_read_at->toDateTimeString())->toBe('2026-10-01 09:03:00')
        ->and($item->fresh()->updated_at)->toEqual($updatedAt);

    $html = Livewire::actingAs($me)
        ->test(FeedbackItemModal::class, ['item' => $item, 'readBefore' => '2026-10-01T09:01:00+00:00'])
        ->html();

    expect(substr_count($html, '<span class="fb-new">'))->toBe(1)
        ->and(strpos($html, 'Older reply'))->toBeLessThan(strpos($html, '<span class="fb-new">'))
        ->and(strpos($html, '<span class="fb-new">'))->toBeLessThan(strpos($html, 'Newer reply'));
});

it('opens a report from ?report= and opens nothing for anything else', function (mixed $report, bool $opens) {
    $me = fbReporter();
    $other = TestUser::create(['name' => 'Other', 'email' => 'o@x.com']);
    FeedbackItem::factory()->count(4)->for($other, 'reporter')->create();
    $mine = FeedbackItem::factory()->for($me, 'reporter')->create();

    expect($mine->id)->toBe(5);

    $component = Livewire::withQueryParams(['report' => $report])->actingAs($me)->test(MyReportsBoard::class)->assertOk();

    $opens
        ? $component->assertSeeLivewire('feedback-board::feedback-item-modal')
        : $component->assertDontSeeLivewire('feedback-board::feedback-item-modal');
    expect($mine->fresh()->reporter_read_at !== null)->toBe($opens);
})->with([
    'own id as a string' => ['5', true],
    'own id as an integer' => [5, true],
    'someone else\'s id' => ['1', false],
    'a missing id' => ['999', false],
    'letters' => ['abc', false],
    'an array' => [['5'], false],
    'a negative number' => ['-5', false],
]);

it('lets the reporter reply with images only, and reads replies by replying', function () {
    $me = fbReporter();
    $item = FeedbackItem::factory()->for($me, 'reporter')->create();

    $this->travel(1)->minutes();
    Livewire::actingAs($me)->test(FeedbackItemModal::class, ['item' => $item])
        ->set('upload', UploadedFile::fake()->image('shot.png'))
        ->call('reply')
        ->assertHasNoErrors()
        ->assertCount('files', 0);

    $comment = $item->comments()->latest('id')->first();
    expect($comment->body)->toBe('')
        ->and($comment->author_role)->toBe(AuthorRole::Reporter)
        ->and($comment->attachments)->toHaveCount(1)
        ->and($comment->attachments->first()->isImage())->toBeTrue()
        ->and($item->fresh()->reporter_read_at->toDateTimeString())->toBe('2026-10-01 09:01:00');
});

it('accepts only images in the reporter composer and needs text or an image', function () {
    $me = fbReporter();
    $item = FeedbackItem::factory()->for($me, 'reporter')->create();

    Livewire::actingAs($me)->test(FeedbackItemModal::class, ['item' => $item])
        ->set('upload', UploadedFile::fake()->create('notes.pdf', 5, 'application/pdf'))
        ->assertHasErrors('upload')
        ->assertCount('files', 0)
        ->call('reply')
        ->assertHasErrors(['body' => 'required_without'])
        ->assertSee(__('feedback-board::feedback-board.composer.empty'));

    expect($item->comments()->count())->toBe(0);
});

it('hides the image picker and ignores pastes when the host allows no image types', function () {
    config()->set('feedback-board.attachments.allowed_mimes', ['pdf']);
    $me = fbReporter();
    $item = FeedbackItem::factory()->for($me, 'reporter')->create();

    Livewire::actingAs($me)->test(FeedbackItemModal::class, ['item' => $item])
        ->assertDontSeeHtml('accept="image/*"')
        ->assertDontSeeHtml('x-on:paste=');
});

it('never shows the images of an internal note to the reporter', function () {
    $me = fbReporter();
    $item = FeedbackItem::factory()->for($me, 'reporter')->create();
    $note = FeedbackComment::factory()->for($item, 'item')->create(['is_internal' => true, 'author_role' => AuthorRole::Developer]);
    $secret = app(AttachmentStorage::class)->store(UploadedFile::fake()->image('secret.png'), $item, $note);
    $own = app(AttachmentStorage::class)->store(UploadedFile::fake()->image('mine.png'), $item);

    Livewire::actingAs($me)->test(FeedbackItemModal::class, ['item' => $item])
        ->assertSeeHtml('data-fb-src="'.$own->url().'"')
        ->assertDontSeeHtml('data-fb-src="'.$secret->url().'"')
        ->assertDontSee('secret.png');
});
```

In `tests/Feature/StandalonePagesTest.php`, replace `'renders no duplicate element ids on the reports page with a drawer open'` with:

```php
it('renders no duplicate element ids on the reports page with a report open', function () {
    $me = TestUser::create(['name' => 'Me', 'email' => 'me@x.com']);
    FeedbackItem::factory()->for($me, 'reporter')->count(2)->create();

    $html = \Livewire\Livewire::actingAs($me)
        ->test(\YousefAman\FeedbackBoard\Livewire\MyReportsBoard::class)
        ->call('open', FeedbackItem::first()->id)
        ->html();

    preg_match_all('/\sid="([^"]+)"/', $html, $ids);

    expect(preg_grep('/-report-title$/', $ids[1]))->toHaveCount(1)
        ->and(preg_grep('/-form-title$/', $ids[1]))->toHaveCount(1)
        ->and(array_count_values($ids[1]))->each->toBe(1);
});
```

In `tests/Unit/StylesheetCoverageTest.php`, add `'fb-open-report-form'` to `$notClasses`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/MyReportsTest.php tests/Feature/StandalonePagesTest.php`
Expected: FAIL — no `report` property, no filters, no rows, the modal has no chat markup.

- [ ] **Step 3: Composer rules in the trait**

In `src/Livewire/Concerns/WithStagedUploads.php`, add after `removeFile()`:

```php
    /** A comment composer posts text, images, or both. */
    protected function composerRules(AttachmentStorage $storage): array
    {
        return [
            'body' => ['nullable', 'string', 'required_without:files'],
            'files' => ['array', 'max:'.$storage->maxFiles()],
            'files.*' => $storage->imageRules(),
        ];
    }

    /** @return array<string, string> */
    protected function composerMessages(): array
    {
        return ['body.required_without' => __('feedback-board::feedback-board.composer.empty')];
    }
```

- [ ] **Step 4: The components**

Replace `src/Livewire/MyReportsBoard.php` with:

```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\{Locked, On, Url};
use Livewire\Component;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class MyReportsBoard extends Component
{
    /** @var list<string> */
    public const FILTERS = ['all', 'open', 'done'];

    /**
     * The open report (?report=). Untyped on purpose: Livewire 3 assigns query values without a
     * type guard, and #[Url] JSON-decodes "5" to an integer; reportId() normalises both.
     */
    #[Url(as: 'report')]
    public $report = null;

    #[Locked]
    public string $filter = 'all';

    /** Inside a Filament page, which owns the heading and the "New report" action. */
    #[Locked]
    public bool $inPanel = false;

    /** The read marker before this opening: replies after it are tagged "new". */
    #[Locked]
    public ?string $readBefore = null;

    public function mount(bool $inPanel = false): void
    {
        $this->inPanel = $inPanel;

        if (($id = self::reportId($this->report)) !== null) {
            $this->open($id);
        }
    }

    #[On('feedback-submitted')]
    public function refreshList(): void
    {
        // Re-render: the new report joins the list, and an open form keeps its sent state.
    }

    public function open(int $id): void
    {
        $item = $this->ownItems()->whereKey($id)->first();

        if ($item === null) {
            $this->close();

            return;
        }

        $this->readBefore = $item->readMarker()?->toIso8601String();
        $item->markReadBy(auth()->user());
        $this->report = $item->id;
    }

    public function close(): void
    {
        $this->report = null;
        $this->readBefore = null;
    }

    public function filterBy(string $filter): void
    {
        $this->filter = in_array($filter, self::FILTERS, true) ? $filter : 'all';
    }

    public function render(): View
    {
        $items = $this->ownItems()
            ->withUnreadCount()
            ->withCount(['comments as replies_count' => fn (Builder $comments) => $comments->where('is_internal', false)])
            ->latestForReporter()
            ->get();

        $open = $items->filter(fn (FeedbackItem $item) => $item->status->isOpen());
        $id = self::reportId($this->report);

        return view('feedback-board::livewire.my-reports-board', [
            'uid' => 'fb-'.$this->getId(),
            'counts' => ['all' => $items->count(), 'open' => $open->count(), 'done' => $items->count() - $open->count()],
            'items' => match ($this->filter) {
                'open' => $open->values(),
                'done' => $items->reject(fn (FeedbackItem $item) => $item->status->isOpen())->values(),
                default => $items,
            },
            'emptyText' => __(match ($this->filter) {
                'open' => 'feedback-board::feedback-board.reports.empty_open',
                'done' => 'feedback-board::feedback-board.reports.empty_done',
                default => 'feedback-board::feedback-board.board.empty_reports',
            }),
            'selected' => $id === null ? null : $items->firstWhere('id', $id),
        ]);
    }

    /** Scoped to the reporter: another user's id, or none, opens nothing. */
    private function ownItems(): Builder
    {
        return FeedbackItem::query()->ownedBy(auth()->user());
    }

    /** ctype_digit() must never see an int: it reads -128..255 as ASCII codes. */
    private static function reportId(mixed $value): ?int
    {
        return is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : null);
    }
}
```

Replace `src/Livewire/FeedbackItemModal.php` with:

```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Livewire\Component;
use YousefAman\FeedbackBoard\Actions\ReplyToFeedback;
use YousefAman\FeedbackBoard\Enums\{ActivityAction, AuthorRole, FeedbackStatus};
use YousefAman\FeedbackBoard\Livewire\Concerns\WithStagedUploads;
use YousefAman\FeedbackBoard\Models\{FeedbackAttachment, FeedbackComment, FeedbackItem};
use YousefAman\FeedbackBoard\Services\AttachmentStorage;

class FeedbackItemModal extends Component
{
    use WithStagedUploads;

    public FeedbackItem $item;
    public string $body = '';

    /** Replies after this moment are tagged "new"; MyReportsBoard sets it before marking the report read. */
    #[Locked]
    public ?string $readBefore = null;

    #[Locked]
    public string $titleId = '';

    #[Locked]
    public bool $imagesFailed = false;

    public function mount(FeedbackItem $item, ?string $readBefore = null, ?string $titleId = null): void
    {
        $this->item = $item;
        $this->readBefore = $readBefore;
        $this->titleId = $titleId ?? 'fb-'.$this->getId().'-title';
    }

    public function reply(ReplyToFeedback $reply, AttachmentStorage $storage): void
    {
        $this->authorize('reply', $this->item);
        $data = $this->validate($this->composerRules($storage), $this->composerMessages());

        $comment = $reply->handle($this->item, (string) ($data['body'] ?? ''), auth()->user(), AuthorRole::Reporter);
        $this->imagesFailed = ! $this->storeStagedFiles($storage, $this->item, $comment);
        $this->reset('body');

        // Replies that arrived while the reporter was reading are read now; readBefore stays, so
        // the "new" tags already shown remain.
        $this->item->markReadBy(auth()->user());
        $this->item->refresh();
    }

    protected function uploadRules(): array
    {
        return app(AttachmentStorage::class)->imageRules();
    }

    public function render(): View
    {
        $user = auth()->user();
        $readBefore = $this->readBefore !== null ? CarbonImmutable::parse($this->readBefore) : null;
        $own = $this->item->attachments()->whereNull('feedback_comment_id')->orderBy('id')->get();
        $terminal = in_array($this->item->status, [FeedbackStatus::Closed, FeedbackStatus::Declined], true);

        return view('feedback-board::livewire.feedback-item-modal', [
            'uid' => 'fb-'.$this->getId(),
            // The reporter view never shows internal notes, whoever is looking (developers included).
            'comments' => $this->item->comments()->where('is_internal', false)->with('attachments')->oldest()->orderBy('id')->get(),
            'images' => $own->filter(fn (FeedbackAttachment $a) => $a->isImage())->values(),
            'documents' => $own->reject(fn (FeedbackAttachment $a) => $a->isImage())->values(),
            'steps' => $this->steps(),
            'terminal' => $terminal,
            'terminalAt' => $terminal
                ? $this->item->activities()->where('action', ActivityAction::StatusChanged)->first()?->created_at
                : null,
            'canAttach' => app(AttachmentStorage::class)->imageMimes() !== [],
            'isMine' => fn (FeedbackComment $comment): bool => $user instanceof Model
                && $comment->author_type === $user->getMorphClass()
                && (string) $comment->author_id === (string) $user->getKey(),
            'isNew' => fn (FeedbackComment $comment): bool => $readBefore !== null
                && $comment->author_role !== AuthorRole::Reporter
                && $comment->created_at !== null
                && $comment->created_at->gt($readBefore),
        ]);
    }

    /** @return list<array{status: FeedbackStatus, done: bool, current: bool}> */
    private function steps(): array
    {
        $order = [FeedbackStatus::New, FeedbackStatus::InReview, FeedbackStatus::InProgress, FeedbackStatus::Resolved];
        $at = array_search($this->item->status, $order, true);

        return array_map(fn (FeedbackStatus $status, int $index) => [
            'status' => $status,
            'current' => $index === $at,
            'done' => $at !== false && ($index < $at || ($index === $at && $status === FeedbackStatus::Resolved)),
        ], $order, array_keys($order));
    }
}
```

- [ ] **Step 5: The views**

Create `resources/views/partials/pending-images.blade.php`:

```blade
{{-- Pending composer images, each removable; previews only for allow-listed images. Needs $files and $uid. --}}
@if ($files !== [])
    <div class="fb-pending">
        @foreach ($files as $index => $file)
            <span class="fb-pending__item" wire:key="{{ $uid }}-pending-{{ $index }}-{{ $file->getFilename() }}">
                @if (\YousefAman\FeedbackBoard\Support\Images::matches($file->getMimeType(), $file->getClientOriginalName()))
                    <img src="{{ $file->temporaryUrl() }}" alt="">
                @else
                    <x-feedback-board::icon name="photo" />
                @endif
                <button type="button" class="fb-pending__remove" wire:click="removeFile({{ $index }})">
                    <x-feedback-board::icon name="x-mark" />
                    <span class="fb-sr-only">{{ __('feedback-board::feedback-board.composer.remove_image') }}</span>
                </button>
            </span>
        @endforeach
    </div>
@endif
```

Replace `resources/views/livewire/my-reports-board.blade.php` with:

```blade
<div class="fb-scope fb-reports" data-fb-theme="{{ \YousefAman\FeedbackBoard\Support\Ui::theme() }}" dir="{{ __('feedback-board::feedback-board.dir') }}"
     x-data="{ formOpen: false }"
     x-on:fb-open-report-form.window="formOpen = true; $dispatch('fb-launcher-opened')">
    @include('feedback-board::partials.assets')

    @unless ($inPanel)
        <header class="fb-head">
            <div class="fb-head__titles">
                <h1 class="fb-head__title">{{ __('feedback-board::feedback-board.board.my_reports') }}</h1>
                <p class="fb-head__sub">{{ __('feedback-board::feedback-board.reports.subheading') }}</p>
            </div>
            <button type="button" class="fb-btn fb-btn--primary" x-on:click="$dispatch('fb-open-report-form')">
                <x-feedback-board::icon name="plus" />{{ __('feedback-board::feedback-board.board.new_report') }}
            </button>
        </header>
    @endunless

    <div class="fb-segmented" role="group" aria-label="{{ __('feedback-board::feedback-board.reports.filter_label') }}">
        @foreach (\YousefAman\FeedbackBoard\Livewire\MyReportsBoard::FILTERS as $option)
            <button type="button" @class(['fb-segmented__btn', 'fb-tint' => $filter === $option]) aria-pressed="{{ $filter === $option ? 'true' : 'false' }}" wire:click="filterBy('{{ $option }}')">
                {{ __('feedback-board::feedback-board.reports.filter.'.$option) }}
                <span class="fb-count">{{ $counts[$option] }}</span>
            </button>
        @endforeach
    </div>

    <div class="fb-rows">
        <div class="fb-rows__head" aria-hidden="true">
            <span>{{ __('feedback-board::feedback-board.reports.columns.report') }}</span>
            <span>{{ __('feedback-board::feedback-board.reports.columns.status') }}</span>
            <span>{{ __('feedback-board::feedback-board.reports.columns.updated') }}</span>
            <span>{{ __('feedback-board::feedback-board.reports.columns.replies') }}</span>
        </div>
        @forelse ($items as $item)
            <button type="button" class="fb-row" wire:key="row-{{ $item->id }}" wire:click="open({{ $item->id }})">
                <span class="fb-row__main">
                    <x-feedback-board::type-icon :type="$item->type" />
                    <span class="fb-row__text">
                        <span class="fb-row__title" dir="auto">{{ $item->title }}</span>
                        <span class="fb-row__ref">#{{ $item->id }} · {{ $item->type->label() }}</span>
                    </span>
                </span>
                <span class="fb-badge fb-badge--{{ $item->status->color() }}">{{ $item->status->label() }}</span>
                <time class="fb-row__time" datetime="{{ $item->reporterActivity()?->toIso8601String() }}">{{ $item->reporterActivity()?->diffForHumans() }}</time>
                <span @class(['fb-row__replies', 'fb-row__replies--new' => $item->unread_count > 0])>
                    <x-feedback-board::icon name="chat-bubble-left-right" />
                    <span class="fb-row__count">{{ $item->replies_count }}</span>
                    @if ($item->unread_count > 0)
                        <span class="fb-unread" aria-hidden="true"></span>
                        <span class="fb-sr-only">{{ __('feedback-board::feedback-board.reports.new_reply') }}</span>
                    @endif
                </span>
            </button>
        @empty
            <p class="fb-empty">{{ $emptyText }}</p>
        @endforelse
    </div>

    <x-feedback-board::slide-over :id="$uid.'-form'" :labelledby="$uid.'-form-title'" show="formOpen" close="formOpen = false">
        @include('feedback-board::partials.report-form-sheet', ['titleId' => $uid.'-form-title'])
    </x-feedback-board::slide-over>

    @if ($selected)
        <x-feedback-board::slide-over :id="$uid.'-report'" :labelledby="$uid.'-report-title'" wide show="true" close="$wire.close()">
            @livewire('feedback-board::feedback-item-modal', ['item' => $selected, 'readBefore' => $readBefore, 'titleId' => $uid.'-report-title'], key('report-'.$selected->id))
        </x-feedback-board::slide-over>
    @endif
</div>
```

Replace `resources/views/livewire/feedback-item-modal.blade.php` with:

```blade
<div class="fb-scope fb-item fb-sheet" data-fb-theme="{{ \YousefAman\FeedbackBoard\Support\Ui::theme() }}" dir="{{ __('feedback-board::feedback-board.dir') }}"
     x-data="{ lightbox: null, zoom(el) { this.lightbox = { src: el.dataset.fbSrc, name: el.dataset.fbName } }, closeLightbox() { this.lightbox = null } }">
    @include('feedback-board::partials.assets')
    <div class="fb-slide-over__head">
        <x-feedback-board::type-icon :type="$item->type" />
        <div class="fb-slide-over__titles">
            <h2 id="{{ $titleId }}" class="fb-slide-over__title" dir="auto">{{ $item->title }}</h2>
            <p class="fb-slide-over__sub">
                <span>#{{ $item->id }} · {{ $item->type->label() }}</span>
                <span class="fb-badge fb-badge--{{ $item->status->color() }}">{{ $item->status->label() }}</span>
            </p>
        </div>
        <button type="button" class="fb-icon-btn" x-on:click="$dispatch('fb-overlay-close')" aria-label="{{ __('feedback-board::feedback-board.close') }}">
            <x-feedback-board::icon name="x-mark" />
        </button>
    </div>

    <div class="fb-sheet__body">
        @if ($terminal)
            <p @class(['fb-notice', 'fb-banner', 'fb-notice--danger' => $item->status === \YousefAman\FeedbackBoard\Enums\FeedbackStatus::Declined])>
                <span>{{ __('feedback-board::feedback-board.item.'.$item->status->value.'_banner') }}</span>
                @if ($terminalAt)
                    <time datetime="{{ $terminalAt->toIso8601String() }}">{{ $terminalAt->diffForHumans() }}</time>
                @endif
            </p>
        @else
            <ol class="fb-steps" aria-label="{{ __('feedback-board::feedback-board.item.steps_label') }}">
                @foreach ($steps as $step)
                    <li @class(['fb-step', 'fb-step--done' => $step['done'], 'fb-step--current' => $step['current'] && ! $step['done']]) @if ($step['current']) aria-current="step" @endif>
                        <span class="fb-step__bar" aria-hidden="true"></span>{{ $step['status']->label() }}
                    </li>
                @endforeach
            </ol>
        @endif

        <div class="fb-meta">
            <span><x-feedback-board::icon name="clock" />{{ __('feedback-board::feedback-board.item.sent_at', ['time' => $item->created_at?->diffForHumans()]) }}</span>
            @if (is_string($item->meta['url'] ?? null) && trim($item->meta['url']) !== '')
                <span><x-feedback-board::icon name="link" /><span class="fb-meta__url" dir="ltr">{{ $item->meta['url'] }}</span></span>
            @endif
        </div>

        <section class="fb-section">
            <h3 class="fb-section-title">{{ __('feedback-board::feedback-board.item.description') }}</h3>
            <p class="fb-prose" dir="auto">{{ filled($item->description) ? $item->description : __('feedback-board::feedback-board.item.no_description') }}</p>
            @if ($images->isNotEmpty())
                <div class="fb-thumbs">
                    @foreach ($images as $image)
                        <x-feedback-board::thumb :attachment="$image" wire:key="image-{{ $image->id }}" />
                    @endforeach
                </div>
            @endif
            @if ($documents->isNotEmpty())
                <ul class="fb-doc-links">
                    @foreach ($documents as $document)
                        <li><a class="fb-doc-link" href="{{ $document->url() }}"><x-feedback-board::icon name="paper-clip" /><span dir="auto">{{ $document->original_name }}</span></a></li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="fb-section">
            <h3 class="fb-section-title">{{ __('feedback-board::feedback-board.item.conversation') }}</h3>
            @if ($comments->isEmpty())
                <p class="fb-waiting"><x-feedback-board::icon name="clock" />{{ __('feedback-board::feedback-board.item.waiting') }}</p>
            @else
                <div class="fb-chat">
                    @foreach ($comments as $comment)
                        @php($mine = $isMine($comment))
                        @php($commentImages = $comment->attachments->filter(fn ($attachment) => $attachment->isImage()))
                        <article wire:key="c-{{ $comment->id }}" @class(['fb-msg', 'fb-msg--mine' => $mine, 'fb-msg--theirs' => ! $mine])>
                            <p class="fb-msg__who">
                                <span class="fb-msg__author">{{ $mine ? __('feedback-board::feedback-board.item.you') : $comment->author_role->label() }}</span>
                                @if ($comment->created_at)
                                    <time datetime="{{ $comment->created_at->toIso8601String() }}">{{ $comment->created_at->diffForHumans() }}</time>
                                @endif
                                @if ($isNew($comment))
                                    <span class="fb-new">{{ __('feedback-board::feedback-board.item.new') }}</span>
                                @endif
                            </p>
                            <div @class(['fb-msg__bubble', 'fb-tint' => ! $mine])>
                                @if (filled($comment->body))
                                    <p class="fb-prose" dir="auto">{{ $comment->body }}</p>
                                @endif
                                @if ($commentImages->isNotEmpty())
                                    <div class="fb-thumbs">
                                        @foreach ($commentImages as $image)
                                            <x-feedback-board::thumb :attachment="$image" />
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    </div>

    <form class="fb-sheet__foot fb-reply" wire:submit="reply"
          x-data="{ @include('feedback-board::partials.upload-queue') }"
          @if ($canAttach) x-on:paste="pasted($event)" @endif>
        @include('feedback-board::partials.pending-images')
        <div class="fb-reply__row">
            @if ($canAttach)
                <label class="fb-icon-btn fb-reply__attach">
                    <input type="file" class="fb-file-input" accept="image/*" multiple x-on:change="picked($event)">
                    <x-feedback-board::icon name="paper-clip" />
                    <span class="fb-sr-only">{{ __('feedback-board::feedback-board.composer.attach_image') }}</span>
                </label>
            @endif
            <label class="fb-sr-only" for="{{ $uid }}-body">{{ __('feedback-board::feedback-board.composer.reply_label') }}</label>
            <textarea id="{{ $uid }}-body" class="fb-textarea fb-reply__input" wire:model="body" rows="1" dir="auto"
                      placeholder="{{ __('feedback-board::feedback-board.composer.placeholder_reporter') }}"
                      x-on:input="$el.style.height = 'auto'; $el.style.height = $el.scrollHeight + 'px'"></textarea>
            <button type="submit" class="fb-btn fb-btn--primary" x-bind:disabled="uploading > 0" wire:loading.attr="disabled" wire:target="reply">
                <x-feedback-board::icon name="paper-airplane" />{{ __('feedback-board::feedback-board.composer.send') }}
            </button>
        </div>
        <p class="fb-hint" x-show="uploading > 0" x-cloak>{{ __('feedback-board::feedback-board.form.uploading') }} <span x-text="progress + '%'"></span></p>
        @foreach (['body', 'upload', 'files', 'files.*'] as $field)
            @error($field) <span class="fb-error">{{ $message }}</span> @enderror
        @endforeach
        @if ($imagesFailed)
            <p class="fb-error">{{ __('feedback-board::feedback-board.composer.images_failed') }}</p>
        @endif
    </form>

    @include('feedback-board::partials.lightbox')
</div>
```

- [ ] **Step 6: Stylesheet**

In `resources/dist/feedback-board.css`:

a) In the "Containers and text" section, delete `.fb-panel__title`.

b) Replace the whole "My reports" section (`.fb-reports` through `.fb-list__time`, including the `@media (min-width: 64rem)` layout block) with the block below. Keep the `.fb-reports:not([data-fb-theme="class"])` rule and its comment exactly as they are, right after `.fb-reports`.

```css
/* ---------- My reports ---------- */

.fb-reports {
    display: grid;
    gap: 1.25rem;
}

/* Under a forced or system theme the package surface can differ from the host page, so the
   outermost surfaces paint their own background. */
.fb-reports:not([data-fb-theme="class"]) {
    padding: 1rem;
    border-radius: var(--_fb-radius);
    background: var(--_fb-bg);
}

.fb-head {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    justify-content: space-between;
    gap: 0.875rem;
}

.fb-head__titles {
    display: grid;
    gap: 0.125rem;
    min-inline-size: 0;
}

.fb-head__title {
    font-size: 1.5rem;
    font-weight: 700;
    line-height: 1.3;
}

.fb-head__sub {
    color: var(--_fb-text-muted);
    font-size: 0.875rem;
}

.fb-segmented {
    display: inline-flex;
    flex-wrap: wrap;
    justify-self: start;
    gap: 0.25rem;
    padding: 0.25rem;
    border: 1px solid var(--_fb-border);
    border-radius: 0.625rem;
    background: var(--_fb-surface);
}

.fb-segmented__btn {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.375rem 0.75rem;
    border: 1px solid transparent;
    border-radius: 0.4375rem;
    background: transparent;
    color: var(--_fb-text-muted);
    font-size: 0.8125rem;
    font-weight: 600;
    cursor: pointer;
}

.fb-segmented__btn:hover {
    color: var(--_fb-text);
}

.fb-segmented__btn[aria-pressed="true"] {
    border-color: var(--_fb-accent);
    color: var(--_fb-text);
}

.fb-segmented__btn .fb-icon {
    inline-size: 0.875rem;
    block-size: 0.875rem;
}

.fb-count {
    padding: 0 0.375rem;
    border-radius: 0.375rem;
    background: var(--_fb-gray-bg);
    color: var(--_fb-gray-fg);
    font-size: 0.6875rem;
    font-variant-numeric: tabular-nums;
}

.fb-rows {
    overflow: hidden;
    border: 1px solid var(--_fb-border);
    border-radius: var(--_fb-radius);
    background: var(--_fb-surface);
    box-shadow: var(--_fb-shadow);
}

.fb-rows__head,
.fb-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 8.125rem 7.5rem 5.625rem;
    align-items: center;
    gap: 1rem;
    padding: 0.75rem 1.25rem;
}

.fb-rows__head {
    border-block-end: 1px solid var(--_fb-border);
    background: var(--_fb-bg);
    color: var(--_fb-text-muted);
    font-size: 0.75rem;
    font-weight: 600;
}

.fb-row {
    inline-size: 100%;
    border: 0;
    border-block-end: 1px solid var(--_fb-border);
    background: transparent;
    color: var(--_fb-text);
    text-align: start;
    cursor: pointer;
}

.fb-row:last-child {
    border-block-end: 0;
}

.fb-row:hover {
    background: var(--_fb-surface-muted);
}

.fb-row__main {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    min-inline-size: 0;
}

.fb-row__text {
    display: grid;
    min-inline-size: 0;
}

.fb-row__title {
    overflow: hidden;
    font-weight: 600;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.fb-row__ref {
    color: var(--_fb-text-muted);
    font-size: 0.75rem;
    font-variant-numeric: tabular-nums;
}

.fb-row .fb-badge {
    justify-self: start;
}

.fb-row__time {
    color: var(--_fb-text-muted);
    font-size: 0.8125rem;
}

.fb-row__replies {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    color: var(--_fb-text-muted);
    font-size: 0.8125rem;
}

.fb-row__replies .fb-icon {
    inline-size: 1.0625rem;
    block-size: 1.0625rem;
}

.fb-row__replies--new {
    color: var(--_fb-text);
    font-weight: 700;
}

.fb-row__count {
    font-variant-numeric: tabular-nums;
}

.fb-unread {
    inline-size: 0.5rem;
    block-size: 0.5rem;
    border-radius: 999px;
    background: var(--_fb-accent);
}

.fb-rows .fb-empty {
    margin: 1rem;
}

@media (max-width: 57.5rem) {
    .fb-rows__head {
        display: none;
    }

    .fb-row {
        grid-template-columns: minmax(0, 1fr) auto;
        grid-template-areas: "main status" "main replies";
        row-gap: 0.25rem;
    }

    .fb-row__main {
        grid-area: main;
    }

    .fb-row .fb-badge {
        grid-area: status;
        justify-self: end;
    }

    .fb-row__time {
        display: none;
    }

    .fb-row__replies {
        grid-area: replies;
        justify-self: end;
    }
}

/* ---------- The reporter's report: steps, conversation, reply ---------- */

.fb-item {
    background: var(--_fb-surface);
}

.fb-steps {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0.375rem;
}

.fb-step {
    display: grid;
    gap: 0.375rem;
    color: var(--_fb-text-muted);
    font-size: 0.75rem;
    font-weight: 500;
}

.fb-step__bar {
    block-size: 0.25rem;
    border-radius: 999px;
    background: var(--_fb-border);
}

.fb-step--done {
    color: var(--_fb-text);
}

.fb-step--done .fb-step__bar {
    background: var(--_fb-accent);
}

.fb-step--current {
    color: var(--_fb-text);
    font-weight: 700;
}

.fb-step--current .fb-step__bar {
    background: var(--_fb-accent);
    opacity: 0.55;
}

.fb-banner {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
    font-weight: 600;
}

.fb-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 0.375rem 1rem;
    color: var(--_fb-text-muted);
    font-size: 0.75rem;
}

.fb-meta > span {
    display: inline-flex;
    align-items: center;
    gap: 0.3125rem;
    min-inline-size: 0;
}

.fb-meta .fb-icon {
    inline-size: 0.9375rem;
    block-size: 0.9375rem;
}

.fb-meta__url {
    font-family: var(--_fb-mono);
    overflow-wrap: anywhere;
    unicode-bidi: isolate;
}

.fb-doc-links {
    display: grid;
    gap: 0.375rem;
}

.fb-doc-link {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    color: var(--_fb-link);
    text-decoration: none;
    overflow-wrap: anywhere;
}

.fb-doc-link:hover {
    text-decoration: underline;
}

.fb-doc-link .fb-icon {
    inline-size: 1rem;
    block-size: 1rem;
}

.fb-chat {
    display: grid;
    gap: 0.75rem;
}

.fb-msg {
    display: grid;
    gap: 0.25rem;
    max-inline-size: 88%;
}

.fb-msg--mine {
    justify-self: start;
}

.fb-msg--theirs {
    justify-self: end;
}

.fb-msg__who {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.375rem;
    color: var(--_fb-text-muted);
    font-size: 0.75rem;
}

.fb-msg--theirs .fb-msg__who {
    justify-content: flex-end;
}

.fb-msg__author {
    color: var(--_fb-text);
    font-weight: 600;
}

.fb-msg__bubble {
    display: grid;
    gap: 0.5rem;
    padding: 0.625rem 0.8125rem;
    border-radius: 0.875rem;
    font-size: 0.875rem;
    line-height: 1.7;
}

.fb-msg--mine .fb-msg__bubble {
    border-start-start-radius: 0.25rem;
    background: var(--_fb-surface-muted);
}

.fb-msg--theirs .fb-msg__bubble {
    border: 1px solid var(--_fb-accent);
    border-start-end-radius: 0.25rem;
}

.fb-new {
    padding: 0 0.375rem;
    border-radius: 0.3125rem;
    background: var(--_fb-accent);
    color: var(--_fb-accent-contrast);
    font-size: 0.6875rem;
    font-weight: 700;
}

.fb-waiting {
    display: flex;
    align-items: center;
    gap: 0.625rem;
    padding: 0.875rem;
    border: 1px solid var(--_fb-border);
    border-radius: 0.625rem;
    background: var(--_fb-bg);
    color: var(--_fb-text-muted);
    font-size: 0.8125rem;
}

.fb-reply {
    display: grid;
    gap: 0.5rem;
}

.fb-reply__row {
    display: flex;
    align-items: flex-end;
    gap: 0.5rem;
}

.fb-reply__attach {
    position: relative;
    flex: none;
}

.fb-reply__attach:focus-within {
    outline: 2px solid var(--_fb-ring);
    outline-offset: 2px;
}

.fb-reply__input {
    flex: 1;
    min-height: 2.75rem;
    max-block-size: 10rem;
    resize: none;
}

.fb-pending {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.fb-pending__item {
    position: relative;
    display: grid;
    place-items: center;
    inline-size: 3.25rem;
    block-size: 3.25rem;
    overflow: hidden;
    border-radius: 0.5rem;
    background: var(--_fb-surface-muted);
    box-shadow: 0 0 0 1px var(--_fb-border);
    color: var(--_fb-text-muted);
}

.fb-pending__item img {
    inline-size: 100%;
    block-size: 100%;
    object-fit: cover;
    object-position: top;
}

.fb-pending__remove {
    position: absolute;
    inset-block-start: 0.125rem;
    inset-inline-start: 0.125rem;
    display: grid;
    place-items: center;
    inline-size: 1.25rem;
    block-size: 1.25rem;
    padding: 0;
    border: 0;
    border-radius: 999px;
    background: var(--_fb-surface);
    color: var(--_fb-text);
    box-shadow: 0 1px 3px rgb(0 0 0 / 0.25);
    cursor: pointer;
}

.fb-pending__remove .fb-icon {
    inline-size: 0.75rem;
    block-size: 0.75rem;
}
```

c) In the "Item header, thread, composer" section, change the selector `.fb-item,\n.fb-detail` to `.fb-detail` (the developer detail keeps its grid until Task 9).

- [ ] **Step 7: Translations**

In `resources/lang/en/feedback-board.php`, add before the final `];`:

```php
    'reports' => [
        'subheading' => "Follow your reports and the support team's replies",
        'filter_label' => 'Filter reports',
        'filter' => ['all' => 'All', 'open' => 'Open', 'done' => 'Done'],
        'columns' => ['report' => 'Report', 'status' => 'Status', 'updated' => 'Last update', 'replies' => 'Replies'],
        'new_reply' => 'New reply',
        'empty_open' => 'No open reports.',
        'empty_done' => 'No finished reports yet.',
    ],
    'item' => [
        'steps_label' => 'Report progress',
        'closed_banner' => 'This report was closed',
        'declined_banner' => 'This report was declined',
        'sent_at' => 'Sent :time',
        'description' => 'Description',
        'no_description' => 'No description.',
        'conversation' => 'Conversation',
        'you' => 'You',
        'new' => 'New',
        'waiting' => "No reply yet. We'll notify you when someone replies.",
    ],
    'composer' => [
        'reply_label' => 'Your reply',
        'placeholder_reporter' => 'Write your reply to the support team, or paste an image…',
        'attach_image' => 'Attach an image',
        'remove_image' => 'Remove the image',
        'send' => 'Send',
        'empty' => 'Write a reply or attach an image.',
        'images_failed' => 'Some images could not be saved.',
    ],
```

In `resources/lang/ar/feedback-board.php`, add before the final `];`:

```php
    'reports' => [
        'subheading' => 'تابع بلاغاتك وردود فريق الدعم الفني',
        'filter_label' => 'تصفية البلاغات',
        'filter' => ['all' => 'الكل', 'open' => 'مفتوحة', 'done' => 'منتهية'],
        'columns' => ['report' => 'البلاغ', 'status' => 'الحالة', 'updated' => 'آخر تحديث', 'replies' => 'الردود'],
        'new_reply' => 'رد جديد',
        'empty_open' => 'لا توجد بلاغات مفتوحة.',
        'empty_done' => 'لا توجد بلاغات منتهية بعد.',
    ],
    'item' => [
        'steps_label' => 'مراحل البلاغ',
        'closed_banner' => 'أُغلق هذا البلاغ',
        'declined_banner' => 'رُفض هذا البلاغ',
        'sent_at' => 'أُرسل :time',
        'description' => 'الوصف',
        'no_description' => 'بلا وصف.',
        'conversation' => 'المحادثة',
        'you' => 'أنت',
        'new' => 'جديد',
        'waiting' => 'لم يصل رد بعد. سنرسل لك إشعارًا عند الرد.',
    ],
    'composer' => [
        'reply_label' => 'ردّك',
        'placeholder_reporter' => 'اكتب ردّك لفريق الدعم، أو الصق صورة…',
        'attach_image' => 'إرفاق صورة',
        'remove_image' => 'إزالة الصورة',
        'send' => 'إرسال',
        'empty' => 'اكتب ردًا أو أرفق صورة.',
        'images_failed' => 'تعذّر حفظ بعض الصور.',
    ],
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/MyReportsTest.php tests/Feature/StandalonePagesTest.php tests/Unit/StylesheetTest.php tests/Unit/StylesheetCoverageTest.php tests/Feature/LocalizationTest.php`
Expected: PASS.

Run: `php -l` on the changed PHP files, then `vendor/bin/pest`.
Expected: no failures.

- [ ] **Step 9: Commit**

```bash
git add src/Livewire resources/views resources/dist/feedback-board.css resources/lang tests/Feature/MyReportsTest.php tests/Feature/StandalonePagesTest.php tests/Unit/StylesheetCoverageTest.php
git commit -m "feat(reports): my reports with filters, unread replies and deep links; reporter conversation with image replies"
```

---

### Task 8: Kanban — type filters, cover images, chips, counts and avatars in one query

**Files:**
- Modify: `src/Livewire/FeedbackKanban.php`, `resources/views/livewire/feedback-kanban.blade.php`
- Modify: `resources/dist/feedback-board.css`, both language files
- Test: `tests/Feature/KanbanTest.php` (replaced)

**Interfaces:**
- Consumes: `FeedbackItem::coverAttachment()`, `FeedbackAttachment::isImage()`/`url()` (Task 1); `Person::name()` (Task 2); components `icon`, `avatar` and the `fb-tint`/`fb-count` classes (Tasks 5 and 7); `FeedbackType::icon()` (Task 5).
- Produces:
  - `FeedbackKanban`: `#[Url(as: 'type')] public $type` (untyped; normalised to a `FeedbackType` value or `null`), `filterType(string $type): void` (`'all'` or anything unknown clears it), and the listener `#[On('feedback-updated')] refreshBoard()`. Task 9's dialog dispatches `feedback-updated` after every write.
  - View data: `columns` (status value ⇒ list of items), `filters` (list of `{value, type, label, count, active}`), `counts` (`all` + every type value ⇒ int), `cards` (item id ⇒ `{cover: ?FeedbackAttachment, reporter: ?string, assignee: ?string}`), `selected`.
  - Item counts on every card: `replies_count` (public comments), `internal_count` (internal notes), `attachments_count` (all attachments, comments' included).
  - CSS: `fb-filters`, `fb-filter`, `fb-card__cover`, `fb-card__body`, `fb-card__chips`, `fb-card__prio`, `fb-card__type`, `fb-card__foot`, `fb-card__ref`, `fb-card__stat` (+ `--internal`), `fb-card__people`. Removed: `fb-card__meta`, `fb-card__time`.
  - The drawer around the detail stays until Task 9 turns it into the card dialog.

- [ ] **Step 1: Write the failing tests**

Replace `tests/Feature/KanbanTest.php` with:

```php
<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage};
use Livewire\Attributes\On;
use Livewire\Livewire;
use YousefAman\FeedbackBoard\Enums\{AuthorRole, FeedbackPriority, FeedbackStatus, FeedbackType};
use YousefAman\FeedbackBoard\Livewire\FeedbackKanban;
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};
use YousefAman\FeedbackBoard\Services\AttachmentStorage;
use YousefAman\FeedbackBoard\Support\Person;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    config()->set('feedback-board.authorization.developer_resolver', fn ($u) => $u->name === 'Dev');
    Storage::fake('local');
});

function kanbanDev(): TestUser
{
    return TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
}

it('forbids non-developers and lets a developer move a card', function () {
    $dev = kanbanDev();
    $client = TestUser::create(['name' => 'Client', 'email' => 'c@x.com']);
    $item = FeedbackItem::factory()->create(['status' => FeedbackStatus::New]);

    Livewire::actingAs($client)->test(FeedbackKanban::class)->call('moveTo', $item->id, 'in_progress')->assertForbidden();

    Livewire::actingAs($dev)->test(FeedbackKanban::class)->call('moveTo', $item->id, 'in_progress')->assertOk();
    expect($item->refresh()->status)->toBe(FeedbackStatus::InProgress);
});

it('opens a card detail panel from the board', function () {
    $item = FeedbackItem::factory()->create(['title' => 'Card X']);

    Livewire::actingAs(kanbanDev())->test(FeedbackKanban::class)
        ->call('open', $item->id)
        ->assertSet('selectedId', $item->id)
        ->assertSeeLivewire('feedback-board::feedback-detail');
});

it('closes the detail drawer', function () {
    $item = FeedbackItem::factory()->create(['title' => 'Card X']);

    Livewire::actingAs(kanbanDev())->test(FeedbackKanban::class)
        ->call('open', $item->id)
        ->assertSeeHtml('class="fb-drawer"')
        ->call('close')
        ->assertSet('selectedId', null)
        ->assertDontSeeHtml('class="fb-drawer"')
        ->assertDontSeeLivewire('feedback-board::feedback-detail');
});

it('renders columns with counts, empty placeholders and keyboard-operable cards', function () {
    FeedbackItem::factory()->create(['title' => 'Urgent card', 'status' => FeedbackStatus::New, 'priority' => FeedbackPriority::Urgent]);

    Livewire::actingAs(kanbanDev())->test(FeedbackKanban::class)
        ->assertSeeHtml('class="fb-scope fb-kanban"')
        ->assertSeeHtml('class="fb-col__count">1</span>')
        ->assertSeeHtml('<span class="fb-badge fb-badge--danger fb-card__prio">Urgent</span>')
        ->assertSeeHtml('class="fb-card__type"')
        ->assertSeeHtml('fb-dot fb-dot--gray')
        ->assertSeeHtml('role="button" tabindex="0"')
        ->assertSeeHtml("\$event.dataTransfer.setData('id'")
        ->assertSeeHtml('$event.dataTransfer.getData(\'id\') && $wire.moveTo($event.dataTransfer.getData(\'id\'), \'in_progress\')')
        ->assertSeeHtml('$event.dataTransfer.types.includes(\'id\') && over++')
        ->assertSeeHtml('x-bind:class="{ \'fb-col--over\': over > 0 }"')
        ->assertSeeHtml('x-bind:class="{ \'fb-card--dragging\': dragging }"')
        ->assertSeeHtml('x-on:keydown.space.prevent=')
        ->assertSee(__('feedback-board::feedback-board.board.empty_column'));
});

it('covers a card with its first own image, lazily, and never with a comment image or a non-image', function () {
    $storage = app(AttachmentStorage::class);

    $shots = FeedbackItem::factory()->create(['title' => 'With shots']);
    $first = $storage->store(UploadedFile::fake()->image('first.png'), $shots);
    $storage->store(UploadedFile::fake()->image('second.jpg'), $shots);

    $commented = FeedbackItem::factory()->create(['title' => 'Comment image only']);
    $storage->store(UploadedFile::fake()->image('reply.png'), $commented, FeedbackComment::factory()->for($commented, 'item')->create());

    // Rows written directly: the configured allow-list would refuse these uploads.
    $vector = FeedbackItem::factory()->create(['title' => 'SVG only']);
    $vector->attachments()->create(['disk' => 'local', 'path' => 'x/logo.svg', 'original_name' => 'logo.svg', 'mime' => 'image/svg+xml', 'size' => 10]);
    $mismatch = FeedbackItem::factory()->create(['title' => 'PNG type, text name']);
    $mismatch->attachments()->create(['disk' => 'local', 'path' => 'x/notes.txt', 'original_name' => 'notes.txt', 'mime' => 'image/png', 'size' => 10]);

    $html = Livewire::actingAs(kanbanDev())->test(FeedbackKanban::class)->html();

    expect(substr_count($html, 'class="fb-card__cover"'))->toBe(1)
        ->and($html)->toContain('<img class="fb-card__cover" src="'.$first->url().'" alt="" loading="lazy" decoding="async" draggable="false"')
        ->and($html)->toContain('x-on:error="$el.remove()"');
});

it('shows public comments, attachments and internal notes on a card, with the reporter and assignee', function () {
    $dev = kanbanDev();
    $storage = app(AttachmentStorage::class);
    $item = FeedbackItem::factory()->create([
        'reporter_name' => 'Lena Client', 'assignee_type' => $dev->getMorphClass(), 'assignee_id' => $dev->id,
    ]);
    $reply = FeedbackComment::factory()->for($item, 'item')->create(['author_role' => AuthorRole::Developer]);
    FeedbackComment::factory()->for($item, 'item')->create();
    $note = FeedbackComment::factory()->for($item, 'item')->create(['author_role' => AuthorRole::Developer, 'is_internal' => true]);
    $storage->store(UploadedFile::fake()->image('a.png'), $item);
    $storage->store(UploadedFile::fake()->image('b.png'), $item, $reply);
    $storage->store(UploadedFile::fake()->image('c.png'), $item, $note);
    FeedbackItem::factory()->create(['title' => 'Quiet card']);

    Livewire::actingAs($dev)->test(FeedbackKanban::class)
        ->assertSeeHtml('<span class="fb-card__ref">#'.$item->id.'</span>')
        ->assertSeeHtml('<span class="fb-sr-only">Comments: 2</span>')
        ->assertSeeHtml('<span class="fb-sr-only">Attachments: 3</span>')
        ->assertSeeHtml('class="fb-card__stat fb-card__stat--internal"')
        ->assertSeeHtml('<span class="fb-sr-only">Internal notes: 1</span>')
        ->assertDontSeeHtml('Comments: 0')
        ->assertSeeHtml('class="fb-avatar fb-avatar--'.Person::hue('Lena Client').'" aria-hidden="true">L</span>')
        ->assertSeeHtml('<span class="fb-sr-only">Reporter: Lena Client</span>')
        ->assertSeeHtml('class="fb-avatar fb-avatar--'.Person::hue('Dev').'" aria-hidden="true">D</span>')
        ->assertSeeHtml('<span class="fb-sr-only">Assignee: Dev</span>');
});

it('filters the board by type, with a count per type', function () {
    FeedbackItem::factory()->create(['title' => 'Broken login', 'type' => FeedbackType::Bug]);
    FeedbackItem::factory()->create(['title' => 'Broken export', 'type' => FeedbackType::Bug]);
    FeedbackItem::factory()->create(['title' => 'Dark mode please', 'type' => FeedbackType::Feature]);

    $component = Livewire::actingAs(kanbanDev())->test(FeedbackKanban::class)
        ->assertSeeHtml('aria-pressed="true" wire:click="filterType(\'all\')"');

    expect($component->viewData('counts'))->toBe(['all' => 3, 'bug' => 2, 'feature' => 1, 'question' => 0, 'other' => 0]);

    $component->call('filterType', 'feature')
        ->assertSet('type', 'feature')
        ->assertSee('Dark mode please')
        ->assertDontSee('Broken login')
        ->assertSeeHtml('class="fb-filter fb-tint" aria-pressed="true" wire:click="filterType(\'feature\')"')
        ->assertSeeHtml('class="fb-col__count">1</span>');

    $component->call('filterType', 'all')->assertSet('type', null)->assertSee('Broken login');
    $component->call('filterType', 'nonsense')->assertSet('type', null)->assertSee('Dark mode please');
});

it('reads the type filter from ?type= and ignores anything that is not a type', function (mixed $type, bool $filtered) {
    FeedbackItem::factory()->create(['title' => 'Broken login', 'type' => FeedbackType::Bug]);
    FeedbackItem::factory()->create(['title' => 'Dark mode please', 'type' => FeedbackType::Feature]);

    $component = Livewire::withQueryParams(['type' => $type])->actingAs(kanbanDev())->test(FeedbackKanban::class)
        ->assertOk()
        ->assertSee('Dark mode please');

    $filtered ? $component->assertDontSee('Broken login') : $component->assertSee('Broken login');
})->with([
    'a type' => ['feature', true],
    'a number' => ['5', false],
    'an integer' => [5, false],
    'a boolean' => ['true', false],
    'an array' => [['feature'], false],
    'junk' => ['nope', false],
]);

it('loads the board with the same number of queries for 2 cards and for 10', function () {
    $dev = kanbanDev();

    $seed = function (int $cards) use ($dev): void {
        FeedbackItem::factory()->count($cards)
            ->create(['assignee_type' => $dev->getMorphClass(), 'assignee_id' => $dev->id])
            ->each(function (FeedbackItem $item): void {
                app(AttachmentStorage::class)->store(UploadedFile::fake()->image('shot.png'), $item);
                FeedbackComment::factory()->for($item, 'item')->create();
                FeedbackComment::factory()->for($item, 'item')->create(['is_internal' => true]);
            });
    };

    $queries = function () use ($dev): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($dev)->test(FeedbackKanban::class)->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $seed(2);
    $two = $queries();
    $seed(8);

    expect($queries())->toBe($two);
});

it('redraws when the card dialog reports a change', function () {
    $item = FeedbackItem::factory()->create(['status' => FeedbackStatus::New]);
    $component = Livewire::actingAs(kanbanDev())->test(FeedbackKanban::class);

    $item->update(['status' => FeedbackStatus::InProgress]);
    $component->dispatch('feedback-updated');

    expect($component->viewData('columns')['in_progress']->pluck('id')->all())->toBe([$item->id])
        ->and($component->viewData('columns')['new'])->toBeEmpty();

    // A Livewire test re-renders on any dispatch; this pins the browser wiring, where only a
    // registered listener makes the board ask the server again.
    $listener = (new ReflectionMethod(FeedbackKanban::class, 'refreshBoard'))->getAttributes(On::class)[0];
    expect($listener->getArguments())->toBe(['feedback-updated']);
});

it('keeps an English title auto-directed on an Arabic board', function () {
    app()->setLocale('ar');
    FeedbackItem::factory()->create(['title' => 'Export to CSV fails']);

    Livewire::actingAs(kanbanDev())->test(FeedbackKanban::class)
        ->assertSeeHtml('dir="rtl"')
        ->assertSeeHtml('<p class="fb-card__title" dir="auto">Export to CSV fails</p>')
        ->assertSeeHtml('aria-label="تصفية حسب النوع"');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/KanbanTest.php`
Expected: FAIL — no `fb-card__prio`, no `type` property or `filterType()`, no covers, no `refreshBoard()`, and six queries per board (one per column).

- [ ] **Step 3: The component**

Replace `src/Livewire/FeedbackKanban.php` with:

```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\{On, Url};
use Livewire\Component;
use YousefAman\FeedbackBoard\Actions\TransitionStatus;
use YousefAman\FeedbackBoard\Enums\{FeedbackStatus, FeedbackType};
use YousefAman\FeedbackBoard\Models\{FeedbackAttachment, FeedbackItem};
use YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy;
use YousefAman\FeedbackBoard\Support\Person;

class FeedbackKanban extends Component
{
    public ?int $selectedId = null;

    /**
     * The type filter (?type=). Untyped on purpose: Livewire 3 assigns query values without a
     * type guard, and #[Url] JSON-decodes "5" to an integer and "true" to a boolean; under
     * strict_types FeedbackType::tryFrom() would throw for those, so typeFilter() guards it.
     */
    #[Url(as: 'type')]
    public $type = null;

    // Page access is enforced by the route's `can:viewAny` middleware (Step 4); each
    // write re-authorizes below. No mount() guard — that keeps the component mountable
    // in tests (Livewire re-throws auth exceptions raw on initial render) while the
    // real gate lives on the route + the per-action authorize('transition').

    public function mount(): void
    {
        // #[Url] has already assigned the query value.
        $this->type = self::typeFilter($this->type)?->value;
    }

    public function open(int $id): void { $this->selectedId = $id; }

    public function close(): void { $this->selectedId = null; }

    /** "all", or anything that is not a type, clears the filter. */
    public function filterType(string $type): void
    {
        $this->type = FeedbackType::tryFrom($type)?->value;
    }

    /** The card dialog changed a report: redraw its column, counts and cover. */
    #[On('feedback-updated')]
    public function refreshBoard(): void
    {
    }

    public function moveTo(int $itemId, string $status, TransitionStatus $transition): void
    {
        $item = FeedbackItem::findOrFail($itemId);
        $this->authorize('transition', $item);
        $s = FeedbackStatus::tryFrom($status) ?? abort(422);
        $transition->handle($item, $s, auth()->user());
    }

    public function render(): View
    {
        // Defense-in-depth: if a non-developer somehow mounts this component outside the
        // gated route, return empty columns so item data does not leak. The per-action
        // authorize('transition') in moveTo() remains the primary write guard.
        if (! FeedbackItemPolicy::isDeveloper(auth()->user())) {
            return view('feedback-board::livewire.feedback-kanban', [
                'columns' => collect(), 'filters' => [], 'counts' => [], 'cards' => [], 'selected' => null,
            ]);
        }

        $type = self::typeFilter($this->type);

        // One query for every card, with its counts; the cover and the assignee are eager-loaded.
        // Nothing runs per card.
        $items = FeedbackItem::query()
            ->with(['coverAttachment', 'assignee'])
            ->withCount([
                'comments as replies_count' => fn (Builder $comments) => $comments->where('is_internal', false),
                'comments as internal_count' => fn (Builder $comments) => $comments->where('is_internal', true),
                'attachments',
            ])
            ->latest()
            ->orderByDesc('id')
            ->get();

        $counts = ['all' => $items->count()];

        foreach (FeedbackType::cases() as $case) {
            $counts[$case->value] = $items->filter(fn (FeedbackItem $item) => $item->type === $case)->count();
        }

        $shown = $type === null ? $items : $items->filter(fn (FeedbackItem $item) => $item->type === $type)->values();

        return view('feedback-board::livewire.feedback-kanban', [
            'columns' => collect(FeedbackStatus::cases())->mapWithKeys(fn (FeedbackStatus $status) => [
                $status->value => $shown->filter(fn (FeedbackItem $item) => $item->status === $status)->values(),
            ]),
            'filters' => array_map(fn (?FeedbackType $case) => [
                'value' => $case?->value ?? 'all',
                'type' => $case,
                'label' => $case?->label() ?? __('feedback-board::feedback-board.kanban.all'),
                'count' => $counts[$case?->value ?? 'all'],
                'active' => $case === $type,
            ], [null, ...FeedbackType::cases()]),
            'counts' => $counts,
            'cards' => $shown->mapWithKeys(fn (FeedbackItem $item) => [$item->id => $this->card($item)])->all(),
            'selected' => $this->selectedId ? FeedbackItem::find($this->selectedId) : null,
        ]);
    }

    /** @return array{cover: ?FeedbackAttachment, reporter: ?string, assignee: ?string} */
    private function card(FeedbackItem $item): array
    {
        $cover = $item->coverAttachment;

        return [
            // The relation filters by MIME type; isImage() also checks the extension.
            'cover' => $cover?->isImage() ? $cover : null,
            'reporter' => filled($item->reporter_name) ? $item->reporter_name : (filled($item->reporter_email) ? $item->reporter_email : null),
            'assignee' => $item->assignee_id !== null ? Person::name($item->assignee, $item->assignee_id) : null,
        ];
    }

    private static function typeFilter(mixed $value): ?FeedbackType
    {
        return is_string($value) ? FeedbackType::tryFrom($value) : null;
    }
}
```

- [ ] **Step 4: The view**

In `resources/views/livewire/feedback-kanban.blade.php`, replace everything from the root's `@include('feedback-board::partials.assets')` line through the closing `</div>` of `fb-kanban__columns` with the block below. The root element's opening tag and the `@if ($selected)` drawer block after the columns stay exactly as they are.

```blade
    @include('feedback-board::partials.assets')

    <div class="fb-filters" role="group" aria-label="{{ __('feedback-board::feedback-board.kanban.filter_label') }}">
        @foreach ($filters as $filter)
            <button type="button" @class(['fb-filter', 'fb-tint' => $filter['active']]) aria-pressed="{{ $filter['active'] ? 'true' : 'false' }}" wire:click="filterType('{{ $filter['value'] }}')">
                @if ($filter['type'])
                    <x-feedback-board::icon :name="$filter['type']->icon()" />
                @endif
                {{ $filter['label'] }}
                <span class="fb-count">{{ $filter['count'] }}</span>
            </button>
        @endforeach
    </div>

    <div class="fb-kanban__columns">
        @foreach ($columns as $status => $items)
            @php($columnStatus = \YousefAman\FeedbackBoard\Enums\FeedbackStatus::from($status))
            <section class="fb-col" data-status="{{ $status }}" wire:key="col-{{ $status }}"
                     x-data="{ over: 0 }"
                     x-on:dragenter="$event.dataTransfer.types.includes('id') && over++"
                     x-on:dragleave="$event.dataTransfer.types.includes('id') && (over = Math.max(0, over - 1))"
                     x-on:drop.prevent="over = 0; $event.dataTransfer.getData('id') && $wire.moveTo($event.dataTransfer.getData('id'), '{{ $status }}')"
                     x-bind:class="{ 'fb-col--over': over > 0 }">
                <header class="fb-col__header">
                    <span class="fb-dot fb-dot--{{ $columnStatus->color() }}" aria-hidden="true"></span>
                    <h3 class="fb-col__title">{{ $columnStatus->label() }}</h3>
                    <span class="fb-col__count">{{ count($items) }}</span>
                </header>
                <div class="fb-col__body">
                    @forelse ($items as $item)
                        @php($card = $cards[$item->id])
                        <div class="fb-card" wire:key="k-{{ $item->id }}"
                             role="button" tabindex="0"
                             wire:click="open({{ $item->id }})"
                             x-on:keydown.enter.prevent="$wire.open({{ $item->id }})"
                             x-on:keydown.space.prevent="$wire.open({{ $item->id }})"
                             draggable="true"
                             x-data="{ dragging: false }"
                             x-on:dragstart="dragging = true; $event.dataTransfer.effectAllowed = 'move'; $event.dataTransfer.setData('id', '{{ $item->id }}')"
                             x-on:dragend="dragging = false"
                             x-bind:class="{ 'fb-card--dragging': dragging }">
                            @if ($card['cover'])
                                {{-- Decorative (the title follows). A broken image is removed; x-init also catches one that failed before Alpine started. --}}
                                <img class="fb-card__cover" src="{{ $card['cover']->url() }}" alt="" loading="lazy" decoding="async" draggable="false"
                                     x-init="$el.complete && $el.naturalWidth === 0 && $el.remove()" x-on:error="$el.remove()">
                            @endif
                            <div class="fb-card__body">
                                <div class="fb-card__chips">
                                    @if ($item->priority)
                                        <span class="fb-badge fb-badge--{{ $item->priority->color() }} fb-card__prio">{{ $item->priority->label() }}</span>
                                    @endif
                                    <span class="fb-card__type"><x-feedback-board::icon :name="$item->type->icon()" />{{ $item->type->label() }}</span>
                                </div>
                                <p class="fb-card__title" dir="auto">{{ $item->title }}</p>
                                <div class="fb-card__foot">
                                    <span class="fb-card__ref">#{{ $item->id }}</span>
                                    @if ($item->replies_count > 0)
                                        <span class="fb-card__stat"><x-feedback-board::icon name="chat-bubble-oval-left-ellipsis" /><span aria-hidden="true">{{ $item->replies_count }}</span><span class="fb-sr-only">{{ __('feedback-board::feedback-board.kanban.comments', ['count' => $item->replies_count]) }}</span></span>
                                    @endif
                                    @if ($item->attachments_count > 0)
                                        <span class="fb-card__stat"><x-feedback-board::icon name="paper-clip" /><span aria-hidden="true">{{ $item->attachments_count }}</span><span class="fb-sr-only">{{ __('feedback-board::feedback-board.kanban.attachments', ['count' => $item->attachments_count]) }}</span></span>
                                    @endif
                                    @if ($item->internal_count > 0)
                                        <span class="fb-card__stat fb-card__stat--internal"><x-feedback-board::icon name="lock-closed" /><span aria-hidden="true">{{ $item->internal_count }}</span><span class="fb-sr-only">{{ __('feedback-board::feedback-board.kanban.internal', ['count' => $item->internal_count]) }}</span></span>
                                    @endif
                                    <span class="fb-card__people">
                                        @if ($card['reporter'] !== null)
                                            <x-feedback-board::avatar :name="$card['reporter']" />
                                            <span class="fb-sr-only">{{ __('feedback-board::feedback-board.kanban.reporter', ['name' => $card['reporter']]) }}</span>
                                        @endif
                                        @if ($card['assignee'] !== null)
                                            <x-feedback-board::avatar :name="$card['assignee']" />
                                            <span class="fb-sr-only">{{ __('feedback-board::feedback-board.kanban.assignee', ['name' => $card['assignee']]) }}</span>
                                        @endif
                                    </span>
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="fb-col__empty">{{ __('feedback-board::feedback-board.board.empty_column') }}</p>
                    @endforelse
                </div>
            </section>
        @endforeach
    </div>
```

- [ ] **Step 5: Stylesheet**

In `resources/dist/feedback-board.css`, replace the whole "Kanban" section (from `/* ---------- Kanban ---------- */` through the `.fb-card__time` rule) with:

```css
/* ---------- Kanban ---------- */

/* min-inline-size lets the column scroller shrink inside a flex or grid host instead of widening the page. */
.fb-kanban {
    display: grid;
    gap: 0.875rem;
    min-inline-size: 0;
}

.fb-kanban:not([data-fb-theme="class"]) {
    padding: 1rem;
    border-radius: var(--_fb-radius);
    background: var(--_fb-bg);
}

.fb-filters {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
}

.fb-filter {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.3125rem 0.75rem;
    border: 1px solid var(--_fb-border-strong);
    border-radius: 999px;
    background: var(--_fb-surface);
    color: var(--_fb-text-muted);
    font-size: 0.8125rem;
    font-weight: 600;
    cursor: pointer;
}

.fb-filter:hover {
    color: var(--_fb-text);
}

.fb-filter[aria-pressed="true"] {
    border-color: var(--_fb-accent);
    color: var(--_fb-text);
}

.fb-filter .fb-icon {
    inline-size: 1rem;
    block-size: 1rem;
}

.fb-kanban__columns {
    display: grid;
    grid-auto-flow: column;
    grid-auto-columns: 17rem;
    align-items: start;
    gap: 0.75rem;
    overflow-x: auto;
    padding-block-end: 0.625rem;
    scroll-snap-type: x proximity;
}

.fb-col {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    min-block-size: 12rem;
    padding: 0.75rem 0.5rem;
    border: 1px solid var(--_fb-border);
    border-radius: var(--_fb-radius);
    background: var(--_fb-bg);
    scroll-snap-align: start;
    transition: border-color 0.15s ease, background-color 0.15s ease;
}

.fb-col--over {
    border-color: var(--_fb-accent);
    border-style: dashed;
    background: var(--_fb-surface-muted);
}

.fb-col__header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding-inline: 0.375rem;
}

.fb-col__title {
    flex: 1;
    min-width: 0;
    font-size: 0.875rem;
    font-weight: 600;
}

.fb-col__count {
    min-inline-size: 1.5rem;
    padding: 0 0.375rem;
    border-radius: 999px;
    background: var(--_fb-surface-muted);
    color: var(--_fb-text-muted);
    font-size: 0.75rem;
    font-weight: 600;
    text-align: center;
}

.fb-col__body {
    display: grid;
    gap: 0.5rem;
}

.fb-col__empty {
    padding: 1rem;
    border: 1px dashed var(--_fb-border-strong);
    border-radius: var(--_fb-radius-sm);
    color: var(--_fb-text-muted);
    font-size: 0.8125rem;
    text-align: center;
}

.fb-card {
    display: block;
    overflow: hidden;
    border: 1px solid var(--_fb-border);
    border-radius: 0.625rem;
    background: var(--_fb-surface);
    box-shadow: var(--_fb-shadow);
    cursor: grab;
    transition: border-color 0.15s ease, box-shadow 0.15s ease, opacity 0.15s ease;
}

.fb-card:hover {
    border-color: var(--_fb-accent);
    box-shadow: 0 4px 12px -4px rgb(0 0 0 / 0.15);
}

.fb-card:active {
    cursor: grabbing;
}

.fb-card--dragging {
    opacity: 0.45;
}

.fb-card__cover {
    display: block;
    inline-size: 100%;
    aspect-ratio: 16 / 9;
    object-fit: cover;
    object-position: top;
    border-block-end: 1px solid var(--_fb-border);
    background: var(--_fb-surface-muted);
}

.fb-card__body {
    display: grid;
    gap: 0.5rem;
    padding: 0.625rem 0.75rem 0.6875rem;
}

.fb-card__chips {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.375rem;
}

.fb-card__prio {
    border-radius: 0.3125rem;
}

.fb-card__type {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    color: var(--_fb-text-muted);
    font-size: 0.75rem;
    font-weight: 600;
}

.fb-card__type .fb-icon {
    inline-size: 0.875rem;
    block-size: 0.875rem;
}

.fb-card__title {
    display: -webkit-box;
    overflow: hidden;
    font-size: 0.875rem;
    font-weight: 600;
    line-height: 1.5;
    overflow-wrap: anywhere;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 3;
}

.fb-card__foot {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.375rem 0.625rem;
    color: var(--_fb-text-muted);
    font-size: 0.75rem;
    font-variant-numeric: tabular-nums;
}

.fb-card__ref {
    font-weight: 600;
}

.fb-card__stat {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
}

.fb-card__stat .fb-icon {
    inline-size: 0.9375rem;
    block-size: 0.9375rem;
}

.fb-card__stat--internal {
    color: var(--_fb-warning-fg);
}

.fb-card__people {
    display: inline-flex;
    align-items: center;
    margin-inline-start: auto;
}

.fb-card__people .fb-avatar ~ .fb-avatar {
    margin-inline-start: -0.375rem;
}

@media (max-width: 35rem) {
    .fb-kanban__columns {
        grid-auto-columns: 15.5rem;
    }
}
```

- [ ] **Step 6: Translations**

In `resources/lang/en/feedback-board.php`, add before the final `];`:

```php
    'kanban' => [
        'filter_label' => 'Filter by type',
        'all' => 'All',
        'comments' => 'Comments: :count',
        'attachments' => 'Attachments: :count',
        'internal' => 'Internal notes: :count',
        'reporter' => 'Reporter: :name',
        'assignee' => 'Assignee: :name',
    ],
```

In `resources/lang/ar/feedback-board.php`, add before the final `];`:

```php
    'kanban' => [
        'filter_label' => 'تصفية حسب النوع',
        'all' => 'الكل',
        'comments' => 'التعليقات: :count',
        'attachments' => 'المرفقات: :count',
        'internal' => 'الملاحظات الداخلية: :count',
        'reporter' => 'المُبلِّغ: :name',
        'assignee' => 'المسؤول: :name',
    ],
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/KanbanTest.php tests/Unit/StylesheetTest.php tests/Unit/StylesheetCoverageTest.php tests/Feature/LocalizationTest.php tests/Feature/StandalonePagesTest.php`
Expected: PASS.

Run: `php -l src/Livewire/FeedbackKanban.php`, then `vendor/bin/pest`.
Expected: no failures.

- [ ] **Step 8: Commit**

```bash
git add src/Livewire/FeedbackKanban.php resources/views/livewire/feedback-kanban.blade.php resources/dist/feedback-board.css resources/lang tests/Feature/KanbanTest.php
git commit -m "feat(kanban): type filters, cover images, priority and type chips, counts and avatars in one query"
```

---

### Task 9: The card dialog — cover band, properties, gallery, composer with modes and images, merged feed

**Files:**
- Modify: `src/Livewire/FeedbackDetail.php` and rewrite `resources/views/livewire/feedback-detail.blade.php`
- Modify: `resources/views/livewire/feedback-kanban.blade.php` (the drawer becomes the dialog shell)
- Modify: `resources/dist/feedback-board.css`, both language files
- Test: `tests/Feature/FeedbackDetailTest.php` (replaced), `tests/Feature/KanbanTest.php`, `tests/Unit/StylesheetCoverageTest.php`

**Interfaces:**
- Consumes: Task 1 (`coverAttachment`, `isImage()`, `url()`), Task 2 (`SetPriority`, `FeedbackActivity::sentence()`/`assigneeNames()`, `Person::name()`), Task 4 (`imageMimes()`), Task 5 (components, lightbox, `fb-round-btn`, `fb-tint`), Task 6 (`WithStagedUploads`, the `upload-queue` partial), Task 7 (`composerRules()`, `composerMessages()`, the `pending-images` partial, `fb-segmented`, `fb-doc-links`, `fb-reply__attach`, the `item.*` and `composer.*` strings), Task 8 (the board's `feedback-updated` listener).
- Produces:
  - `FeedbackDetail`: `body` and staged `files` shared by `reply()` and `internalNote()`; `note` is removed. `#[Locked] bool $imagesFailed`, `#[Locked] bool $githubQueued`. Every write that changes a card (`reply`, `internalNote`, `assignToMe`, `unassign`, `setPriority`, `changeStatus`, `applyTriage`) dispatches `feedback-updated`. `sendToGithub()` calls `guard()` first.
  - The kanban dialog shell: `.fb-dialog` > `.fb-dialog__backdrop` + `.fb-dialog__panel[role=dialog][aria-labelledby=fb-drawer-title]`; the detail's close button dispatches `fb-overlay-close`.
  - Translation keys `detail.*` (incl. `detail.send_github`, which Task 10 reuses for the resource action).
  - CSS: `fb-dialog` (+ `__backdrop`, `__panel`, `__cols`, `__main`, `__side`), `fb-cover` (+ `--empty`, `__bar`, `__status`), `fb-headline` (+ `__text`, `__title`, `__sub`), `fb-props`, `fb-prop` (+ `__label`, `__who`), `fb-tech`, `fb-gallery` (+ `__item`, `__file`, `__name`, `__time`), `fb-side-head` (+ `__title`), `fb-dev-composer` (+ `--internal`, `__row`), `fb-segmented__btn--internal`, `fb-feed`, `fb-entry` (+ `--internal`, `--activity`, `__body`, `__head`, `__name`, `__text`, `__dot`, `__sentence`), `fb-role` (+ `--team`), `fb-lock-tag`. Removed: the drawer, `fb-detail__*`, `fb-thread`, `fb-comment*`, `fb-composer*`, `fb-tabs`, `fb-tab`, `fb-attachments`, `fb-activity*`, `fb-chip`.

- [ ] **Step 1: Write the failing tests**

Replace `tests/Feature/FeedbackDetailTest.php` with:

```php
<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Bus, Storage};
use Livewire\Livewire;
use YousefAman\FeedbackBoard\Enums\{AuthorRole, FeedbackPriority, FeedbackStatus};
use YousefAman\FeedbackBoard\Jobs\DeliverToDestination;
use YousefAman\FeedbackBoard\Livewire\{FeedbackDetail, FeedbackItemModal};
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};
use YousefAman\FeedbackBoard\Services\AttachmentStorage;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    config()->set('feedback-board.authorization.developer_resolver', fn ($u) => $u->name === 'Dev');
    Storage::fake('local');
    $this->travelTo('2026-10-01 09:00:00');
});

function detailDev(): TestUser
{
    return TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
}

it('lets a developer reply, add an internal note, assign, set priority and status', function () {
    $dev = detailDev();
    $item = FeedbackItem::factory()->create(['status' => FeedbackStatus::New]);

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->set('body', 'we are on it')->call('reply')->assertHasNoErrors()
        ->set('body', 'root cause: cache')->call('internalNote')->assertHasNoErrors()
        ->call('assignToMe')->assertHasNoErrors()
        ->call('setPriority', FeedbackPriority::High->value)->assertHasNoErrors()
        ->call('changeStatus', FeedbackStatus::InProgress->value)->assertHasNoErrors();

    $item->refresh();
    expect($item->comments()->where('is_internal', false)->value('body'))->toBe('we are on it')
        ->and($item->comments()->where('is_internal', true)->value('body'))->toBe('root cause: cache')
        ->and($item->assignee->is($dev))->toBeTrue()
        ->and($item->priority)->toBe(FeedbackPriority::High)
        ->and($item->status)->toBe(FeedbackStatus::InProgress);
});

it('forbids a non-developer from opening the detail panel', function () {
    $client = TestUser::create(['name' => 'Client', 'email' => 'c@x.com']);
    $item = FeedbackItem::factory()->create();
    Livewire::actingAs($client);
    // mount() calls abort(403); Livewire re-throws on the initial render, so assert the throw directly.
    expect(fn () => Livewire::test(FeedbackDetail::class, ['item' => $item]))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('tells the board after every change to a card', function (string $method, array $arguments) {
    $item = FeedbackItem::factory()->create(['priority' => FeedbackPriority::Low]);
    $item->ai_state = ['triage' => ['status' => 'completed', 'summary' => 'x', 'suggested_type' => 'feature', 'suggested_priority' => 'urgent', 'model' => 'fake']];
    $item->save();

    Livewire::actingAs(detailDev())->test(FeedbackDetail::class, ['item' => $item])
        ->set('body', 'text')
        ->call($method, ...$arguments)
        ->assertHasNoErrors()
        ->assertDispatched('feedback-updated');
})->with([
    'reply' => ['reply', []],
    'internal note' => ['internalNote', []],
    'assign' => ['assignToMe', []],
    'unassign' => ['unassign', []],
    'priority' => ['setPriority', ['high']],
    'status' => ['changeStatus', ['in_review']],
    'triage type' => ['applyTriage', ['type']],
]);

it('labels triage suggestions with translated enum labels', function () {
    $item = FeedbackItem::factory()->create(['priority' => FeedbackPriority::Low]);
    $item->ai_state = ['triage' => ['status' => 'completed', 'summary' => 'Looks urgent', 'suggested_type' => 'feature', 'suggested_priority' => 'urgent', 'model' => 'fake']];
    $item->save();

    Livewire::actingAs(detailDev())->test(FeedbackDetail::class, ['item' => $item])
        ->assertSee(__('feedback-board::feedback-board.triage.apply_priority').': Urgent')
        ->assertSee(__('feedback-board::feedback-board.triage.apply_type').': Feature request')
        ->assertSeeHtml('<span dir="auto">Looks urgent</span>');
});

it('offers a selectable No priority option that clears the priority', function () {
    $item = FeedbackItem::factory()->create(['priority' => null]);

    $component = Livewire::actingAs(detailDev())->test(FeedbackDetail::class, ['item' => $item])
        ->assertSeeHtml('<option value="" selected>');

    $component->call('setPriority', FeedbackPriority::High->value)
        ->assertDontSeeHtml('<option value="" selected>')
        ->call('setPriority', '')
        ->assertSeeHtml('<option value="" selected>');

    expect($item->fresh()->priority)->toBeNull();
});

it('links the captured page URL only when it is http(s), inside a closed details block', function (string $url, bool $linked) {
    $item = FeedbackItem::factory()->create(['meta' => ['url' => $url, 'user_agent' => 'Mozilla/5.0 Test', 'locale' => 'ar']]);

    $component = Livewire::actingAs(detailDev())->test(FeedbackDetail::class, ['item' => $item])
        ->assertSeeHtml('<details class="fb-details fb-tech">')
        ->assertSee(__('feedback-board::feedback-board.board.context'))
        ->assertSee('Mozilla/5.0 Test')
        ->assertSee($url)
        ->assertSeeHtml('<span dir="ltr">Mozilla/5.0 Test</span>');

    $linked
        ? $component->assertSeeHtml('href="'.e($url).'"')
        : $component->assertDontSeeHtml('href="'.e($url).'"')->assertDontSeeHtml('href="javascript:');
})->with([
    ['https://app.test/orders/7', true],
    ['javascript:alert(1)', false],
    [' javascript:alert(1)', false],
]);

it('omits the context section when nothing was captured', function () {
    $item = FeedbackItem::factory()->create(['meta' => []]);

    Livewire::actingAs(detailDev())->test(FeedbackDetail::class, ['item' => $item])
        ->assertDontSee(__('feedback-board::feedback-board.board.context'));
});

it('merges comments and activity sentences into one feed, newest first, without replied lines', function () {
    $item = FeedbackItem::factory()->create(['reporter_name' => 'Lena Client', 'status' => FeedbackStatus::New]);

    $component = Livewire::actingAs(detailDev())->test(FeedbackDetail::class, ['item' => $item])
        ->assertSee('Lena Client')
        ->assertSee(__('feedback-board::feedback-board.board.unassigned'));

    $component->call('changeStatus', FeedbackStatus::InReview->value);
    $this->travel(1)->minutes();
    $component->set('body', 'Looking into it')->call('reply');
    $this->travel(1)->minutes();
    $component->call('assignToMe');

    $html = $component->html();
    $moved = strpos($html, 'Dev moved the report from “New” to “In review”');
    $reply = strpos($html, 'Looking into it');
    $assigned = strpos($html, 'Dev assigned the report to Dev');

    expect($moved)->not->toBeFalse()
        ->and($assigned)->toBeLessThan($reply)
        ->and($reply)->toBeLessThan($moved)
        ->and($html)->not->toContain('Dev replied')
        ->and($html)->toContain('x-show="showActivity"');
});

it('shows comments with avatars and role tags, tints internal notes, and switches the composer by mode', function () {
    $item = FeedbackItem::factory()->create(['reporter_name' => 'Lena Client']);
    FeedbackComment::factory()->for($item, 'item')->create(['body' => 'Still broken', 'author_role' => AuthorRole::Reporter]);

    Livewire::actingAs(detailDev())->test(FeedbackDetail::class, ['item' => $item])
        ->set('body', 'root cause: cache')->call('internalNote')
        ->assertSeeHtml('class="fb-scope fb-detail"')
        ->assertSeeHtml('<h2 id="fb-drawer-title" class="fb-headline__title" dir="auto">')
        ->assertSeeHtml('class="fb-entry fb-entry--internal"')
        ->assertSee(__('feedback-board::feedback-board.detail.internal_tag'))
        ->assertSeeHtml('<bdi class="fb-entry__name">Dev</bdi>')
        ->assertSeeHtml('<span class="fb-role fb-role--team fb-tint">'.AuthorRole::Developer->label().'</span>')
        ->assertSeeHtml('<bdi class="fb-entry__name">Lena Client</bdi>')
        ->assertSeeHtml('<span class="fb-role">'.AuthorRole::Reporter->label().'</span>')
        ->assertSeeHtml('x-on:submit.prevent="mode === \'internal\' ? $wire.internalNote() : $wire.reply()"')
        ->assertSeeHtml('x-bind:aria-pressed="mode === \'internal\'"')
        ->assertSeeHtml('x-bind:class="{ \'fb-dev-composer--internal\': mode === \'internal\' }"')
        ->assertDontSeeHtml('wire:model="note"');
});

it('writes the unassignment as a sentence in the Arabic feed and keeps titles and URLs directed', function () {
    app()->setLocale('ar');
    $item = FeedbackItem::factory()->create(['title' => 'Export to CSV fails', 'meta' => ['url' => 'https://app.test/orders/7']]);

    Livewire::actingAs(detailDev())->test(FeedbackDetail::class, ['item' => $item])
        ->call('assignToMe')
        ->call('unassign')
        ->assertSee('ألغى Dev الإسناد')
        ->assertSeeHtml('dir="rtl"')
        ->assertSeeHtml('<h2 id="fb-drawer-title" class="fb-headline__title" dir="auto">Export to CSV fails</h2>')
        ->assertSeeHtml('dir="ltr">https://app.test/orders/7</a>');
});

it('posts replies and internal notes with images only, and accepts only images', function () {
    $item = FeedbackItem::factory()->create();

    Livewire::actingAs(detailDev())->test(FeedbackDetail::class, ['item' => $item])
        ->set('upload', UploadedFile::fake()->image('fix.png'))
        ->call('reply')
        ->assertHasNoErrors()
        ->set('upload', UploadedFile::fake()->image('trace.png'))
        ->call('internalNote')
        ->assertHasNoErrors()
        ->set('upload', UploadedFile::fake()->create('log.pdf', 5, 'application/pdf'))
        ->assertHasErrors('upload')
        ->assertCount('files', 0)
        ->call('reply')
        ->assertHasErrors(['body' => 'required_without']);

    $reply = $item->comments()->where('is_internal', false)->sole();
    $note = $item->comments()->where('is_internal', true)->sole();

    expect($reply->body)->toBe('')
        ->and($reply->attachments->pluck('original_name')->all())->toBe(['fix.png'])
        ->and($note->attachments->pluck('original_name')->all())->toBe(['trace.png']);
});

it('shows every attachment in the gallery, internal-note images included, with other files as download tiles', function () {
    $storage = app(AttachmentStorage::class);
    $item = FeedbackItem::factory()->create();
    $shot = $storage->store(UploadedFile::fake()->image('shot.png'), $item);
    $pdf = $storage->store(UploadedFile::fake()->create('spec.pdf', 5, 'application/pdf'), $item);
    $note = FeedbackComment::factory()->for($item, 'item')->create(['is_internal' => true, 'author_role' => AuthorRole::Developer]);
    $trace = $storage->store(UploadedFile::fake()->image('trace.png'), $item, $note);

    Livewire::actingAs(detailDev())->test(FeedbackDetail::class, ['item' => $item])
        ->assertSeeHtml('<ul class="fb-gallery">')
        ->assertSeeHtml('data-fb-src="'.$shot->url().'"')
        ->assertSeeHtml('data-fb-src="'.$trace->url().'"')
        ->assertSeeHtml('<a class="fb-gallery__file" href="'.$pdf->url().'">')
        ->assertSeeHtml('<span class="fb-gallery__name" dir="auto">spec.pdf</span>')
        ->assertSee(__('feedback-board::feedback-board.board.attachments').' · 3');
});

it('shows the cover band with the status select and the close button, and a short band without a cover', function () {
    $dev = detailDev();
    $item = FeedbackItem::factory()->create();

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->assertSeeHtml('<div class="fb-cover fb-cover--empty">')
        ->assertSeeHtml('wire:change="changeStatus($event.target.value)"')
        ->assertSeeHtml('x-on:click="$dispatch(\'fb-overlay-close\')"');

    $cover = app(AttachmentStorage::class)->store(UploadedFile::fake()->image('cover.png'), $item);

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item->fresh()])
        ->assertSeeHtml('<div class="fb-cover">')
        ->assertSeeHtml('data-fb-src="'.$cover->url().'"');
});

it('offers Send to GitHub only when it is configured and the report has no issue yet', function () {
    Bus::fake();
    $dev = detailDev();
    $item = FeedbackItem::factory()->create();

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->assertDontSeeHtml('wire:click="sendToGithub"');

    config()->set('feedback-board.destinations.github.token', 'token');
    config()->set('feedback-board.destinations.github.repo', 'acme/app');

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->assertSeeHtml('wire:click="sendToGithub"')
        ->call('sendToGithub')
        ->assertDontSeeHtml('wire:click="sendToGithub"')
        ->assertSee(__('feedback-board::feedback-board.detail.github_queued'));

    Bus::assertDispatched(DeliverToDestination::class);

    $item->forceFill(['external_refs' => ['github' => ['number' => 7]]])->save();

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item->fresh()])
        ->assertDontSeeHtml('wire:click="sendToGithub"');
});

it('guards sendToGithub like every other write', function () {
    Bus::fake();
    config()->set('feedback-board.destinations.github.token', 'token');
    config()->set('feedback-board.destinations.github.repo', 'acme/app');
    $component = Livewire::actingAs(detailDev())->test(FeedbackDetail::class, ['item' => FeedbackItem::factory()->create()]);

    config()->set('feedback-board.authorization.developer_resolver', fn () => false);

    $component->call('sendToGithub')->assertForbidden();
    Bus::assertNotDispatched(DeliverToDestination::class);
});

it('keeps a hostile file name out of every script position', function () {
    $name = 'x\');alert(1);(\'"</script>.png';
    $dev = detailDev();
    $item = FeedbackItem::factory()->for($dev, 'reporter')->create();
    $reply = FeedbackComment::factory()->for($item, 'item')->create(['author_role' => AuthorRole::Developer]);
    // Symfony strips everything up to the last "/" from an uploaded file's name, so the rows are written directly.
    $item->attachments()->create(['disk' => 'local', 'path' => 'x/a.png', 'original_name' => $name, 'mime' => 'image/png', 'size' => 10]);
    $item->attachments()->create(['feedback_comment_id' => $reply->id, 'disk' => 'local', 'path' => 'x/b.png', 'original_name' => $name, 'mime' => 'image/png', 'size' => 10]);

    foreach ([FeedbackDetail::class, FeedbackItemModal::class] as $component) {
        $html = Livewire::actingAs($dev)->test($component, ['item' => $item])->html();

        expect($html)->not->toContain($name)
            ->and($html)->toContain('data-fb-name="'.e($name).'"');

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();

        foreach ((new DOMXPath($dom))->query('//@*') as $attribute) {
            if (str_contains($attribute->nodeValue, 'alert(1)')) {
                expect($attribute->nodeName)->toStartWith('data-');
            }
        }
    }
});
```

In `tests/Feature/KanbanTest.php`, replace the tests `'opens a card detail panel from the board'` and `'closes the detail drawer'` with:

```php
it('opens a card in a dialog and closes it', function () {
    $item = FeedbackItem::factory()->create(['title' => 'Card X']);

    Livewire::actingAs(kanbanDev())->test(FeedbackKanban::class)
        ->call('open', $item->id)
        ->assertSet('selectedId', $item->id)
        ->assertSeeLivewire('feedback-board::feedback-detail')
        ->assertSeeHtml('<div class="fb-dialog" x-data x-on:keydown.escape.window="$wire.close()" x-on:fb-overlay-close.stop="$wire.close()">')
        ->assertSeeHtml('<div class="fb-dialog__backdrop" wire:click="close"></div>')
        ->assertSeeHtml('<div class="fb-dialog__panel" role="dialog" aria-modal="true" aria-labelledby="fb-drawer-title" x-trap.noscroll="true">')
        ->assertDontSeeHtml('fb-drawer"')
        ->call('close')
        ->assertSet('selectedId', null)
        ->assertDontSeeHtml('class="fb-dialog"')
        ->assertDontSeeLivewire('feedback-board::feedback-detail');
});

it('renders no duplicate element ids with a card open', function () {
    FeedbackItem::factory()->count(3)->create();

    $html = Livewire::actingAs(kanbanDev())->test(FeedbackKanban::class)->call('open', FeedbackItem::first()->id)->html();
    preg_match_all('/\sid="([^"]+)"/', $html, $ids);

    expect($ids[1])->toContain('fb-drawer-title')
        ->and(array_count_values($ids[1]))->each->toBe(1);
});
```

In `tests/Unit/StylesheetCoverageTest.php`, in the dynamic test, delete the two `fb-comment` lines from `$expected` (`...array_map(fn ($r) => 'fb-comment--'.$r->value, AuthorRole::cases()),` and `'fb-comment--internal',`) and remove `AuthorRole` from the `use` line.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/FeedbackDetailTest.php tests/Feature/KanbanTest.php`
Expected: FAIL — `note` is still a separate field, no `feedback-updated`, a disabled priority placeholder, no feed, gallery, cover band or dialog.

- [ ] **Step 3: The component**

Replace `src/Livewire/FeedbackDetail.php` with:

```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;
use YousefAman\FeedbackBoard\Actions\{ApplyTriageSuggestion, AssignFeedback, DispatchToRunner, ReplyToFeedback, RequestTriage, SendItemToGithub, SetPriority, TransitionStatus};
use YousefAman\FeedbackBoard\Enums\{ActivityAction, AuthorRole, FeedbackPriority, FeedbackStatus};
use YousefAman\FeedbackBoard\Livewire\Concerns\WithStagedUploads;
use YousefAman\FeedbackBoard\Models\{FeedbackActivity, FeedbackComment, FeedbackItem};
use YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy;
use YousefAman\FeedbackBoard\Services\AttachmentStorage;
use YousefAman\FeedbackBoard\Support\{Person, Ui};

class FeedbackDetail extends Component
{
    use WithStagedUploads;

    public FeedbackItem $item;

    /** Shared by reply() and internalNote(): the composer's mode decides which one the form calls. */
    public string $body = '';

    #[Locked]
    public bool $imagesFailed = false;

    #[Locked]
    public bool $githubQueued = false;

    public function mount(FeedbackItem $item): void
    {
        abort_unless(FeedbackItemPolicy::isDeveloper(auth()->user()), 403); // hard enforcement
        $this->item = $item;
    }

    protected function guard(): void
    {
        abort_unless(FeedbackItemPolicy::isDeveloper(auth()->user()), 403);
    }

    /** A reply the reporter sees and is notified about. */
    public function reply(ReplyToFeedback $reply, AttachmentStorage $storage): void
    {
        $this->comment($reply, $storage, internal: false);
    }

    /** A note only developers see; nobody is notified. */
    public function internalNote(ReplyToFeedback $reply, AttachmentStorage $storage): void
    {
        $this->comment($reply, $storage, internal: true);
    }

    public function assignToMe(AssignFeedback $assign): void
    {
        $this->guard();
        $assign->handle($this->item, auth()->user(), auth()->user());
        $this->changed();
    }

    public function unassign(AssignFeedback $assign): void
    {
        $this->guard();
        $assign->handle($this->item, null, auth()->user());
        $this->changed();
    }

    public function setPriority(string $priority, SetPriority $action): void
    {
        $this->guard();
        $p = $priority === '' ? null : (FeedbackPriority::tryFrom($priority) ?? abort(422));
        $action->handle($this->item, $p, auth()->user());
        $this->changed();
    }

    public function changeStatus(string $status, TransitionStatus $transition): void
    {
        $this->guard();
        $s = FeedbackStatus::tryFrom($status) ?? abort(422);
        $transition->handle($this->item, $s, auth()->user());
        $this->changed();
    }

    public function sendToGithub(SendItemToGithub $action): void
    {
        $this->guard();
        $action->handle($this->item, auth()->user());
        $this->githubQueued = true;
        $this->dispatch('feedback-sent-to-github'); // optimistic UI hook
    }

    public function requestTriage(RequestTriage $action): void
    {
        $this->guard();
        $action->handle($this->item, auth()->user());
        $this->item->refresh();
        $this->dispatch('feedback-triage-requested');
    }

    public function applyTriage(string $field, ApplyTriageSuggestion $action): void
    {
        $this->guard();
        $action->handle($this->item, auth()->user(), [$field]);
        $this->changed();
    }

    public function dispatchToRunner(DispatchToRunner $action): void
    {
        $this->guard();
        $action->handle($this->item, auth()->user());
        $this->item->refresh();
        $this->dispatch('feedback-runner-dispatched');
    }

    protected function uploadRules(): array
    {
        return app(AttachmentStorage::class)->imageRules();
    }

    public function render(): View
    {
        $cover = $this->item->coverAttachment;

        return view('feedback-board::livewire.feedback-detail', [
            'uid' => 'fb-'.$this->getId(),
            // The relation filters by MIME type; isImage() also checks the extension, as on the board.
            'cover' => $cover?->isImage() ? $cover : null,
            // Every attachment, comments' and internal notes' included: this view is developer-only.
            'gallery' => $this->item->attachments()->orderBy('id')->get(),
            'feed' => $this->feed(),
            'statuses' => FeedbackStatus::cases(),
            'priorities' => FeedbackPriority::cases(),
            'reporterName' => $this->reporterName(),
            'assigneeName' => $this->item->assignee_id !== null ? Person::name($this->item->assignee, $this->item->assignee_id) : null,
            'context' => $this->context(),
            'canAttach' => app(AttachmentStorage::class)->imageMimes() !== [],
            'canGithub' => $this->canSendToGithub(),
        ]);
    }

    private function comment(ReplyToFeedback $reply, AttachmentStorage $storage, bool $internal): void
    {
        $this->guard();
        $data = $this->validate($this->composerRules($storage), $this->composerMessages());

        $comment = $reply->handle($this->item, (string) ($data['body'] ?? ''), auth()->user(), AuthorRole::Developer, isInternal: $internal);
        $this->imagesFailed = ! $this->storeStagedFiles($storage, $this->item, $comment);
        $this->reset('body');
        $this->changed();
    }

    /** Reloads the report and tells the board, which redraws its card. */
    private function changed(): void
    {
        $this->item->refresh();
        $this->dispatch('feedback-updated');
    }

    /**
     * Comments and activity lines, newest first. "Replied" lines are left out: the comment itself
     * is the record. Ties (same second) put comments first, then the higher id.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function feed(): Collection
    {
        $comments = $this->item->comments()->with(['attachments', 'author'])->get();
        $activities = $this->item->activities()->with('actor')->where('action', '!=', ActivityAction::Replied->value)->get();
        $assignees = FeedbackActivity::assigneeNames($activities);

        return $comments
            ->map(fn (FeedbackComment $comment) => [
                'kind' => 'comment',
                'model' => $comment,
                'name' => $this->commentName($comment),
                'at' => $comment->created_at?->getTimestamp() ?? 0,
                'rank' => 1,
                'id' => $comment->id,
            ])
            ->concat($activities->map(fn (FeedbackActivity $activity) => [
                'kind' => 'activity',
                'model' => $activity,
                'sentence' => $activity->sentence(Person::name($activity->actor, $activity->actor_id), $assignees[$activity->id] ?? null),
                'at' => $activity->created_at?->getTimestamp() ?? 0,
                'rank' => 0,
                'id' => $activity->id,
            ]))
            ->sortBy([['at', 'desc'], ['rank', 'desc'], ['id', 'desc']])
            ->values();
    }

    /** The author's name; a guest reporter's comment uses the report's reporter, an AI comment its role. */
    private function commentName(FeedbackComment $comment): string
    {
        return Person::name($comment->author, $comment->author_id)
            ?? ($comment->author_role === AuthorRole::Reporter ? $this->reporterName() : null)
            ?? $comment->author_role->label();
    }

    private function reporterName(): ?string
    {
        foreach ([$this->item->reporter_name, $this->item->reporter_email] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /** The resource action's conditions: configured, and no issue yet. */
    private function canSendToGithub(): bool
    {
        return FeedbackItemPolicy::isDeveloper(auth()->user())
            && (bool) config('feedback-board.destinations.github.token')
            && (bool) config('feedback-board.destinations.github.repo')
            && ! isset(($this->item->external_refs ?? [])['github']);
    }

    /**
     * Captured request context (page URL, browser, locale). The URL comes from the Referer header
     * of the reporter's request, so it only becomes a link when it is http(s).
     *
     * @return list<array{label: string, value: string, href: ?string}>
     */
    private function context(): array
    {
        $meta = is_array($this->item->meta) ? $this->item->meta : [];
        $rows = [];

        foreach (['url' => 'page_url', 'user_agent' => 'browser', 'locale' => 'locale'] as $key => $label) {
            $value = $meta[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $rows[] = [
                'label' => __('feedback-board::feedback-board.board.'.$label),
                'value' => $value,
                'href' => $key === 'url' ? Ui::safeUrl($value) : null,
            ];
        }

        return $rows;
    }
}
```

- [ ] **Step 4: The views**

Replace `resources/views/livewire/feedback-detail.blade.php` with:

```blade
{{-- resources/views/livewire/feedback-detail.blade.php — the developer's card dialog content --}}
<div class="fb-scope fb-detail" data-fb-theme="{{ \YousefAman\FeedbackBoard\Support\Ui::theme() }}" dir="{{ __('feedback-board::feedback-board.dir') }}"
     x-data="{
        lightbox: null,
        zoom(el) { this.lightbox = { src: el.dataset.fbSrc, name: el.dataset.fbName } },
        closeLightbox() { this.lightbox = null },
        showActivity: true,
        init() { try { this.showActivity = localStorage.getItem('feedback-board.activity-hidden') !== '1' } catch (e) {} },
        toggleActivity() { this.showActivity = ! this.showActivity; try { localStorage.setItem('feedback-board.activity-hidden', this.showActivity ? '0' : '1') } catch (e) {} },
     }">
    @include('feedback-board::partials.assets')

    <div @class(['fb-cover', 'fb-cover--empty' => $cover === null])>
        <div class="fb-cover__bar">
            <label class="fb-sr-only" for="{{ $uid }}-status">{{ __('feedback-board::feedback-board.board.status') }}</label>
            <select id="{{ $uid }}-status" class="fb-select fb-cover__status" wire:change="changeStatus($event.target.value)">
                @foreach ($statuses as $s)
                    <option value="{{ $s->value }}" @selected($item->status === $s)>{{ $s->label() }}</option>
                @endforeach
            </select>
            <button type="button" class="fb-round-btn" x-on:click="$dispatch('fb-overlay-close')">
                <x-feedback-board::icon name="x-mark" />
                <span class="fb-sr-only">{{ __('feedback-board::feedback-board.close') }}</span>
            </button>
        </div>
        @if ($cover)
            <x-feedback-board::thumb :attachment="$cover" />
        @endif
    </div>

    <div class="fb-dialog__cols">
        <div class="fb-dialog__main">
            <header class="fb-headline">
                <x-feedback-board::type-icon :type="$item->type" />
                <div class="fb-headline__text">
                    <h2 id="fb-drawer-title" class="fb-headline__title" dir="auto">{{ $item->title }}</h2>
                    <p class="fb-headline__sub">
                        <span>#{{ $item->id }} · {{ $item->type->label() }} ·</span>
                        <span>{{ __('feedback-board::feedback-board.detail.sent_by') }} <bdi>{{ $reporterName ?? __('feedback-board::feedback-board.activity.someone') }}</bdi></span>
                        @if ($item->created_at)
                            <time datetime="{{ $item->created_at->toIso8601String() }}">{{ $item->created_at->diffForHumans() }}</time>
                        @endif
                    </p>
                </div>
            </header>

            <div class="fb-props">
                <div class="fb-prop">
                    <label class="fb-prop__label" for="{{ $uid }}-priority">{{ __('feedback-board::feedback-board.board.priority') }}</label>
                    <select id="{{ $uid }}-priority" class="fb-select" wire:change="setPriority($event.target.value)">
                        <option value="" @selected($item->priority === null)>{{ __('feedback-board::feedback-board.board.no_priority') }}</option>
                        @foreach ($priorities as $p)
                            <option value="{{ $p->value }}" @selected($item->priority === $p)>{{ $p->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="fb-prop">
                    <span class="fb-prop__label">{{ __('feedback-board::feedback-board.board.assignee') }}</span>
                    <div class="fb-prop__who">
                        @if ($assigneeName !== null)
                            <x-feedback-board::avatar :name="$assigneeName" />
                            <bdi>{{ $assigneeName }}</bdi>
                            <button type="button" class="fb-btn fb-btn--sm" wire:click="unassign">{{ __('feedback-board::feedback-board.board.unassign') }}</button>
                        @else
                            <span class="fb-muted">{{ __('feedback-board::feedback-board.board.unassigned') }}</span>
                            <button type="button" class="fb-btn fb-btn--sm" wire:click="assignToMe">{{ __('feedback-board::feedback-board.board.assign_me') }}</button>
                        @endif
                    </div>
                </div>
                <div class="fb-prop">
                    <span class="fb-prop__label">{{ __('feedback-board::feedback-board.detail.reporter') }}</span>
                    <div class="fb-prop__who">
                        @if ($reporterName !== null)
                            <x-feedback-board::avatar :name="$reporterName" />
                            <bdi>{{ $reporterName }}</bdi>
                        @else
                            <span class="fb-muted">{{ __('feedback-board::feedback-board.activity.someone') }}</span>
                        @endif
                    </div>
                </div>
            </div>

            <section class="fb-section">
                <h3 class="fb-section-title"><x-feedback-board::icon name="queue-list" />{{ __('feedback-board::feedback-board.item.description') }}</h3>
                <p class="fb-prose" dir="auto">{{ filled($item->description) ? $item->description : __('feedback-board::feedback-board.item.no_description') }}</p>
            </section>

            @if ($gallery->isNotEmpty())
                <section class="fb-section">
                    <h3 class="fb-section-title"><x-feedback-board::icon name="paper-clip" />{{ __('feedback-board::feedback-board.board.attachments') }} · {{ $gallery->count() }}</h3>
                    <ul class="fb-gallery">
                        @foreach ($gallery as $attachment)
                            <li class="fb-gallery__item" wire:key="g-{{ $attachment->id }}">
                                @if ($attachment->isImage())
                                    <x-feedback-board::thumb :attachment="$attachment" />
                                @else
                                    <a class="fb-gallery__file" href="{{ $attachment->url() }}"><x-feedback-board::icon name="document" /><span class="fb-sr-only">{{ __('feedback-board::feedback-board.detail.download', ['name' => $attachment->original_name]) }}</span></a>
                                @endif
                                <span class="fb-gallery__name" dir="auto">{{ $attachment->original_name }}</span>
                                @if ($attachment->created_at)
                                    <time class="fb-gallery__time" datetime="{{ $attachment->created_at->toIso8601String() }}">{{ $attachment->created_at->diffForHumans() }}</time>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($context !== [])
                <details class="fb-details fb-tech">
                    <summary>{{ __('feedback-board::feedback-board.board.context') }}</summary>
                    <dl class="fb-context">
                        @foreach ($context as $row)
                            <dt>{{ $row['label'] }}</dt>
                            <dd>
                                @if ($row['href'] !== null)
                                    <a class="fb-link" href="{{ $row['href'] }}" target="_blank" rel="noopener noreferrer" dir="ltr">{{ $row['value'] }}</a>
                                @else
                                    <span dir="ltr">{{ $row['value'] }}</span>
                                @endif
                            </dd>
                        @endforeach
                    </dl>
                </details>
            @endif

            @php($triage = $item->ai_state['triage'] ?? null)
            @if ($triage)
                <div class="fb-panel fb-triage" wire:key="triage-{{ $item->id }}"
                     @if(($triage['status'] ?? null) === 'pending') wire:poll.5s @endif>
                    @if (($triage['status'] ?? null) === 'pending')
                        <p class="fb-muted">{{ __('feedback-board::feedback-board.triage.analyzing') }}</p>
                    @elseif (($triage['status'] ?? null) === 'failed')
                        <p class="fb-notice fb-notice--danger">{{ __('feedback-board::feedback-board.triage.failed') }}</p>
                        <div class="fb-actions">
                            <button type="button" class="fb-btn fb-btn--sm" wire:click="requestTriage">{{ __('feedback-board::feedback-board.triage.retriage') }}</button>
                        </div>
                    @elseif (($triage['status'] ?? null) === 'completed')
                        <p><strong>{{ __('feedback-board::feedback-board.triage.summary') }}:</strong> <span dir="auto">{{ $triage['summary'] }}</span></p>
                        <div class="fb-actions">
                            @if (($triage['suggested_type'] ?? null) && $triage['suggested_type'] !== ($item->type?->value))
                                <button type="button" class="fb-btn fb-btn--sm" wire:click="applyTriage('type')">
                                    {{ __('feedback-board::feedback-board.triage.apply_type') }}: {{ \YousefAman\FeedbackBoard\Enums\FeedbackType::tryFrom((string) $triage['suggested_type'])?->label() ?? $triage['suggested_type'] }}
                                </button>
                            @endif
        
                            @if (($triage['suggested_priority'] ?? null) && $triage['suggested_priority'] !== ($item->priority?->value))
                                <button type="button" class="fb-btn fb-btn--sm" wire:click="applyTriage('priority')">
                                    {{ __('feedback-board::feedback-board.triage.apply_priority') }}: {{ \YousefAman\FeedbackBoard\Enums\FeedbackPriority::tryFrom((string) $triage['suggested_priority'])?->label() ?? $triage['suggested_priority'] }}
                                </button>
                            @endif
        
                            <button type="button" class="fb-btn fb-btn--sm" wire:click="requestTriage">{{ __('feedback-board::feedback-board.triage.retriage') }}</button>
                        </div>
                    @endif
                </div>
            @endif
        
            @php($runner = $item->ai_state['runner'] ?? null)
            @php($runnerConfigured = (bool) \YousefAman\FeedbackBoard\Runner\Support\RunnerGate::configured())
            @if ($runnerConfigured)
                <div class="fb-panel fb-runner" wire:key="runner-{{ $item->id }}"
                     @if(in_array(($runner['status'] ?? null), ['dispatched','running'], true)) wire:poll.5s @endif>
                    @if (! config('feedback-board.ingest.enabled'))
                        <p class="fb-notice fb-notice--warning">{{ __('feedback-board::feedback-board.runner.ingest_disabled') }}</p>
                    @elseif (($runner['status'] ?? null) === null)
                        <div class="fb-actions">
                            <button type="button" class="fb-btn fb-btn--sm" wire:click="dispatchToRunner">{{ __('feedback-board::feedback-board.runner.send') }}</button>
                        </div>
                    @else
                        @if (in_array($runner['status'], ['dispatched','running'], true))
                            <p class="fb-muted">{{ __('feedback-board::feedback-board.runner.'.$runner['status']) }} — {{ $runner['dispatched_at'] ?? '' }}</p>
                        @elseif ($runner['status'] === 'failed')
                            <p class="fb-notice fb-notice--danger">{{ __('feedback-board::feedback-board.runner.failed') }}: {{ $runner['error_code'] ?? '' }}</p>
                        @elseif ($runner['status'] === 'completed')
                            <p>{{ __('feedback-board::feedback-board.runner.completed') }}</p>
                        @endif
        
                        @if (($runner['diff'] ?? null) !== null)
                            <details class="fb-details"><summary>{{ __('feedback-board::feedback-board.runner.diff') }}</summary><pre class="fb-pre">{{ $runner['diff'] }}</pre></details>
                        @endif
                        @if (($runner['pr_url'] ?? null) !== null)
                            <a class="fb-link" href="{{ $runner['pr_url'] }}" target="_blank" rel="noopener noreferrer" dir="ltr">{{ $runner['pr_url'] }}</a>
                        @endif
        
                        @if (! in_array($runner['status'], ['dispatched','running'], true))
                            <div class="fb-actions">
                                <button type="button" class="fb-btn fb-btn--sm" wire:click="dispatchToRunner">{{ __('feedback-board::feedback-board.runner.resend') }}</button>
                            </div>
                        @endif
                    @endif
                </div>
            @endif

            @if ($githubQueued)
                <p class="fb-notice">{{ __('feedback-board::feedback-board.detail.github_queued') }}</p>
            @elseif ($canGithub)
                <div class="fb-actions">
                    <button type="button" class="fb-btn fb-btn--sm" wire:click="sendToGithub" wire:loading.attr="disabled" wire:target="sendToGithub">
                        <x-feedback-board::icon name="code-bracket" />{{ __('feedback-board::feedback-board.detail.send_github') }}
                    </button>
                </div>
            @endif
        </div>

        <aside class="fb-dialog__side" aria-labelledby="{{ $uid }}-side-title">
            <div class="fb-side-head">
                <h3 id="{{ $uid }}-side-title" class="fb-side-head__title"><x-feedback-board::icon name="chat-bubble-left-right" />{{ __('feedback-board::feedback-board.detail.side_title') }}</h3>
                <button type="button" class="fb-btn fb-btn--sm" x-on:click="toggleActivity()">
                    <span x-show="showActivity">{{ __('feedback-board::feedback-board.detail.hide_activity') }}</span>
                    <span x-show="! showActivity" x-cloak>{{ __('feedback-board::feedback-board.detail.show_activity') }}</span>
                </button>
            </div>

            <form class="fb-dev-composer"
                  x-data="{ mode: 'reply', @include('feedback-board::partials.upload-queue') }"
                  x-bind:class="{ 'fb-dev-composer--internal': mode === 'internal' }"
                  x-on:submit.prevent="mode === 'internal' ? $wire.internalNote() : $wire.reply()"
                  @if ($canAttach) x-on:paste="pasted($event)" @endif>
                <div class="fb-segmented" role="group" aria-label="{{ __('feedback-board::feedback-board.detail.mode_label') }}">
                    <button type="button" class="fb-segmented__btn" x-bind:aria-pressed="mode === 'reply'" x-bind:class="{ 'fb-tint': mode === 'reply' }" x-on:click="mode = 'reply'">
                        <x-feedback-board::icon name="chat-bubble-left-right" />{{ __('feedback-board::feedback-board.detail.mode_reply') }}
                    </button>
                    <button type="button" class="fb-segmented__btn fb-segmented__btn--internal" x-bind:aria-pressed="mode === 'internal'" x-on:click="mode = 'internal'">
                        <x-feedback-board::icon name="lock-closed" />{{ __('feedback-board::feedback-board.detail.mode_internal') }}
                    </button>
                </div>
                <label class="fb-sr-only" for="{{ $uid }}-body">{{ __('feedback-board::feedback-board.detail.comment_label') }}</label>
                <textarea id="{{ $uid }}-body" class="fb-textarea" wire:model="body" rows="3" dir="auto"
                          placeholder="{{ __('feedback-board::feedback-board.detail.placeholder') }}"></textarea>
                @include('feedback-board::partials.pending-images')
                <div class="fb-dev-composer__row">
                    @if ($canAttach)
                        <label class="fb-icon-btn fb-reply__attach">
                            <input type="file" class="fb-file-input" accept="image/*" multiple x-on:change="picked($event)">
                            <x-feedback-board::icon name="photo" />
                            <span class="fb-sr-only">{{ __('feedback-board::feedback-board.composer.attach_image') }}</span>
                        </label>
                    @endif
                    <span class="fb-hint" x-show="mode === 'reply'">{{ __('feedback-board::feedback-board.detail.hint_reply') }}</span>
                    <span class="fb-hint" x-show="mode === 'internal'" x-cloak>{{ __('feedback-board::feedback-board.detail.hint_internal') }}</span>
                    <button type="submit" class="fb-btn fb-btn--primary fb-btn--sm" x-bind:disabled="uploading > 0" wire:loading.attr="disabled" wire:target="reply, internalNote">
                        {{ __('feedback-board::feedback-board.composer.send') }}
                    </button>
                </div>
                <p class="fb-hint" x-show="uploading > 0" x-cloak>{{ __('feedback-board::feedback-board.form.uploading') }} <span x-text="progress + '%'"></span></p>
                @foreach (['body', 'upload', 'files', 'files.*'] as $field)
                    @error($field) <span class="fb-error">{{ $message }}</span> @enderror
                @endforeach
                @if ($imagesFailed)
                    <p class="fb-error">{{ __('feedback-board::feedback-board.composer.images_failed') }}</p>
                @endif
            </form>

            <div class="fb-feed">
                @forelse ($feed as $entry)
                    @if ($entry['kind'] === 'comment')
                        @php($comment = $entry['model'])
                        @php($commentImages = $comment->attachments->filter(fn ($attachment) => $attachment->isImage()))
                        @php($commentFiles = $comment->attachments->reject(fn ($attachment) => $attachment->isImage()))
                        <article wire:key="feed-c-{{ $comment->id }}" @class(['fb-entry', 'fb-entry--internal' => $comment->is_internal])>
                            <x-feedback-board::avatar :name="$entry['name']" size="lg" />
                            <div class="fb-entry__body">
                                <p class="fb-entry__head">
                                    <bdi class="fb-entry__name">{{ $entry['name'] }}</bdi>
                                    <span @class(['fb-role', 'fb-role--team fb-tint' => $comment->author_role !== \YousefAman\FeedbackBoard\Enums\AuthorRole::Reporter])>{{ $comment->author_role->label() }}</span>
                                    @if ($comment->created_at)
                                        <time datetime="{{ $comment->created_at->toIso8601String() }}">{{ $comment->created_at->diffForHumans() }}</time>
                                    @endif
                                    @if ($comment->is_internal)
                                        <span class="fb-lock-tag"><x-feedback-board::icon name="lock-closed" />{{ __('feedback-board::feedback-board.detail.internal_tag') }}</span>
                                    @endif
                                </p>
                                <div class="fb-entry__text">
                                    @if (filled($comment->body))
                                        <p class="fb-prose" dir="auto">{{ $comment->body }}</p>
                                    @endif
                                    @if ($commentImages->isNotEmpty())
                                        <div class="fb-thumbs">
                                            @foreach ($commentImages as $image)
                                                <x-feedback-board::thumb :attachment="$image" />
                                            @endforeach
                                        </div>
                                    @endif
                                    @if ($commentFiles->isNotEmpty())
                                        <ul class="fb-doc-links">
                                            @foreach ($commentFiles as $file)
                                                <li><a class="fb-doc-link" href="{{ $file->url() }}"><x-feedback-board::icon name="paper-clip" /><span dir="auto">{{ $file->original_name }}</span></a></li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            </div>
                        </article>
                    @else
                        <p class="fb-entry fb-entry--activity" wire:key="feed-a-{{ $entry['model']->id }}" x-show="showActivity">
                            <span class="fb-entry__dot" aria-hidden="true"></span>
                            <span>
                                <span class="fb-entry__sentence">{{ $entry['sentence'] }}</span>
                                @if ($entry['model']->created_at)
                                    · <time datetime="{{ $entry['model']->created_at->toIso8601String() }}">{{ $entry['model']->created_at->diffForHumans() }}</time>
                                @endif
                            </span>
                        </p>
                    @endif
                @empty
                    <p class="fb-muted">{{ __('feedback-board::feedback-board.detail.empty_feed') }}</p>
                @endforelse
            </div>
        </aside>
    </div>

    @include('feedback-board::partials.lightbox')
</div>
```

The triage and runner blocks above are lines 88–154 of the old view with only the indentation changed. Keep their markup contract exactly: the `wire:key`s, the conditional literal `wire:poll.5s` attributes, `dir="auto"` on the summary, the button labels and the `wire:click` names.

In `resources/views/livewire/feedback-kanban.blade.php`, replace the whole `@if ($selected) … @endif` drawer block with:

```blade
    @if ($selected)
        <div class="fb-dialog" x-data x-on:keydown.escape.window="$wire.close()" x-on:fb-overlay-close.stop="$wire.close()">
            <div class="fb-dialog__backdrop" wire:click="close"></div>
            <div class="fb-dialog__panel" role="dialog" aria-modal="true" aria-labelledby="fb-drawer-title" x-trap.noscroll="true">
                @livewire('feedback-board::feedback-detail', ['item' => $selected], key('detail-'.$selected->id))
            </div>
        </div>
    @endif
```

- [ ] **Step 5: Stylesheet**

In `resources/dist/feedback-board.css`:

a) Delete the `.fb-chip` rule (Badges section). Confirm first: `grep -rn 'fb-chip' resources/views resources/views-filament` prints nothing.

b) Replace `.fb-section-title` with:

```css
.fb-section-title {
    display: flex;
    align-items: center;
    gap: 0.375rem;
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--_fb-text-muted);
}

.fb-section-title .fb-icon {
    inline-size: 1rem;
    block-size: 1rem;
}
```

c) Delete the whole "Drawer" section and the whole "Item header, thread, composer (shared by reporter and developer views)" section.

d) In the "Overlays" section, directly before the `@media (max-width: 35rem)` block at its end, add:

```css
@keyframes fb-pop {
    from {
        opacity: 0;
        transform: translateY(0.5rem) scale(0.985);
    }
}

/* The developer's card dialog: centred, full height minus a margin; full screen below 57.5rem. */
.fb-dialog {
    position: fixed;
    inset: 0;
    z-index: calc(var(--_fb-z) + 10);
    display: grid;
    place-items: center;
    padding: 1.125rem;
}

.fb-dialog__backdrop {
    position: absolute;
    inset: 0;
    background: var(--_fb-backdrop);
    animation: fb-fade-in 0.2s ease-out;
}

.fb-dialog__panel {
    position: relative;
    display: flex;
    flex-direction: column;
    inline-size: min(66.25rem, 100%);
    block-size: 100%;
    min-inline-size: 0;
    overflow: hidden;
    border-radius: 0.875rem;
    background: var(--_fb-surface);
    color: var(--_fb-text);
    box-shadow: var(--_fb-shadow-lg);
    animation: fb-pop 0.22s ease-out;
}
```

e) Replace the whole "Developer detail" section (from `/* ---------- Developer detail ---------- */` through the `.fb-activity__time` rule) with:

```css
/* ---------- Card dialog content ---------- */

.fb-detail {
    display: flex;
    flex: 1;
    flex-direction: column;
    min-block-size: 0;
}

.fb-cover {
    position: relative;
    display: grid;
    place-items: center;
    block-size: 9.375rem;
    overflow: hidden;
    border-block-end: 1px solid var(--_fb-border);
    background: var(--_fb-surface-muted);
    flex: none;
}

.fb-cover--empty {
    block-size: 4rem;
}

.fb-cover .fb-thumb {
    block-size: 100%;
    max-inline-size: 70%;
    border-radius: 0;
}

.fb-cover .fb-thumb img {
    inline-size: auto;
    max-inline-size: 100%;
    block-size: 100%;
    aspect-ratio: auto;
}

.fb-cover__bar {
    position: absolute;
    inset-block-start: 0.875rem;
    inset-inline: 0.875rem;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
}

.fb-cover__status {
    inline-size: 10.625rem;
    background-color: var(--_fb-surface);
    font-weight: 700;
}

.fb-dialog__cols {
    display: grid;
    grid-template-columns: minmax(0, 1.55fr) minmax(0, 1fr);
    flex: 1;
    min-block-size: 0;
}

.fb-dialog__main,
.fb-dialog__side {
    display: grid;
    align-content: start;
    min-inline-size: 0;
    overflow-y: auto;
    overscroll-behavior: contain;
}

.fb-dialog__main {
    gap: 1.375rem;
    padding: 1.375rem 1.625rem 1.75rem;
}

.fb-dialog__side {
    gap: 0.875rem;
    padding: 1.125rem 1.25rem 1.5rem;
    border-inline-start: 1px solid var(--_fb-border);
    background: var(--_fb-bg);
}

.fb-headline {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
}

.fb-headline__text {
    display: grid;
    gap: 0.125rem;
    min-inline-size: 0;
}

.fb-headline__title {
    font-size: 1.375rem;
    font-weight: 700;
    line-height: 1.45;
    overflow-wrap: anywhere;
    text-wrap: balance;
}

.fb-headline__sub {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.375rem;
    color: var(--_fb-text-muted);
    font-size: 0.8125rem;
}

.fb-props {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.875rem;
}

.fb-prop {
    display: grid;
    align-content: start;
    gap: 0.375rem;
    min-inline-size: 0;
}

.fb-prop__label {
    color: var(--_fb-text-muted);
    font-size: 0.78125rem;
    font-weight: 600;
}

.fb-prop__who {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
    min-block-size: 2.125rem;
    font-weight: 500;
    overflow-wrap: anywhere;
}

.fb-gallery {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(9.375rem, 1fr));
    gap: 0.625rem;
}

.fb-gallery__item {
    display: grid;
    align-content: start;
    gap: 0.375rem;
    min-inline-size: 0;
}

.fb-gallery__item .fb-thumb img {
    aspect-ratio: 16 / 10;
}

.fb-gallery__file {
    display: grid;
    place-items: center;
    aspect-ratio: 16 / 10;
    border-radius: var(--_fb-radius-sm);
    background: var(--_fb-surface-muted);
    box-shadow: 0 0 0 1px var(--_fb-border);
    color: var(--_fb-text-muted);
}

.fb-gallery__file:hover {
    color: var(--_fb-text);
}

.fb-gallery__file .fb-icon {
    inline-size: 2rem;
    block-size: 2rem;
}

.fb-gallery__name {
    overflow: hidden;
    font-size: 0.78125rem;
    font-weight: 500;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.fb-gallery__time {
    color: var(--_fb-text-muted);
    font-size: 0.71875rem;
}

.fb-tech {
    padding: 0.625rem 0.875rem;
    border: 1px solid var(--_fb-border);
    border-radius: 0.625rem;
}

.fb-tech summary {
    color: var(--_fb-text-muted);
    font-size: 0.8125rem;
}

.fb-context {
    display: grid;
    grid-template-columns: max-content minmax(0, 1fr);
    gap: 0.375rem 1rem;
    font-size: 0.8125rem;
}

.fb-context dt {
    color: var(--_fb-text-muted);
}

.fb-context dd {
    overflow-wrap: anywhere;
}

.fb-triage,
.fb-runner {
    display: grid;
    gap: 0.625rem;
}

.fb-side-head {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.fb-side-head__title {
    display: flex;
    flex: 1;
    align-items: center;
    gap: 0.375rem;
    font-size: 0.9375rem;
    font-weight: 700;
}

.fb-side-head__title .fb-icon {
    inline-size: 1.125rem;
    block-size: 1.125rem;
    color: var(--_fb-text-muted);
}

.fb-dev-composer {
    display: grid;
    gap: 0.5rem;
    padding: 0.75rem;
    border: 1px solid var(--_fb-border);
    border-radius: 0.75rem;
    background: var(--_fb-surface);
}

.fb-dev-composer--internal {
    border-color: var(--_fb-internal-border);
    background: var(--_fb-internal-bg);
}

.fb-dev-composer .fb-textarea {
    min-block-size: 4.75rem;
    resize: vertical;
}

.fb-dev-composer__row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.375rem;
}

.fb-dev-composer__row .fb-btn {
    margin-inline-start: auto;
}

.fb-segmented__btn--internal[aria-pressed="true"] {
    border-color: var(--_fb-internal-border);
    background: var(--_fb-warning-bg);
    color: var(--_fb-warning-fg);
}

.fb-feed {
    display: grid;
    gap: 0.875rem;
}

.fb-entry {
    display: flex;
    align-items: flex-start;
    gap: 0.625rem;
    min-inline-size: 0;
}

.fb-entry__body {
    display: grid;
    flex: 1;
    gap: 0.375rem;
    min-inline-size: 0;
}

.fb-entry__head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.375rem;
    color: var(--_fb-text-muted);
    font-size: 0.78125rem;
}

.fb-entry__name {
    color: var(--_fb-text);
    font-size: 0.84375rem;
    font-weight: 700;
}

.fb-entry__text {
    display: grid;
    gap: 0.5rem;
    padding: 0.625rem 0.75rem;
    border: 1px solid var(--_fb-border);
    border-radius: 0.625rem;
    background: var(--_fb-surface);
    font-size: 0.875rem;
    line-height: 1.75;
}

.fb-entry--internal .fb-entry__text {
    border-color: var(--_fb-internal-border);
    background: var(--_fb-internal-bg);
}

.fb-role {
    padding: 0 0.375rem;
    border-radius: 0.3125rem;
    background: var(--_fb-gray-bg);
    color: var(--_fb-gray-fg);
    font-size: 0.6875rem;
    font-weight: 700;
}

.fb-role--team {
    background: transparent;
    color: var(--_fb-text);
}

.fb-lock-tag {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    color: var(--_fb-warning-fg);
    font-size: 0.6875rem;
    font-weight: 700;
}

.fb-lock-tag .fb-icon {
    inline-size: 0.8125rem;
    block-size: 0.8125rem;
}

.fb-entry--activity {
    align-items: center;
    color: var(--_fb-text-muted);
    font-size: 0.78125rem;
}

.fb-entry__dot {
    display: grid;
    place-items: center;
    inline-size: 2rem;
    flex: none;
}

.fb-entry__dot::after {
    content: "";
    inline-size: 0.4375rem;
    block-size: 0.4375rem;
    border-radius: 999px;
    background: var(--_fb-border-strong);
}

.fb-entry__sentence {
    color: var(--_fb-text);
    font-weight: 600;
}

@media (max-width: 68.75rem) {
    .fb-props {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 57.5rem) {
    .fb-dialog {
        padding: 0;
    }

    .fb-dialog__panel {
        border-radius: 0;
    }

    .fb-dialog__cols {
        display: block;
        overflow-y: auto;
    }

    .fb-dialog__main,
    .fb-dialog__side {
        overflow: visible;
    }

    .fb-dialog__side {
        border-inline-start: 0;
        border-block-start: 1px solid var(--_fb-border);
    }
}

@media (max-width: 35rem) {
    .fb-props {
        grid-template-columns: minmax(0, 1fr);
    }

    .fb-dialog__main,
    .fb-dialog__side {
        padding-inline: 1rem;
    }
}
```

- [ ] **Step 6: Translations**

In `resources/lang/en/feedback-board.php`, change `'context' => 'Context'` (in `board`) to `'context' => 'Technical context'` and add before the final `];`:

```php
    'detail' => [
        'sent_by' => 'Sent by',
        'reporter' => 'Reporter',
        'side_title' => 'Comments and activity',
        'hide_activity' => 'Hide activity',
        'show_activity' => 'Show activity',
        'mode_label' => 'Comment type',
        'mode_reply' => 'Reply to reporter',
        'mode_internal' => 'Internal note',
        'comment_label' => 'Comment',
        'placeholder' => 'Write a comment… you can paste a screenshot',
        'hint_reply' => 'The reporter sees this and gets notified.',
        'hint_internal' => 'Only developers see this; no notification.',
        'internal_tag' => 'Internal · hidden from the reporter',
        'download' => 'Download :name',
        'send_github' => 'Send to GitHub',
        'github_queued' => 'Sent to GitHub; the issue is being created.',
        'empty_feed' => 'No comments yet.',
    ],
```

In `resources/lang/ar/feedback-board.php`, change `'context' => 'السياق'` to `'context' => 'السياق التقني'` and add before the final `];`:

```php
    'detail' => [
        'sent_by' => 'أرسله',
        'reporter' => 'المُبلِّغ',
        'side_title' => 'التعليقات والنشاط',
        'hide_activity' => 'إخفاء النشاط',
        'show_activity' => 'إظهار النشاط',
        'mode_label' => 'نوع التعليق',
        'mode_reply' => 'رد للمُبلِّغ',
        'mode_internal' => 'ملاحظة داخلية',
        'comment_label' => 'التعليق',
        'placeholder' => 'اكتب تعليقًا… يمكنك لصق لقطة شاشة مباشرة',
        'hint_reply' => 'يصل الرد للمُبلِّغ ويُرسل له إشعار.',
        'hint_internal' => 'لا يراها إلا المطورون، ولا يُرسل إشعار.',
        'internal_tag' => 'داخلية · لا يراها المُبلِّغ',
        'download' => 'تنزيل :name',
        'send_github' => 'إرسال إلى GitHub',
        'github_queued' => 'أُرسل إلى GitHub، وجارٍ إنشاء البلاغ هناك.',
        'empty_feed' => 'لا توجد تعليقات بعد.',
    ],
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/FeedbackDetailTest.php tests/Feature/KanbanTest.php tests/Feature/MyReportsTest.php tests/Unit/StylesheetTest.php tests/Unit/StylesheetCoverageTest.php tests/Feature/LocalizationTest.php`
Expected: PASS.

Run: `grep -n 'wire:poll.5s' resources/views/livewire/feedback-detail.blade.php`
Expected: exactly two lines, the triage and the runner blocks, each still inside its `@if(…) … @endif`.

Run: `php -l src/Livewire/FeedbackDetail.php`, then `vendor/bin/pest`.
Expected: no failures.

- [ ] **Step 8: Commit**

```bash
git add src/Livewire/FeedbackDetail.php resources/views/livewire resources/dist/feedback-board.css resources/lang tests/Feature/FeedbackDetailTest.php tests/Feature/KanbanTest.php tests/Unit/StylesheetCoverageTest.php
git commit -m "feat(board): card dialog with cover band, gallery, image comments in reply and internal modes, and a merged activity feed"
```

---

### Task 10: Filament — "My reports" in the panel, translated navigation with icons and badges, the resource, panel colours

**Files:**
- Create: `src/Filament/Pages/MyFeedbackPage.php`, `src/Filament/PanelColors.php`, `resources/views-filament/filament/my-feedback-page.blade.php`
- Modify: `src/Filament/FeedbackBoardPlugin.php`, `src/Filament/Pages/FeedbackBoardPage.php`, `src/Filament/Resources/FeedbackItemResource.php`, `src/Support/Ui.php`, both language files
- Test: `tests/Filament/NavigationTest.php` (new), `tests/Filament/PanelColorsTest.php` (new), `tests/Filament/FilamentTestCase.php`, `tests/Feature/FilamentPluginTest.php`

**Interfaces:**
- Consumes: `FeedbackItem::ownedBy()`/`hasUnread()` (Task 1), `FeedbackStatus::openCases()` (Task 1), `FeedbackType::color()` (Task 5), `Ui::reportsUrl()`/`reportLink()` (Task 6), `MyReportsBoard` with `inPanel` and the `fb-open-report-form` event (Task 7), `detail.send_github` and `kanban.*` strings (Tasks 8–9).
- Produces:
  - `FeedbackBoardPlugin::myReports(bool $condition = true): static`, `hasMyReports(): bool`, `static current(): ?FeedbackBoardPlugin` (the current panel's instance, through the panel's own `hasPlugin()`).
  - `MyFeedbackPage` (slug `my-feedback`, sort 1, `heroicon-o-chat-bubble-left-right`, primary badge = reports with unread replies); `FeedbackBoardPage` gains sort 2, `heroicon-o-view-columns`, a gray badge of open reports and a subheading; the resource gains sort 3, `heroicon-o-inbox-stack`, translated labels and enum badges. All three sit in the group `nav.group`.
  - `PanelColors::style(): string`, `PanelColors::tokens(array $palette): ?array{accent, hover, contrast}`, `PanelColors::allowed(mixed $value): bool`.
  - `Ui::reportsUrl()` returns `MyFeedbackPage::getUrl()` inside a panel whose plugin enables it.

- [ ] **Step 1: Write the failing tests**

`tests/Filament/NavigationTest.php`:

```php
<?php

use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Log, Schema};
use Livewire\Livewire;
use YousefAman\FeedbackBoard\Enums\{AuthorRole, FeedbackPriority, FeedbackStatus, FeedbackType};
use YousefAman\FeedbackBoard\Filament\Pages\{FeedbackBoardPage, MyFeedbackPage};
use YousefAman\FeedbackBoard\Filament\Resources\FeedbackItemResource;
use YousefAman\FeedbackBoard\Livewire\FeedbackForm;
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};
use YousefAman\FeedbackBoard\Support\Ui;
use YousefAman\FeedbackBoard\Tests\Filament\FilamentTestUser;

beforeEach(fn () => config()->set('feedback-board.authorization.developer_resolver', fn ($user) => $user->name === 'Dev'));

it('gives every panel user a My reports page inside the panel, with the page owning the heading and the New report action', function () {
    $client = FilamentTestUser::create(['name' => 'Client', 'email' => 'c@x.com']);
    FeedbackItem::factory()->for($client, 'reporter')->create(['title' => 'Mine']);
    FeedbackItem::factory()->create(['title' => 'Theirs']);

    $html = $this->actingAs($client)->get(MyFeedbackPage::getUrl())->assertOk()->getContent();

    expect($html)->toContain('class="fb-scope fb-reports"')
        ->toContain('Mine')
        ->not->toContain('Theirs')
        ->not->toContain('class="fb-head"')
        ->toContain(__('feedback-board::feedback-board.reports.subheading'))
        ->toMatch("/\\\$dispatch\\((?:'|&#039;)fb-open-report-form(?:'|&#039;)\\)/")
        ->not->toContain(FeedbackBoardPage::getUrl())
        ->not->toContain(FeedbackItemResource::getUrl('index'));
});

it('groups the package pages under one group, translated at request time, with icons and an order', function () {
    $dev = FilamentTestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    app()->setLocale('ar'); // after the panel was built: labels must still follow the request locale

    $html = $this->actingAs($dev)->get(FeedbackBoardPage::getUrl())->assertOk()->getContent();

    expect($html)->toContain('الملاحظات')
        ->toContain('بلاغاتي')
        ->toContain('كل البلاغات')
        ->toContain('لوحة الملاحظات')
        ->toContain('اسحب البطاقة بين الأعمدة');

    expect([MyFeedbackPage::getNavigationIcon(), FeedbackBoardPage::getNavigationIcon(), FeedbackItemResource::getNavigationIcon()])
        ->toBe(['heroicon-o-chat-bubble-left-right', 'heroicon-o-view-columns', 'heroicon-o-inbox-stack'])
        ->and([MyFeedbackPage::getNavigationSort(), FeedbackBoardPage::getNavigationSort(), FeedbackItemResource::getNavigationSort()])->toBe([1, 2, 3]);
});

it('badges My reports with unread replies and the board with open reports', function () {
    $this->travelTo('2026-10-01 09:00:00');
    $dev = FilamentTestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $this->actingAs($dev);
    $mine = FeedbackItem::factory()->for($dev, 'reporter')->create();
    FeedbackItem::factory()->create(['status' => FeedbackStatus::Resolved]);

    expect(MyFeedbackPage::getNavigationBadge())->toBeNull()
        ->and(MyFeedbackPage::getNavigationBadgeColor())->toBe('primary')
        ->and(FeedbackBoardPage::getNavigationBadge())->toBe('1')
        ->and(FeedbackBoardPage::getNavigationBadgeColor())->toBe('gray');

    $this->travel(1)->minutes();
    FeedbackComment::factory()->for($mine, 'item')->create(['author_role' => AuthorRole::Developer]);

    expect(MyFeedbackPage::getNavigationBadge())->toBe('1');
});

it('keeps the board and the resource developer-only', function () {
    $this->actingAs(FilamentTestUser::create(['name' => 'Client', 'email' => 'c@x.com']));

    $this->get(FeedbackBoardPage::getUrl())->assertForbidden();
    $this->get(FeedbackItemResource::getUrl('index'))->assertForbidden();
    $this->get(MyFeedbackPage::getUrl())->assertOk();
});

it('links My reports inside a panel and the standalone page outside one', function () {
    Filament::setCurrentPanel(null);
    expect(Ui::reportsUrl())->toBe(route('feedback-board.page'));

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    expect(Ui::reportsUrl())->toBe(MyFeedbackPage::getUrl())
        ->and(Ui::reportsUrl(7))->toBe(MyFeedbackPage::getUrl().'?report=7');

    Livewire::actingAs(FilamentTestUser::create(['name' => 'Client', 'email' => 'c@x.com']))
        ->test(FeedbackForm::class)
        ->assertSet('reportsUrl', MyFeedbackPage::getUrl());
});

it('leaves My reports out of a panel that turns it off', function () {
    Filament::getPanel('admin')->getPlugin('feedback-board')->myReports(false);
    $client = FilamentTestUser::create(['name' => 'Client', 'email' => 'c@x.com']);

    $this->actingAs($client)->get(MyFeedbackPage::getUrl())->assertForbidden();

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    expect(Ui::reportsUrl(5))->toBe(route('feedback-board.page').'?report=5');
});

it('translates the resource and shows the enum labels as badges', function () {
    config()->set('feedback-board.destinations.github.token', 'token');
    config()->set('feedback-board.destinations.github.repo', 'acme/app');
    app()->setLocale('ar');
    FeedbackItem::factory()->create(['title' => 'Export fails', 'type' => FeedbackType::Feature, 'priority' => FeedbackPriority::Urgent]);

    $html = $this->actingAs(FilamentTestUser::create(['name' => 'Dev', 'email' => 'd@x.com']))
        ->get(FeedbackItemResource::getUrl('index'))->assertOk()->getContent();

    expect($html)->toContain('العنوان')
        ->toContain('اقتراح')
        ->toContain('عاجلة')
        ->toContain('جديد')
        ->toContain('إرسال إلى GitHub')
        ->and(FeedbackItemResource::getModelLabel())->toBe('بلاغ')
        ->and(FeedbackItemResource::getPluralModelLabel())->toBe('بلاغات');
});

it('keeps the panel browsable before the new migration has run, with one warning', function () {
    Schema::table('feedback_items', fn (Blueprint $table) => $table->dropColumn(['reporter_read_at', 'reporter_activity_at']));
    Log::spy();
    $dev = FilamentTestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);

    $this->actingAs($dev)->get(FeedbackBoardPage::getUrl())->assertOk();
    $this->actingAs($dev)->get(FeedbackItemResource::getUrl('index'))->assertOk();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, 'feedback-board'))
        ->once();
});
```

`tests/Filament/PanelColorsTest.php`:

```php
<?php

use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Illuminate\Support\Facades\Vite;
use YousefAman\FeedbackBoard\Filament\Pages\FeedbackBoardPage;
use YousefAman\FeedbackBoard\Filament\PanelColors;
use YousefAman\FeedbackBoard\Tests\Filament\FilamentTestUser;

beforeEach(fn () => config()->set('feedback-board.authorization.developer_resolver', fn ($user) => $user->name === 'Dev'));

it('prints the panel primary as bridge tokens with a readable contrast colour', function (?array $primary, array $palette, string $contrast) {
    if ($primary !== null) {
        Filament::getPanel('admin')->colors(['primary' => $primary]);
    }

    $html = $this->actingAs(FilamentTestUser::create(['name' => 'Dev']))->get(FeedbackBoardPage::getUrl())->assertOk()->getContent();

    expect($html)->toContain('<style>:root{--fb-panel-accent:'.$palette[600].';--fb-panel-accent-hover:'.$palette[500].';--fb-panel-accent-contrast:'.$contrast.'}</style>');
})->with([
    // Amber is Filament's default primary and a light one: white on amber-600 is 3.2:1, its 950 shade 5:1.
    'amber, a light primary' => [null, Color::Amber, Color::Amber[950]],
    'pink' => [Color::Pink, Color::Pink, '#ffffff'],
]);

it('carries the Vite CSP nonce when the app sets one', function () {
    Vite::useCspNonce('n0nce');

    $html = $this->actingAs(FilamentTestUser::create(['name' => 'Dev']))->get(FeedbackBoardPage::getUrl())->assertOk()->getContent();

    expect($html)->toContain('<style nonce="n0nce">:root{--fb-panel-accent:');
});

it('prints nothing for a missing shade or a value outside the allow-list', function (array $palette) {
    expect(PanelColors::tokens($palette))->toBeNull();
})->with([
    'no 950 shade' => [[500 => Color::Pink[500], 600 => Color::Pink[600]]],
    'a CSS injection' => [[500 => Color::Pink[500], 600 => 'red;}</style><script>alert(1)</script>', 950 => Color::Pink[950]]],
    'a named colour' => [[500 => 'red', 600 => 'red', 950 => 'black']],
]);

it('accepts oklch numbers and hex only', function () {
    expect(PanelColors::allowed('oklch(0.592 0.249 0.584)'))->toBeTrue()
        ->and(PanelColors::allowed('oklch(0.59782352941176 0.16913636363636 0.584)'))->toBeTrue()
        ->and(PanelColors::allowed('#db2777'))->toBeTrue()
        ->and(PanelColors::allowed('oklch(var(--x) 0 0)'))->toBeFalse()
        ->and(PanelColors::allowed('rgb(0, 0, 0)'))->toBeFalse()
        ->and(PanelColors::allowed(null))->toBeFalse();
});
```

In `tests/Filament/FilamentTestCase.php`, add:

```php
    protected function setUp(): void
    {
        parent::setUp();

        // The missing-migration warning is logged once per process; each test starts afresh.
        \YousefAman\FeedbackBoard\Filament\Pages\MyFeedbackPage::resetMigrationWarning();
    }
```

In `tests/Feature/FilamentPluginTest.php`, append inside the test, after the last `->and(…)`:

```php
        ->and(class_exists(\YousefAman\FeedbackBoard\Filament\Pages\MyFeedbackPage::class))->toBeTrue()
        ->and($plugin->hasMyReports())->toBeTrue()
        ->and(FeedbackBoardPlugin::make()->myReports(false)->hasMyReports())->toBeFalse();
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Filament tests/Feature/FilamentPluginTest.php`
Expected: FAIL — `MyFeedbackPage` and `PanelColors` do not exist, no group or icons, no badges, English resource labels.

- [ ] **Step 3: Panel colours**

Create `src/Filament/PanelColors.php`:

```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Filament;

use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Support\Facades\Vite;

/**
 * Prints the panel's primary colour as the package's internal bridge tokens (read by the stylesheet
 * only inside .fi .fb-scope, beneath the public tokens). Only allow-listed values are printed:
 * oklch() with plain numbers, or hex. Str::sanitizeCssColor() is absent in early Filament 4.x.
 */
final class PanelColors
{
    private const WCAG_AA_TEXT = 4.5;

    private const WHITE = '#ffffff';

    public static function style(): string
    {
        $tokens = self::tokens(FilamentColor::getColor('primary') ?? []);

        if ($tokens === null) {
            return '';
        }

        $nonce = Vite::cspNonce();

        return '<style'.($nonce ? ' nonce="'.e($nonce).'"' : '').'>'
            .':root{--fb-panel-accent:'.$tokens['accent'].';--fb-panel-accent-hover:'.$tokens['hover'].';--fb-panel-accent-contrast:'.$tokens['contrast'].'}'
            .'</style>';
    }

    /**
     * Button text on the 600 shade: white or the 950 shade, whichever passes WCAG AA (white first);
     * if neither does, the one with the higher ratio. Null when a shade is missing or not allowed.
     *
     * @param  array<int|string, mixed>  $palette
     * @return array{accent: string, hover: string, contrast: string}|null
     */
    public static function tokens(array $palette): ?array
    {
        $accent = $palette[600] ?? null;
        $hover = $palette[500] ?? null;
        $dark = $palette[950] ?? null;

        if (! self::allowed($accent) || ! self::allowed($hover) || ! self::allowed($dark)) {
            return null;
        }

        $onWhite = Color::calculateContrastRatio($accent, self::WHITE);
        $onDark = Color::calculateContrastRatio($accent, $dark);

        $contrast = match (true) {
            $onWhite >= self::WCAG_AA_TEXT => self::WHITE,
            $onDark >= self::WCAG_AA_TEXT => $dark,
            default => $onWhite >= $onDark ? self::WHITE : $dark,
        };

        return ['accent' => $accent, 'hover' => $hover, 'contrast' => $contrast];
    }

    public static function allowed(mixed $value): bool
    {
        return is_string($value) && (
            preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $value) === 1
            || preg_match('/^oklch\(\d*\.?\d+%? \d*\.?\d+ \d*\.?\d+\)$/', $value) === 1
        );
    }
}
```

- [ ] **Step 4: The plugin and the pages**

Replace `src/Filament/FeedbackBoardPlugin.php` with:

```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Filament;

use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use YousefAman\FeedbackBoard\Filament\Pages\{FeedbackBoardPage, MyFeedbackPage};
use YousefAman\FeedbackBoard\Filament\Resources\FeedbackItemResource;

class FeedbackBoardPlugin implements Plugin
{
    protected bool $myReports = true;

    public function getId(): string
    {
        return 'feedback-board';
    }

    public static function make(): static
    {
        return app(static::class);
    }

    /** "My reports" for every user of this panel; on by default. */
    public function myReports(bool $condition = true): static
    {
        $this->myReports = $condition;

        return $this;
    }

    public function hasMyReports(): bool
    {
        return $this->myReports;
    }

    /**
     * This plugin on the current panel; null outside a panel or on a panel without it. Uses the
     * panel's own hasPlugin(): the Filament facade's helpers fall back to the default panel, which
     * could send a host-page user to a panel they cannot open.
     */
    public static function current(): ?self
    {
        $panel = Filament::getCurrentPanel();

        if ($panel === null || ! $panel->hasPlugin('feedback-board')) {
            return null;
        }

        $plugin = $panel->getPlugin('feedback-board');

        return $plugin instanceof self ? $plugin : null;
    }

    public function register(Panel $panel): void
    {
        $panel->resources([FeedbackItemResource::class])
            ->pages([MyFeedbackPage::class, FeedbackBoardPage::class])
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn (): string => PanelColors::style());
    }

    public function boot(Panel $panel): void {}
}
```

Create `src/Filament/Pages/MyFeedbackPage.php`:

```php
<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Filament\Pages;

use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;
use Throwable;
use YousefAman\FeedbackBoard\Filament\FeedbackBoardPlugin;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

/** The reporter's "My reports" inside the panel, for every signed-in user unless the plugin turns it off. */
class MyFeedbackPage extends Page
{
    protected string $view = 'feedback-board::filament.my-feedback-page';

    protected static ?string $slug = 'my-feedback';

    private static bool $warned = false;

    public static function getNavigationLabel(): string
    {
        return static::$navigationLabel ?? __('feedback-board::feedback-board.nav.my_reports');
    }

    public static function getNavigationGroup(): string
    {
        return __('feedback-board::feedback-board.nav.group');
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-chat-bubble-left-right';
    }

    public static function getNavigationSort(): int
    {
        return 1;
    }

    public function getTitle(): string
    {
        return static::$title ?? __('feedback-board::feedback-board.board.my_reports');
    }

    public function getSubheading(): string
    {
        return __('feedback-board::feedback-board.reports.subheading');
    }

    /** Read at call time from the current panel's plugin, so two panels can differ. */
    public static function canAccess(): bool
    {
        return auth()->check() && (FeedbackBoardPlugin::current()?->hasMyReports() ?? false);
    }

    /**
     * Reports with unread replies: one query on every panel page. A host that updated the package
     * but has not migrated yet keeps a working panel and gets one warning per process.
     */
    public static function getNavigationBadge(): ?string
    {
        return rescue(function (): ?string {
            $count = FeedbackItem::query()->ownedBy(auth()->user())->hasUnread()->count();

            return $count > 0 ? (string) $count : null;
        }, function (Throwable $e): ?string {
            if (! self::$warned) {
                self::$warned = true;
                Log::warning('feedback-board: the "My reports" badge failed; publish and run the package migrations.', ['exception' => $e->getMessage()]);
            }

            return null;
        }, report: false);
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'primary';
    }

    /** @internal Test seam: the warning is logged once per process. */
    public static function resetMigrationWarning(): void
    {
        self::$warned = false;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newReport')
                ->label(__('feedback-board::feedback-board.board.new_report'))
                ->icon('heroicon-o-plus')
                // MyReportsBoard owns the form; only it listens to this browser event.
                ->alpineClickHandler("\$dispatch('fb-open-report-form')"),
        ];
    }
}
```

Create `resources/views-filament/filament/my-feedback-page.blade.php`:

```blade
{{-- resources/views-filament/filament/my-feedback-page.blade.php --}}
<x-filament-panels::page>
    @livewire('feedback-board::my-reports-board', ['inPanel' => true])
</x-filament-panels::page>
```

In `src/Filament/Pages/FeedbackBoardPage.php`, add `use YousefAman\FeedbackBoard\Enums\FeedbackStatus;` and `use YousefAman\FeedbackBoard\Models\FeedbackItem;`, and add after `getTitle()`:

```php
    public function getSubheading(): string
    {
        return __('feedback-board::feedback-board.kanban.subheading');
    }

    public static function getNavigationGroup(): string
    {
        return __('feedback-board::feedback-board.nav.group');
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-view-columns';
    }

    public static function getNavigationSort(): int
    {
        return 2;
    }

    /** Open reports. Filament checks canAccess() first, so this never runs for non-developers. */
    public static function getNavigationBadge(): ?string
    {
        $count = FeedbackItem::query()
            ->whereIn('status', array_map(fn (FeedbackStatus $status) => $status->value, FeedbackStatus::openCases()))
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'gray';
    }
```

- [ ] **Step 5: The resource**

In `src/Filament/Resources/FeedbackItemResource.php`, add `use YousefAman\FeedbackBoard\Enums\{FeedbackPriority, FeedbackStatus, FeedbackType};`, add after `canViewAny()`:

```php
    public static function getModelLabel(): string
    {
        return __('feedback-board::feedback-board.resource.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('feedback-board::feedback-board.resource.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('feedback-board::feedback-board.nav.all_reports');
    }

    public static function getNavigationGroup(): string
    {
        return __('feedback-board::feedback-board.nav.group');
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-inbox-stack';
    }

    public static function getNavigationSort(): int
    {
        return 3;
    }
```

replace the `->columns([...])` list with:

```php
        return $table->columns([
            TextColumn::make('title')->label(__('feedback-board::feedback-board.resource.columns.title'))->searchable()->limit(40),
            // The enums do not implement Filament's HasLabel/HasColor: Filament is optional.
            TextColumn::make('type')->label(__('feedback-board::feedback-board.resource.columns.type'))->badge()
                ->formatStateUsing(fn (?FeedbackType $state): string => $state?->label() ?? '')
                ->color(fn (?FeedbackType $state): string => $state?->color() ?? 'gray'),
            TextColumn::make('status')->label(__('feedback-board::feedback-board.resource.columns.status'))->badge()
                ->formatStateUsing(fn (?FeedbackStatus $state): string => $state?->label() ?? '')
                ->color(fn (?FeedbackStatus $state): string => $state?->color() ?? 'gray'),
            TextColumn::make('priority')->label(__('feedback-board::feedback-board.resource.columns.priority'))->badge()->toggleable()
                ->formatStateUsing(fn (?FeedbackPriority $state): string => $state?->label() ?? '')
                ->color(fn (?FeedbackPriority $state): string => $state?->color() ?? 'gray'),
            TextColumn::make('reporter_name')->label(__('feedback-board::feedback-board.resource.columns.reporter'))->toggleable(),
            TextColumn::make('created_at')->label(__('feedback-board::feedback-board.resource.columns.created_at'))
                ->since()->dateTimeTooltip()->sortable(),
        ])->recordActions([
```

and change `->label('Send to GitHub')` to `->label(__('feedback-board::feedback-board.detail.send_github'))`.

- [ ] **Step 6: `Ui::reportsUrl()` inside a panel**

In `src/Support/Ui.php`, replace `reportsUrl()` with:

```php
    /** "My reports": the panel page inside a Filament panel that enables it, else the standalone page. */
    public static function reportsUrl(?int $reportId = null): string
    {
        $url = self::panelReportsUrl() ?? route('feedback-board.page');

        return $reportId === null ? $url : self::reportLink($url, $reportId);
    }

    // Filament may be installed but not booted (an app without panels): check the provider, not the class.
    private static function panelReportsUrl(): ?string
    {
        if (! app()->providerIsLoaded(\Filament\FilamentServiceProvider::class)) {
            return null;
        }

        return \YousefAman\FeedbackBoard\Filament\FeedbackBoardPlugin::current()?->hasMyReports()
            ? \YousefAman\FeedbackBoard\Filament\Pages\MyFeedbackPage::getUrl()
            : null;
    }
```

- [ ] **Step 7: Translations**

In `resources/lang/en/feedback-board.php`, add `'subheading' => 'Drag a card between columns to change its status; click it to open.',` as the first entry of the `kanban` block, and add before the final `];`:

```php
    'nav' => ['group' => 'Feedback', 'my_reports' => 'My reports', 'all_reports' => 'All reports'],
    'resource' => [
        'model' => 'report',
        'plural' => 'reports',
        'columns' => ['title' => 'Title', 'type' => 'Type', 'status' => 'Status', 'priority' => 'Priority', 'reporter' => 'Reporter', 'created_at' => 'Sent'],
    ],
```

In `resources/lang/ar/feedback-board.php`, add `'subheading' => 'اسحب البطاقة بين الأعمدة لتغيير حالتها، واضغطها لفتح التفاصيل.',` as the first entry of the `kanban` block, and add before the final `];`:

```php
    'nav' => ['group' => 'الملاحظات', 'my_reports' => 'بلاغاتي', 'all_reports' => 'كل البلاغات'],
    'resource' => [
        'model' => 'بلاغ',
        'plural' => 'بلاغات',
        'columns' => ['title' => 'العنوان', 'type' => 'النوع', 'status' => 'الحالة', 'priority' => 'الأولوية', 'reporter' => 'المُبلِّغ', 'created_at' => 'أُرسل'],
    ],
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Filament tests/Feature/FilamentPluginTest.php tests/Feature/LocalizationTest.php tests/Feature/FeedbackFormTest.php tests/Unit/StylesheetCoverageTest.php`
Expected: PASS. (`FeedbackFormTest` runs without a booted Filament: it pins the provider guard in `Ui::reportsUrl()`.)

Run: `php -l` on the five changed PHP files, then `vendor/bin/pest`.
Expected: no failures.

- [ ] **Step 9: Commit**

```bash
git add src/Filament src/Support/Ui.php resources/views-filament resources/lang tests/Filament tests/Feature/FilamentPluginTest.php
git commit -m "feat(filament): my reports page, translated navigation group with icons and badges, translated resource, panel colour bridge"
```

---

### Task 11: Standalone app bar, the dead-CSS guard, README and CHANGELOG

**Files:**
- Create: `resources/views/partials/standalone-header.blade.php`
- Modify: `resources/views/pages/board.blade.php`, `resources/views/pages/feedback.blade.php`
- Modify: `resources/dist/feedback-board.css`, both language files, `README.md`, `CHANGELOG.md`
- Test: `tests/Feature/StandalonePagesTest.php`, `tests/Unit/StylesheetCoverageTest.php`

**Interfaces:**
- Consumes: `fb-head` (+ `__titles`, `__title`, `__sub`) from Task 7, `kanban.subheading` from Task 10, the `home` icon from Task 5.
- Produces: partial `feedback-board::partials.standalone-header` (`['wide' => bool]`); CSS `fb-appbar` (+ `--wide`, `__inner`, `__name`, `__back`), `fb-standalone__main--wide`; removed `fb-standalone__main--narrow`, `fb-standalone__title`; the guard test `'styles nothing that no view uses'`.

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/StandalonePagesTest.php`:

1. In `'serves the reports page as a responsive standalone document…'`, replace `->and($html)->toContain('fb-standalone__main--narrow')` with `->and($html)->toContain('<main class="fb-standalone__main">')`.
2. In `'serves the board page with a heading and the stylesheet in its head'`, replace the `fb-standalone__title` line with:

```php
        ->and($html)->toContain('<h1 class="fb-head__title">'.__('feedback-board::feedback-board.board.title').'</h1>')
        ->and($html)->toContain(__('feedback-board::feedback-board.kanban.subheading'))
        ->and($html)->toContain('<main class="fb-standalone__main fb-standalone__main--wide">')
```

3. Append:

```php
it('gives both standalone pages an app bar with the app name and a way back', function () {
    config()->set('app.name', 'Acme Portal');
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);

    foreach ([route('feedback-board.page'), route('feedback-board.board.page')] as $url) {
        $html = $this->actingAs($dev)->get($url)->assertOk()->getContent();

        expect($html)->toContain('<span class="fb-appbar__name" dir="auto">Acme Portal</span>')
            ->and($html)->toContain('<a class="fb-appbar__back" href="'.url('/').'">')
            ->and($html)->toContain(__('feedback-board::feedback-board.standalone.back'))
            ->and(substr_count($html, '<h1'))->toBe(1);
    }
});
```

In `tests/Unit/StylesheetCoverageTest.php`, append:

```php
it('styles nothing that no view uses', function () {
    $views = [
        ...glob(__DIR__.'/../../resources/views/*/*.blade.php'),
        ...glob(__DIR__.'/../../resources/views-filament/*/*.blade.php'),
    ];
    $used = [];

    foreach ($views as $view) {
        preg_match_all('/(?<![\w-])fb-[a-z0-9_-]*[a-z0-9]/', (string) file_get_contents($view), $m);
        $used = [...$used, ...$m[0]];
    }

    // Built in the views from enum values and the avatar hash, so never literal there.
    $dynamic = [
        ...array_map(fn ($s) => 'fb-badge--'.$s->color(), FeedbackStatus::cases()),
        ...array_map(fn ($p) => 'fb-badge--'.$p->color(), FeedbackPriority::cases()),
        ...array_map(fn ($s) => 'fb-dot--'.$s->color(), FeedbackStatus::cases()),
        ...array_map(fn ($t) => 'fb-type-icon--'.$t->value, FeedbackType::cases()),
        ...array_map(fn ($n) => 'fb-avatar--'.$n, range(1, 4)),
    ];

    $unused = array_values(array_diff(fbStyledClasses(), array_unique($used), $dynamic));
    sort($unused);

    expect($unused)->toBe([]);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/StandalonePagesTest.php tests/Unit/StylesheetCoverageTest.php`
Expected: FAIL — no app bar, the board page still has `fb-standalone__title`, and the guard lists at least `fb-standalone__main--narrow` and `fb-standalone__title` (styled, used by no view once Step 3 lands).

- [ ] **Step 3: The app bar and the pages**

Create `resources/views/partials/standalone-header.blade.php`:

```blade
{{-- The standalone pages' app bar: the app's name and a way back. ['wide' => true] for the full-width board. --}}
<header @class(['fb-appbar', 'fb-appbar--wide' => $wide ?? false])>
    <div class="fb-appbar__inner">
        <span class="fb-appbar__name" dir="auto">{{ config('app.name') }}</span>
        <a class="fb-appbar__back" href="{{ url('/') }}"><x-feedback-board::icon name="home" />{{ __('feedback-board::feedback-board.standalone.back') }}</a>
    </div>
</header>
```

In `resources/views/pages/board.blade.php`, replace the `<main …>…</main>` element with:

```blade
    @include('feedback-board::partials.standalone-header', ['wide' => true])
    <main class="fb-standalone__main fb-standalone__main--wide">
        <header class="fb-head">
            <div class="fb-head__titles">
                <h1 class="fb-head__title">{{ __('feedback-board::feedback-board.board.title') }}</h1>
                <p class="fb-head__sub">{{ __('feedback-board::feedback-board.kanban.subheading') }}</p>
            </div>
        </header>
        @livewire('feedback-board::feedback-kanban')
    </main>
```

In `resources/views/pages/feedback.blade.php`, replace the `<main …>…</main>` element with (the component renders its own heading and "New report" button outside a panel):

```blade
    @include('feedback-board::partials.standalone-header')
    <main class="fb-standalone__main">
        @livewire('feedback-board::my-reports-board')
    </main>
```

- [ ] **Step 4: Stylesheet**

In `resources/dist/feedback-board.css`, replace the "Standalone pages" section (from `/* ---------- Standalone pages ---------- */` up to the "Filament action-modal views" comment) with:

```css
/* ---------- Standalone pages ---------- */

.fb-standalone {
    min-block-size: 100vh;
    min-block-size: 100dvh;
    margin: 0;
    background: var(--_fb-bg);
    font-family: var(--fb-font, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans", "Noto Sans Arabic", Tahoma, Arial, sans-serif);
}

.fb-appbar {
    border-block-end: 1px solid var(--_fb-border);
    background: var(--_fb-surface);
}

.fb-appbar__inner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    max-inline-size: 75rem;
    margin-inline: auto;
    padding: 0.75rem 1rem;
}

.fb-appbar__name {
    min-inline-size: 0;
    overflow: hidden;
    font-weight: 700;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.fb-appbar__back {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    color: var(--_fb-link);
    font-size: 0.8125rem;
    font-weight: 600;
    text-decoration: none;
    white-space: nowrap;
}

.fb-appbar__back:hover {
    text-decoration: underline;
}

.fb-appbar__back .fb-icon {
    inline-size: 1rem;
    block-size: 1rem;
}

.fb-standalone__main {
    display: grid;
    gap: 1.25rem;
    max-inline-size: 75rem;
    margin-inline: auto;
    padding: 1.5rem 1rem;
}

/* The board's six columns need the whole width, as in the panel. */
.fb-standalone__main--wide,
.fb-appbar--wide .fb-appbar__inner {
    max-inline-size: none;
}

@media (min-width: 40rem) {
    .fb-appbar__inner {
        padding-inline: 2rem;
    }

    .fb-standalone__main {
        padding: 2rem;
    }
}
```

- [ ] **Step 5: Translations**

In `resources/lang/en/feedback-board.php`, add before the final `];`: `'standalone' => ['back' => 'Back to the app'],`

In `resources/lang/ar/feedback-board.php`, add before the final `];`: `'standalone' => ['back' => 'العودة إلى التطبيق'],`

- [ ] **Step 6: Run the tests, and clear what the guard finds**

Run: `vendor/bin/pest tests/Feature/StandalonePagesTest.php tests/Unit/StylesheetCoverageTest.php tests/Feature/LocalizationTest.php`
Expected: PASS. If `'styles nothing that no view uses'` still lists classes, handle each one:
- `grep -rn '<class>' resources/views resources/views-filament` finds nothing → delete every rule for that class from the stylesheet (in a selector list, delete only that selector);
- a view builds it from a value (as with `fb-badge--{{ … }}`) → add it to `$dynamic`, built from the enum or the helper that produces it.

Re-run until the guard passes.

- [ ] **Step 7: README**

In `README.md`:

a) In "1. Add the launcher to your layout", replace `that opens the feedback form in a modal dialog.` with `that opens the report form in a panel sliding in from the side; reporters can add screenshots by picking, dropping or pasting them.`

b) In "Filament Integration", replace the paragraph that starts `This adds a Kanban-style Feedback Board page` with:

````markdown
This adds three entries to the panel's navigation, in a translated **Feedback** group:

| Entry | Who sees it | Badge |
|---|---|---|
| **My reports** (`/admin/my-feedback`) | every signed-in panel user | reports with unread replies |
| **Feedback board** (`/admin/feedback-board-page`) | developers | open reports |
| **All reports** (the feedback items resource) | developers | — |

Reporters follow their reports and reply, with images, without leaving the panel; developers triage on the Kanban and work each report in a card dialog. To keep "My reports" out of a panel, such as an admin-only one, turn it off for that panel:

```php
$panel->plugin(FeedbackBoardPlugin::make()->myReports(false));
```

**Colours.** Inside a panel the package follows the panel's primary colour, in light and dark mode, with readable text on it (white, or the colour's darkest shade for a light primary such as amber). Your own `--fb-accent` properties still win.

**Limitation.** The package uses the application's default auth guard. Reporters who sign in to a panel with its own guard (`->authGuard(...)`) are not supported yet.
````

c) In "Reporters who use a Filament panel", append: `The form's "My reports" link opens the panel's "My reports" page when the panel shows it.`

d) In "Styling & theming":
- in the first paragraph, replace `the Kanban board and the item drawers` with `the Kanban board, the slide-overs and the card dialog`;
- in the dark-mode table's `class` row, replace `The package's own standalone pages follow the operating system instead.` with `The package's own standalone pages have no host to set that class, so they stay light; use `system` to follow the operating system there.`;
- in the properties table, replace `panels, dialogs and drawers` with `panels, dialogs and slide-overs`, and `the dialog and drawers sit 10 above it` with `dialogs and slide-overs sit 10 above it, the image preview 20 above it`;
- replace the paragraph that starts `Inside a Filament panel the board follows the panel's dark mode` with `Inside a Filament panel the package follows the panel's dark mode and primary colour on its own (see [Filament Integration](#filament-integration)); the properties above still override it.`;
- in **Right-to-left**, replace `the launcher, drawers and spacing` with `the launcher, slide-overs, dialogs and spacing`.

e) In "Configuration Reference", add after the `attachments.allowed_mimes` row:

```markdown
| `attachments.max_files` | int | Most files per report and per comment (default: `10`) |
```

and directly after the table:

```markdown
**Attachments and images.** Reports and comments can both carry files. Comments (from reporters and developers, internal notes included) take images only: JPEG, PNG, GIF and WebP, within `allowed_mimes`. Those images show inline and in a preview; every other file, SVG included, downloads. An internal note's images are served to developers only. Deleting a comment deletes its files. Deleting a whole report removes its rows through the database cascade, which runs no model events, so its files stay on the disk under `attachments.directory/{report id}`; delete that directory yourself.
```

f) In "Webhook (signed)", append: `A reply that carries only images has an empty `body` (`""`).`

g) In "AI Triage", replace `in the Livewire detail panel or the Filament resource` with `in the board's card dialog or the Filament resource`.

- [ ] **Step 8: CHANGELOG**

In `CHANGELOG.md`, under `## [Unreleased]` → `### Added`, replace these bullets:

```markdown
- **In-app reporting.** The `<x-feedback-board::launcher />` component adds a floating button that opens a report form in a slide-over: a type, a title, details, and screenshots or files added by picking, dropping or pasting them. Reports capture the page URL, browser and locale.
- **My reports.** Reporters follow each report's progress, see which reports have unread replies, and talk with the support team in a chat-style conversation that takes images. Inside a Filament panel it is a page of the panel; elsewhere it is a standalone page.
- **Developer board.** A Kanban board with drag-and-drop status changes, type filters, and cards that show the report's screenshot as a cover. Each card opens a dialog where developers set the status, priority and assignee, browse every attachment, read the technical context, and reply to the reporter or leave internal notes, with images, next to a merged activity feed.
- **Filament plugin (optional).** For Filament 4 and 5: "My reports" for every panel user, the Kanban board and a reports resource, in a translated navigation group with icons and badges. Package screens follow the panel's primary colour and dark mode; `->myReports(false)` turns the reporter page off for a panel.
```

(they replace the old **In-app reporting**, **My reports**, **Developer board** and **Filament plugin (optional)** bullets), add after the **Destinations** bullet:

```markdown
- **Image-only replies.** A reply may carry only images. Its e-mail says that a reply with images was added, no GitHub comment is posted (GitHub rejects empty comments), and the webhook payload carries `body: ""`.
```

and append to the **Styling** bullet: ` The standalone pages are light by default; set `FEEDBACK_BOARD_THEME=system` to follow the operating system there.`

Under `### Upgrading from a pre-release install`:
- in the **UI markup** bullet, add the sub-bullet `- The report form's modal is now a slide-over, and the board's drawer a dialog.`;
- replace `- **Database.** The migrations are unchanged.` with:

```markdown
- **Database (required).** Publish and run the new migration: `php artisan vendor:publish --tag=feedback-board-migrations`, then `php artisan migrate`. It adds the reporter's read marker and last-activity time and marks every existing reply as read. Until it runs, sending a report, replying and moving a card on the board fail, and the "My reports" panel page returns a server error; the rest of the panel keeps working.
- **My reports in the panel.** "My reports" now appears for every user of every panel that registers the plugin. Call `->myReports(false)` on the plugin to turn it off for a panel.
- **Panel colour.** Inside a panel the accent follows the panel's primary colour. Your `--fb-accent` properties still win, and the manual `--fb-accent: var(--primary-600)` setup is no longer needed.
- **Standalone theme.** With `ui.theme` set to `class`, the standalone pages are now light, because no host page sets the `dark` class there. Set `FEEDBACK_BOARD_THEME=system` to follow the operating system as before.
```

- [ ] **Step 9: Run the whole suite**

Run: `vendor/bin/pest`
Expected: no failures.

- [ ] **Step 10: Commit**

```bash
git add resources/views resources/dist/feedback-board.css resources/lang README.md CHANGELOG.md tests/Feature/StandalonePagesTest.php tests/Unit/StylesheetCoverageTest.php
git commit -m "feat(ui): standalone app bar and dead-CSS guard; docs: panel pages, images in comments, upgrade steps"
```

---

### Task 12: Version lanes and the browser pass (controller, not a subagent task)

The PHP suite runs on one dependency set and cannot see layout, paste or drag. The controller runs this task after Task 11, from the worktree root. `$SCRATCH` is the session's scratchpad directory.

**Files:** none, unless a defect is found (then: the defect's files plus a test where it is testable).

- [ ] **Step 1: Run the suite on the CI dependency lanes**

```bash
lane() { # name, laravel, livewire, stability
  dir="$SCRATCH/lane-$1"
  rm -rf "$dir" && mkdir -p "$dir"
  rsync -a --exclude vendor --exclude .git --exclude composer.lock ./ "$dir"/
  (cd "$dir" \
    && composer require "laravel/framework:$2" "livewire/livewire:$3" --no-update --no-interaction \
    && COMPOSER_NO_SECURITY_BLOCKING=1 composer update "--$4" --prefer-dist --no-interaction --quiet \
    && composer show filament/filament livewire/livewire laravel/framework | grep -E '^(name|versions)' \
    && vendor/bin/pest)
}
lane l12-lw3 '12.*' '^3.8.3' prefer-stable
lane l12-lw4 '12.*' '^4.3.4' prefer-stable
lane l13-lw3 '13.*' '^3.8.3' prefer-stable
lane l12-lw3-lowest '12.*' '^3.8.3' prefer-lowest
lane l12-lw4-lowest '12.*' '^4.3.4' prefer-lowest
```

Expected: every lane ends with no failures; the Livewire 3 lanes resolve Filament 4 and the Livewire 4 lanes Filament 4 or 5. Record each lane's resolved versions and pass count for the final report. A failure that only one lane shows is a version difference: fix it in the worktree with a test that runs on every lane, then re-run that lane and the worktree suite.

- [ ] **Step 2: Build the browser app**

In `$SCRATCH/app`: a fresh Laravel 13 app that requires the package through a Composer `path` repository pointing at the worktree, plus `filament/filament:^5.0`, using SQLite and the `local` disk. Then:
- run `php artisan feedback-board:install` and `php artisan migrate`; confirm the update migration is published and ran;
- a panel `admin` with `->plugin(FeedbackBoardPlugin::make())`, `->colors(['primary' => Color::Pink])` and the launcher through `PanelsRenderHook::BODY_END`;
- a plain Blade page at `/` with the launcher (no Filament);
- two users, a developer and a reporter (the developer resolver checks the e-mail), and a locale switch (`?lang=ar` middleware setting `app()->setLocale()`);
- seeded reports for both users: every type, status and priority; a long unbroken English title; an English page URL in `meta`; covers from small screenshots and one 8–10 MB phone photo (any large JPEG: Pillow, ImageMagick, or a real photo); comments of every role, an internal note with an image, a reporter reply with images, and a non-image attachment.

A second app, `$SCRATCH/app-lw3`, is the same with `livewire/livewire:^3.8.3` and `filament/filament:^4.0`, for the paste check on Livewire 3.

- [ ] **Step 3: Check every surface in Chromium, at 1280 px and 390 px, in `en` and `ar`**

Use the `playwright-cli` skill. Compare each screen with the mockup (`docs/superpowers/specs/assets/2026-10-10-feedback-experience-mockup.html`): layout, hierarchy, copy, states. Save screenshots in `$SCRATCH/shots/`.

1. **Plain page, launcher and form.** The launcher pill sits at the inline-end corner (bottom-left in Arabic) and shows only its icon below 560 px. The slide-over opens from the inline-end edge, traps focus and closes on Escape and on the backdrop, returning focus to the launcher. The type cards show the accent ring and tint when selected.
2. **Paste (Review Focus 1).** In the form, dispatch one `paste` event carrying three unnamed image blobs (two PNG, one JPEG) through a `DataTransfer`. Expected: three uploads, in order, each exactly once (count the `/livewire/upload-file` requests); Send disabled until the last one finishes; names `screenshot-YYYYMMDD-HHMMSS.png` / `.jpg`; three image attachments on the sent report. Then queue a file over `max_kb` between two good ones: the bad one shows an error and the good ones still upload. Repeat the paste in Firefox, and in `$SCRATCH/app-lw3`.
3. **Sent state.** `#ID`, then **Follow the report** opens the panel's My reports page (it is enabled on the panel) with that report's slide-over open; closing it moves focus to the page heading.
4. **My reports (reporter).** Filters and their counts; the unread dot on a report with a new developer reply, which disappears once it is opened; the steps, or the dated banner for closed and declined reports; chat bubbles on the right sides in LTR and RTL; "new" tags; a reply with a pasted image; the lightbox: Escape closes the lightbox only, focus returns to the thumbnail, and its download link fires a `download` event.
5. **Board (developer, Review Focus 2).** Type filters; covers top-aligned at 16:9, and the 10 MB photo's card shows a cover without blocking the board; scrolling the column scroller requests off-screen covers only when they come near (watch the network log). Drag a card to another column: the status persists after a reload.
6. **Card dialog.** Cover band with the status select and the close button; "No priority" selectable; Assign to me / Unassign; the gallery with internal-note images and the file tile; technical context closed; triage and runner panels if configured. Composer: switching to Internal note tints it and changes the hint; post a note with a pasted image, then a reply; the board card's counts update without a reload. **Hide activity** persists after a reload. Escape closes the lightbox, then a second Escape closes the dialog. Below 920 px the dialog is full screen with one scrolling column.
7. **RTL overflow (Review Focus 3).** In Arabic at 390 px, on the board, the dialog and My reports with the long English title and URL: `document.documentElement.scrollWidth <= window.innerWidth`; titles render left-to-right inside right-to-left layouts; URLs stay LTR.
8. **Panel colours (Review Focus 4).** Pink and then amber primary (`Color::Amber`), each in light and in dark (`.dark`): primary buttons, the launcher, the selected filter tint, the unread dot and focus rings follow the panel colour; text on amber buttons is dark and readable. Override `--fb-accent` in the host CSS once and confirm it wins.
9. **Standalone pages.** `/feedback-board/feedback` and `/feedback-board/feedback/board` with the app bar: light with `ui.theme = class` under an emulated dark colour scheme; dark with `FEEDBACK_BOARD_THEME=system` under the same emulation.
10. **Before migrate (Review Focus 5).** In a copy of the app's database, roll back only the update migration (`php artisan migrate:rollback --step=1`) and open the panel's board and resource: both load, and `storage/logs/laravel.log` gains one warning per request at most.

- [ ] **Step 4: Fix what the pass finds**

Fix each defect in the worktree, with a test where the defect is testable, run `vendor/bin/pest`, and commit it as its own `fix(…)` commit (no attribution). Re-check the affected surfaces. Report anything left unfixed, with its screenshot, in the final summary instead of fixing it silently.
