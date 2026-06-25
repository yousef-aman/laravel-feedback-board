# Feedback Board MVP — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the self-hosted Laravel feedback-board package MVP — app users report issues in-app (description + screenshots + files), developers triage/resolve/reply via a gated board, with email + in-app notifications.

**Architecture:** Livewire-first domain core (Models/Actions/Events) that knows nothing about Filament. Livewire renders the shared client + developer UI; an optional Filament plugin is a thin adapter reusing the same Actions. A destinations driver layer (mail only in MVP) and an HMAC-gated `ingest` webhook are scaffolded but inactive, so Phase 2 (drivers/AI/hub) is a config upgrade, not a rewrite.

**Tech Stack:** PHP 8.2+, Laravel 11/12, Livewire 3, Filament 4/5 (optional), `spatie/laravel-package-tools`, Pest 3 + Orchestra Testbench 10.

## Global Constraints

- PHP `^8.2`; Laravel `^11.0 || ^12.0`; Livewire `^3.0` (required); `filament/filament` `^4.0 || ^5.0` (optional/dev).
- Package namespace: `YousefAman\FeedbackBoard\`; tests namespace: `YousefAman\FeedbackBoard\Tests\`.
- Composer name (working): `yousefaman/feedback-board`. License MIT. Author Yousef Aman.
- Built on `spatie/laravel-package-tools` (`PackageServiceProvider`), matching sibling packages.
- All DB tables prefixed `feedback_`. All config under `config('feedback-board.*')`.
- Reporter identity is ALWAYS server-derived from the authenticated user — never from client input.
- Models are guarded with explicit `$fillable`; Actions never use `$request->all()`.
- Attachment disk is private by default; downloads are authorization-gated, never public.
- Ingest endpoint is OFF by default and requires HMAC signature + timestamp/replay protection.
- Filament authorization must use `abort_unless`/policy in the action body (not only `->authorize()` UI hiding).
- i18n: `ar` + `en` lang files; client surface supports RTL. User-facing strings are translatable.
- Tests: Pest + Orchestra Testbench. TDD: failing test first, frequent commits.

---

## File Structure

| File | Responsibility |
|---|---|
| `composer.json` | package metadata, deps, autoload, provider discovery |
| `src/FeedbackBoardServiceProvider.php` | register config/migrations/views/routes/lang/Livewire/Gate |
| `config/feedback-board.php` | all package configuration |
| `src/Enums/{FeedbackStatus,FeedbackType,FeedbackPriority,ActivityAction,AuthorRole}.php` | typed domains |
| `database/migrations/*` | `feedback_items`, `feedback_comments`, `feedback_attachments`, `feedback_activities` |
| `src/Models/{FeedbackItem,FeedbackComment,FeedbackAttachment,FeedbackActivity}.php` | Eloquent models |
| `database/factories/*` | model factories for tests |
| `src/Actions/{CreateFeedbackItem,TransitionStatus,ReplyToFeedback,AssignFeedback}.php` | domain ops |
| `src/Events/{FeedbackItemCreated,FeedbackStatusChanged,FeedbackReplied}.php` | domain events |
| `src/Policies/FeedbackItemPolicy.php` | authorization |
| `src/Support/DeveloperRecipients.php` | resolve who gets developer notifications |
| `src/Services/AttachmentStorage.php` | validate + store + gated retrieval |
| `src/Http/Controllers/AttachmentController.php` | gated download |
| `src/Notifications/{NewFeedback,FeedbackReplied,FeedbackResolved}Notification.php` | mail + database |
| `src/Listeners/SendFeedbackNotifications.php` | events → notifications |
| `src/Destinations/{Contracts/Destination,DestinationManager,Drivers/MailDriver}.php` | outbound driver layer |
| `src/Listeners/DispatchToDestinations.php` | events → destinations (queued) |
| `src/Http/Middleware/VerifyIngestSignature.php` | HMAC + replay guard |
| `src/Http/Controllers/IngestController.php` | inbound webhook (off by default) |
| `src/Livewire/{FeedbackForm,MyReportsBoard,FeedbackItemModal,FeedbackKanban}.php` | UI |
| `resources/views/**` | Blade views + `components/launcher.blade.php` |
| `src/Concerns/HasFeedbackReports.php` | optional trait for host User model |
| `src/Filament/**` | optional Filament plugin/resource/page |
| `resources/lang/{ar,en}/feedback-board.php` | translations |
| `routes/web.php` | `/feedback`, attachment download, ingest |
| `tests/**` | Pest + Testbench |

---

## Task 1: Package scaffold & Testbench harness

**Files:**
- Create: `composer.json`, `src/FeedbackBoardServiceProvider.php`, `config/feedback-board.php`, `phpunit.xml.dist`, `tests/TestCase.php`, `tests/Pest.php`, `tests/ArchTest.php`, `.github/workflows/tests.yml`

**Interfaces:**
- Produces: `YousefAman\FeedbackBoard\FeedbackBoardServiceProvider`; `YousefAman\FeedbackBoard\Tests\TestCase` (base test case with package provider + in-memory sqlite + loaded package migrations).

- [ ] **Step 1: Write `composer.json`**

```json
{
    "name": "yousefaman/feedback-board",
    "description": "Self-hosted in-app feedback board for Laravel — clients report, developers resolve.",
    "keywords": ["laravel", "filament", "livewire", "feedback", "bug-report", "support"],
    "license": "MIT",
    "authors": [{ "name": "Yousef Aman", "role": "Developer" }],
    "require": {
        "php": "^8.2",
        "illuminate/contracts": "^11.0 || ^12.0",
        "livewire/livewire": "^3.0",
        "spatie/laravel-package-tools": "^1.15.0"
    },
    "require-dev": {
        "orchestra/testbench": "^9.0 || ^10.0",
        "pestphp/pest": "^3.0",
        "pestphp/pest-plugin-laravel": "^3.0",
        "filament/filament": "^4.0 || ^5.0"
    },
    "autoload": { "psr-4": { "YousefAman\\FeedbackBoard\\": "src/", "YousefAman\\FeedbackBoard\\Database\\Factories\\": "database/factories/" } },
    "autoload-dev": { "psr-4": { "YousefAman\\FeedbackBoard\\Tests\\": "tests/" } },
    "extra": { "laravel": { "providers": ["YousefAman\\FeedbackBoard\\FeedbackBoardServiceProvider"] } },
    "config": { "allow-plugins": { "pestphp/pest-plugin": true } },
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

- [ ] **Step 2: Write the minimal service provider**

```php
<?php

namespace YousefAman\FeedbackBoard;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FeedbackBoardServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('feedback-board')
            ->hasConfigFile()
            ->hasMigrations([]) // filled in Task 3
            ->hasTranslations()
            ->hasViews()
            ->hasRoute('web');
    }
}
```

- [ ] **Step 3: Create stub `config/feedback-board.php`, empty `routes/web.php`, `resources/lang/en/feedback-board.php` (`return [];`), and `resources/views/.gitkeep`**

```php
<?php // config/feedback-board.php
return [
    'project' => env('FEEDBACK_BOARD_PROJECT', 'default'),
];
```

- [ ] **Step 4: Write `tests/TestCase.php`**

```php
<?php

namespace YousefAman\FeedbackBoard\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use YousefAman\FeedbackBoard\FeedbackBoardServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [FeedbackBoardServiceProvider::class, \Livewire\LivewireServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
    }
}
```

- [ ] **Step 5: Write `phpunit.xml.dist`, `tests/Pest.php`, and a smoke test `tests/Smoke/BootTest.php`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!-- phpunit.xml.dist -->
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         bootstrap="vendor/autoload.php" colors="true">
    <testsuites>
        <testsuite name="Feedback Board Test Suite">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <source>
        <include><directory>src</directory></include>
    </source>
</phpunit>
```

```php
<?php // tests/Pest.php
uses(YousefAman\FeedbackBoard\Tests\TestCase::class)->in(__DIR__);
```

```php
<?php // tests/Smoke/BootTest.php
it('boots the package and loads config', function () {
    expect(config('feedback-board.project'))->toBe('default');
});
```

- [ ] **Step 6: Run the smoke test — expect PASS**

Run: `vendor/bin/pest tests/Smoke/BootTest.php`
Expected: PASS (1 passed). If `vendor/` absent, run `composer install` first.

- [ ] **Step 7: Add CI workflow `.github/workflows/tests.yml`**

```yaml
name: tests
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php: ['8.2', '8.3']
        laravel: ['11.*', '12.*']
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '${{ matrix.php }}' }
      - run: composer require "laravel/framework:${{ matrix.laravel }}" --no-update
      - run: composer update --prefer-dist --no-interaction
      - run: vendor/bin/pest
```

- [ ] **Step 8: Commit**

```bash
git add composer.json src config phpunit.xml.dist tests routes resources .github
git commit -m "feat: scaffold feedback-board package + testbench harness"
```

---

## Task 2: Enums

**Files:**
- Create: `src/Enums/FeedbackStatus.php`, `FeedbackType.php`, `FeedbackPriority.php`, `ActivityAction.php`, `AuthorRole.php`
- Test: `tests/Unit/EnumsTest.php`

**Interfaces:**
- Produces: `FeedbackStatus` (cases `New,InReview,InProgress,Resolved,Closed,Declined`; methods `label():string`, `color():string`, `isTerminal():bool`, static `default():self`); `FeedbackType` (`Bug,Feature,Question,Other`); `FeedbackPriority` (`Low,Medium,High,Urgent`); `ActivityAction` (`Created,StatusChanged,Replied,Assigned`); `AuthorRole` (`Reporter,Developer,Ai`). All string-backed.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Unit/EnumsTest.php
use YousefAman\FeedbackBoard\Enums\{FeedbackStatus, AuthorRole};

it('exposes status metadata and defaults', function () {
    expect(FeedbackStatus::default())->toBe(FeedbackStatus::New)
        ->and(FeedbackStatus::New->value)->toBe('new')
        ->and(FeedbackStatus::Resolved->isTerminal())->toBeTrue()
        ->and(FeedbackStatus::InProgress->isTerminal())->toBeFalse()
        ->and(FeedbackStatus::New->label())->toBeString();
});

it('distinguishes author roles', function () {
    expect(AuthorRole::Ai->value)->toBe('ai');
});
```

- [ ] **Step 2: Run — expect FAIL** (`Class "FeedbackStatus" not found`)

Run: `vendor/bin/pest tests/Unit/EnumsTest.php`

- [ ] **Step 3: Implement the enums**

```php
<?php // src/Enums/FeedbackStatus.php
namespace YousefAman\FeedbackBoard\Enums;

enum FeedbackStatus: string
{
    case New = 'new';
    case InReview = 'in_review';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case Declined = 'declined';

    public static function default(): self { return self::New; }

    public function label(): string
    {
        return __('feedback-board::feedback-board.status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'gray',
            self::InReview => 'info',
            self::InProgress => 'warning',
            self::Resolved => 'success',
            self::Closed => 'gray',
            self::Declined => 'danger',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Resolved, self::Closed, self::Declined], true);
    }
}
```

```php
<?php // src/Enums/FeedbackType.php
namespace YousefAman\FeedbackBoard\Enums;
enum FeedbackType: string {
    case Bug = 'bug'; case Feature = 'feature'; case Question = 'question'; case Other = 'other';
    public function label(): string { return __('feedback-board::feedback-board.type.'.$this->value); }
}
```

```php
<?php // src/Enums/FeedbackPriority.php
namespace YousefAman\FeedbackBoard\Enums;
enum FeedbackPriority: string {
    case Low = 'low'; case Medium = 'medium'; case High = 'high'; case Urgent = 'urgent';
    public function label(): string { return __('feedback-board::feedback-board.priority.'.$this->value); }
}
```

```php
<?php // src/Enums/ActivityAction.php
namespace YousefAman\FeedbackBoard\Enums;
enum ActivityAction: string {
    case Created = 'created'; case StatusChanged = 'status_changed';
    case Replied = 'replied'; case Assigned = 'assigned';
}
```

```php
<?php // src/Enums/AuthorRole.php
namespace YousefAman\FeedbackBoard\Enums;
enum AuthorRole: string { case Reporter = 'reporter'; case Developer = 'developer'; case Ai = 'ai'; }
```

- [ ] **Step 4: Run — expect PASS.** `vendor/bin/pest tests/Unit/EnumsTest.php`

- [ ] **Step 5: Commit**

```bash
git add src/Enums tests/Unit/EnumsTest.php
git commit -m "feat: add feedback domain enums"
```

---

## Task 3: Data layer (migrations + models + factories)

**Files:**
- Create: `database/migrations/2026_06_26_000001_create_feedback_items_table.php.stub` style — actual: `database/migrations/create_feedback_items_table.php.stub` (Testbench loads via `loadMigrationsFrom`). Use plain `database/migrations/*.php` and register with `->hasMigrations([...])`.
- Create: `src/Models/FeedbackItem.php`, `FeedbackComment.php`, `FeedbackAttachment.php`, `FeedbackActivity.php`
- Create: `database/factories/FeedbackItemFactory.php`, `FeedbackCommentFactory.php`
- Create: `tests/TestUser.php` (a host-app user model for morphs)
- Test: `tests/Unit/DataLayerTest.php`
- Modify: `src/FeedbackBoardServiceProvider.php` (register the 4 migrations)

**Interfaces:**
- Produces: `FeedbackItem` with `reporter():MorphTo`, `assignee():MorphTo`, `comments():HasMany`, `attachments():HasMany`, `activities():HasMany`; casts `type=>FeedbackType, status=>FeedbackStatus, priority=>FeedbackPriority, meta=>array, external_refs=>array, ai_state=>array, resolved_at=>datetime`; `$fillable` includes `project,reporter_type,reporter_id,reporter_name,reporter_email,title,description,type,status,priority,assignee_type,assignee_id,meta,external_refs,ai_state,resolved_at`.
- Produces: `FeedbackComment` (`item():BelongsTo`, `author():MorphTo`, casts `author_role=>AuthorRole, is_internal=>bool`); `FeedbackAttachment` (`item():BelongsTo`, `comment():BelongsTo`); `FeedbackActivity` (`item():BelongsTo`, `actor():MorphTo`, casts `action=>ActivityAction, properties=>array`, `from_status/to_status=>FeedbackStatus`).
- Produces: `FeedbackItem::factory()`, `FeedbackComment::factory()`, `YousefAman\FeedbackBoard\Tests\TestUser`.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Unit/DataLayerTest.php
use YousefAman\FeedbackBoard\Models\{FeedbackItem, FeedbackComment};
use YousefAman\FeedbackBoard\Enums\{FeedbackStatus, FeedbackType, AuthorRole};
use YousefAman\FeedbackBoard\Tests\TestUser;

it('persists an item with enum casts and morphed reporter', function () {
    $user = TestUser::create(['name' => 'Sara', 'email' => 's@x.com']);
    $item = FeedbackItem::factory()->for($user, 'reporter')->create([
        'type' => FeedbackType::Bug, 'status' => FeedbackStatus::New, 'meta' => ['url' => '/x'],
    ]);

    $fresh = $item->fresh();
    expect($fresh->status)->toBe(FeedbackStatus::New)
        ->and($fresh->type)->toBe(FeedbackType::Bug)
        ->and($fresh->meta)->toBe(['url' => '/x'])
        ->and($fresh->reporter->is($user))->toBeTrue();
});

it('threads comments under an item', function () {
    $item = FeedbackItem::factory()->create();
    $c = FeedbackComment::factory()->for($item, 'item')->create([
        'author_role' => AuthorRole::Developer, 'is_internal' => true,
    ]);
    expect($item->comments)->toHaveCount(1)
        ->and($item->comments->first()->is_internal)->toBeTrue()
        ->and($c->author_role)->toBe(AuthorRole::Developer);
});
```

- [ ] **Step 2: Run — expect FAIL** (`Class "FeedbackItem" not found`)

- [ ] **Step 3: Write the four migrations**

```php
<?php // database/migrations/create_feedback_items_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('feedback_items', function (Blueprint $t) {
            $t->id();
            $t->string('project')->default('default')->index();
            $t->nullableMorphs('reporter');
            $t->string('reporter_name')->nullable();
            $t->string('reporter_email')->nullable();
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('type')->default('bug');
            $t->string('status')->default('new')->index();
            $t->string('priority')->nullable();
            $t->nullableMorphs('assignee');
            $t->json('meta')->nullable();
            $t->json('external_refs')->nullable();
            $t->json('ai_state')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('feedback_items'); }
};
```

```php
<?php // database/migrations/create_feedback_comments_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('feedback_comments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('feedback_item_id')->constrained('feedback_items')->cascadeOnDelete();
            $t->nullableMorphs('author');
            $t->string('author_role')->default('developer');
            $t->text('body');
            $t->boolean('is_internal')->default(false)->index();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('feedback_comments'); }
};
```

```php
<?php // database/migrations/create_feedback_attachments_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('feedback_attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('feedback_item_id')->constrained('feedback_items')->cascadeOnDelete();
            $t->foreignId('feedback_comment_id')->nullable()->constrained('feedback_comments')->nullOnDelete();
            $t->string('disk');
            $t->string('path');
            $t->string('original_name');
            $t->string('mime')->nullable();
            $t->unsignedBigInteger('size')->default(0);
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('feedback_attachments'); }
};
```

```php
<?php // database/migrations/create_feedback_activities_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('feedback_activities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('feedback_item_id')->constrained('feedback_items')->cascadeOnDelete();
            $t->nullableMorphs('actor');
            $t->string('action');
            $t->string('from_status')->nullable();
            $t->string('to_status')->nullable();
            $t->json('properties')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }
    public function down(): void { Schema::dropIfExists('feedback_activities'); }
};
```

- [ ] **Step 4: Register migrations in the provider** (`src/FeedbackBoardServiceProvider.php`)

Replace `->hasMigrations([])` with:
```php
->hasMigrations([
    'create_feedback_items_table',
    'create_feedback_comments_table',
    'create_feedback_attachments_table',
    'create_feedback_activities_table',
])
```

- [ ] **Step 5: Write the models**

```php
<?php // src/Models/FeedbackItem.php
namespace YousefAman\FeedbackBoard\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{HasMany, MorphTo};
use YousefAman\FeedbackBoard\Database\Factories\FeedbackItemFactory;
use YousefAman\FeedbackBoard\Enums\{FeedbackPriority, FeedbackStatus, FeedbackType};

class FeedbackItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'project', 'reporter_type', 'reporter_id', 'reporter_name', 'reporter_email',
        'title', 'description', 'type', 'status', 'priority',
        'assignee_type', 'assignee_id', 'meta', 'external_refs', 'ai_state', 'resolved_at',
    ];

    protected $casts = [
        'type' => FeedbackType::class,
        'status' => FeedbackStatus::class,
        'priority' => FeedbackPriority::class,
        'meta' => 'array', 'external_refs' => 'array', 'ai_state' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function reporter(): MorphTo { return $this->morphTo(); }
    public function assignee(): MorphTo { return $this->morphTo(); }
    public function comments(): HasMany { return $this->hasMany(FeedbackComment::class); }
    public function attachments(): HasMany { return $this->hasMany(FeedbackAttachment::class); }
    public function activities(): HasMany { return $this->hasMany(FeedbackActivity::class)->latest('created_at'); }

    protected static function newFactory(): FeedbackItemFactory { return FeedbackItemFactory::new(); }
}
```

```php
<?php // src/Models/FeedbackComment.php
namespace YousefAman\FeedbackBoard\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, MorphTo};
use YousefAman\FeedbackBoard\Database\Factories\FeedbackCommentFactory;
use YousefAman\FeedbackBoard\Enums\AuthorRole;

class FeedbackComment extends Model
{
    use HasFactory;

    protected $fillable = ['feedback_item_id', 'author_type', 'author_id', 'author_role', 'body', 'is_internal'];
    protected $casts = ['author_role' => AuthorRole::class, 'is_internal' => 'bool'];

    public function item(): BelongsTo { return $this->belongsTo(FeedbackItem::class, 'feedback_item_id'); }
    public function author(): MorphTo { return $this->morphTo(); }

    protected static function newFactory(): FeedbackCommentFactory { return FeedbackCommentFactory::new(); }
}
```

```php
<?php // src/Models/FeedbackAttachment.php
namespace YousefAman\FeedbackBoard\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedbackAttachment extends Model
{
    protected $fillable = ['feedback_item_id', 'feedback_comment_id', 'disk', 'path', 'original_name', 'mime', 'size'];

    public function item(): BelongsTo { return $this->belongsTo(FeedbackItem::class, 'feedback_item_id'); }
    public function comment(): BelongsTo { return $this->belongsTo(FeedbackComment::class, 'feedback_comment_id'); }
}
```

```php
<?php // src/Models/FeedbackActivity.php
namespace YousefAman\FeedbackBoard\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, MorphTo};
use YousefAman\FeedbackBoard\Enums\{ActivityAction, FeedbackStatus};

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

    protected static function booted(): void
    {
        static::creating(fn ($a) => $a->created_at ??= now());
    }
}
```

- [ ] **Step 6: Write factories + the test user**

```php
<?php // database/factories/FeedbackItemFactory.php
namespace YousefAman\FeedbackBoard\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use YousefAman\FeedbackBoard\Enums\{FeedbackStatus, FeedbackType};
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class FeedbackItemFactory extends Factory
{
    protected $model = FeedbackItem::class;

    public function definition(): array
    {
        return [
            'project' => 'default',
            'reporter_name' => $this->faker->name(),
            'reporter_email' => $this->faker->safeEmail(),
            'title' => $this->faker->sentence(4),
            'description' => $this->faker->paragraph(),
            'type' => FeedbackType::Bug,
            'status' => FeedbackStatus::New,
            'meta' => [],
        ];
    }
}
```

```php
<?php // database/factories/FeedbackCommentFactory.php
namespace YousefAman\FeedbackBoard\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use YousefAman\FeedbackBoard\Enums\AuthorRole;
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};

class FeedbackCommentFactory extends Factory
{
    protected $model = FeedbackComment::class;

    public function definition(): array
    {
        return [
            'feedback_item_id' => FeedbackItem::factory(),
            'author_role' => AuthorRole::Reporter,
            'body' => $this->faker->sentence(),
            'is_internal' => false,
        ];
    }
}
```

```php
<?php // tests/TestUser.php
namespace YousefAman\FeedbackBoard\Tests;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class TestUser extends Authenticatable
{
    use Notifiable;
    protected $table = 'users';
    protected $guarded = [];
    public $timestamps = false;
}
```

- [ ] **Step 7: Add a `users` table + load package migrations for tests** — add this single method to `tests/TestCase.php` (this is the correct Testbench lifecycle hook; do NOT put `Schema::create` in the class body or `defineEnvironment`):

```php
protected function defineDatabaseMigrations(): void
{
    \Illuminate\Support\Facades\Schema::create('users', function ($t) {
        $t->id(); $t->string('name')->nullable(); $t->string('email')->nullable();
    });
    $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
}
```

- [ ] **Step 8: Run — expect PASS.** `vendor/bin/pest tests/Unit/DataLayerTest.php`

- [ ] **Step 9: Commit**

```bash
git add database src/Models src/FeedbackBoardServiceProvider.php tests
git commit -m "feat: feedback data layer (migrations, models, factories)"
```

---

## Task 4: CreateFeedbackItem action + FeedbackItemCreated event

**Files:**
- Create: `src/Events/FeedbackItemCreated.php`, `src/Actions/CreateFeedbackItem.php`
- Test: `tests/Feature/CreateFeedbackItemTest.php`

**Interfaces:**
- Consumes: `FeedbackItem`, `FeedbackStatus`, `FeedbackType`, `ActivityAction`.
- Produces: `FeedbackItemCreated(public FeedbackItem $item)`; `CreateFeedbackItem::handle(array $data, ?Model $reporter): FeedbackItem`. `$data` keys: `title,description,type(FeedbackType|string),meta(array),priority?`. The action sets `status=New`, snapshots reporter name/email, sets `project` from config, writes a `Created` activity, fires the event. It reads ONLY whitelisted keys — never the whole input.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/CreateFeedbackItemTest.php
use Illuminate\Support\Facades\Event;
use YousefAman\FeedbackBoard\Actions\CreateFeedbackItem;
use YousefAman\FeedbackBoard\Events\FeedbackItemCreated;
use YousefAman\FeedbackBoard\Enums\{ActivityAction, FeedbackStatus, FeedbackType};
use YousefAman\FeedbackBoard\Tests\TestUser;

it('creates an item, snapshots the reporter, logs activity, fires event', function () {
    Event::fake([FeedbackItemCreated::class]);
    $user = TestUser::create(['name' => 'Mona', 'email' => 'm@x.com']);

    $item = app(CreateFeedbackItem::class)->handle([
        'title' => 'Login fails', 'description' => 'sometimes',
        'type' => FeedbackType::Bug, 'meta' => ['url' => '/login'],
        'is_internal' => true, // must be ignored (not whitelisted)
    ], $user);

    expect($item->status)->toBe(FeedbackStatus::New)
        ->and($item->reporter->is($user))->toBeTrue()
        ->and($item->reporter_email)->toBe('m@x.com')
        ->and($item->project)->toBe('default')
        ->and($item->activities()->where('action', ActivityAction::Created)->exists())->toBeTrue();

    Event::assertDispatched(FeedbackItemCreated::class, fn ($e) => $e->item->is($item));
});
```

- [ ] **Step 2: Run — expect FAIL** (`Class "CreateFeedbackItem" not found`)

- [ ] **Step 3: Implement the event and action**

```php
<?php // src/Events/FeedbackItemCreated.php
namespace YousefAman\FeedbackBoard\Events;

use Illuminate\Foundation\Events\Dispatchable;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class FeedbackItemCreated
{
    use Dispatchable;
    public function __construct(public FeedbackItem $item) {}
}
```

```php
<?php // src/Actions/CreateFeedbackItem.php
namespace YousefAman\FeedbackBoard\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use YousefAman\FeedbackBoard\Enums\{ActivityAction, FeedbackStatus, FeedbackType};
use YousefAman\FeedbackBoard\Events\FeedbackItemCreated;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class CreateFeedbackItem
{
    public function handle(array $data, ?Model $reporter): FeedbackItem
    {
        $type = $data['type'] ?? FeedbackType::Bug;
        if (is_string($type)) { $type = FeedbackType::from($type); }

        return DB::transaction(function () use ($data, $reporter, $type) {
            $item = new FeedbackItem([
                'project' => config('feedback-board.project', 'default'),
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'type' => $type,
                'status' => FeedbackStatus::default(),
                'priority' => $data['priority'] ?? null,
                'meta' => $data['meta'] ?? [],
            ]);

            if ($reporter) {
                $item->reporter()->associate($reporter);
                $item->reporter_name = $reporter->name ?? null;
                $item->reporter_email = $reporter->email ?? null;
            }
            $item->save();

            $item->activities()->create([
                'actor_type' => $reporter?->getMorphClass(),
                'actor_id' => $reporter?->getKey(),
                'action' => ActivityAction::Created,
                'to_status' => FeedbackStatus::default(),
            ]);

            FeedbackItemCreated::dispatch($item);

            return $item;
        });
    }
}
```

- [ ] **Step 4: Run — expect PASS.** `vendor/bin/pest tests/Feature/CreateFeedbackItemTest.php`

- [ ] **Step 5: Commit**

```bash
git add src/Events/FeedbackItemCreated.php src/Actions/CreateFeedbackItem.php tests/Feature/CreateFeedbackItemTest.php
git commit -m "feat: CreateFeedbackItem action + created event"
```

---

## Task 5: TransitionStatus action + FeedbackStatusChanged event

**Files:**
- Create: `src/Events/FeedbackStatusChanged.php`, `src/Actions/TransitionStatus.php`
- Test: `tests/Feature/TransitionStatusTest.php`

**Interfaces:**
- Consumes: `FeedbackItem`, `FeedbackStatus`, `ActivityAction`.
- Produces: `FeedbackStatusChanged(public FeedbackItem $item, public FeedbackStatus $from, public FeedbackStatus $to, public ?Model $actor)`; `TransitionStatus::handle(FeedbackItem $item, FeedbackStatus $to, ?Model $actor): FeedbackItem`. Sets `resolved_at` when moving to `Resolved`, clears it when leaving a terminal state; writes a `StatusChanged` activity; fires the event; no-ops (no event, no activity) if `to === current`.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/TransitionStatusTest.php
use Illuminate\Support\Facades\Event;
use YousefAman\FeedbackBoard\Actions\TransitionStatus;
use YousefAman\FeedbackBoard\Events\FeedbackStatusChanged;
use YousefAman\FeedbackBoard\Enums\FeedbackStatus;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

it('transitions status, stamps resolved_at, logs activity, fires event', function () {
    Event::fake([FeedbackStatusChanged::class]);
    $item = FeedbackItem::factory()->create(['status' => FeedbackStatus::New]);

    app(TransitionStatus::class)->handle($item, FeedbackStatus::Resolved, null);

    $item->refresh();
    expect($item->status)->toBe(FeedbackStatus::Resolved)
        ->and($item->resolved_at)->not->toBeNull()
        ->and($item->activities()->where('to_status', FeedbackStatus::Resolved->value)->exists())->toBeTrue();
    Event::assertDispatched(FeedbackStatusChanged::class);
});

it('no-ops when transitioning to the same status', function () {
    Event::fake([FeedbackStatusChanged::class]);
    $item = FeedbackItem::factory()->create(['status' => FeedbackStatus::New]);
    app(TransitionStatus::class)->handle($item, FeedbackStatus::New, null);
    expect($item->activities()->count())->toBe(0);
    Event::assertNotDispatched(FeedbackStatusChanged::class);
});
```

- [ ] **Step 2: Run — expect FAIL**

- [ ] **Step 3: Implement**

```php
<?php // src/Events/FeedbackStatusChanged.php
namespace YousefAman\FeedbackBoard\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use YousefAman\FeedbackBoard\Enums\FeedbackStatus;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class FeedbackStatusChanged
{
    use Dispatchable;
    public function __construct(
        public FeedbackItem $item,
        public FeedbackStatus $from,
        public FeedbackStatus $to,
        public ?Model $actor,
    ) {}
}
```

```php
<?php // src/Actions/TransitionStatus.php
namespace YousefAman\FeedbackBoard\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use YousefAman\FeedbackBoard\Enums\{ActivityAction, FeedbackStatus};
use YousefAman\FeedbackBoard\Events\FeedbackStatusChanged;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class TransitionStatus
{
    public function handle(FeedbackItem $item, FeedbackStatus $to, ?Model $actor): FeedbackItem
    {
        $from = $item->status;
        if ($from === $to) { return $item; }

        return DB::transaction(function () use ($item, $from, $to, $actor) {
            $item->status = $to;
            $item->resolved_at = $to === FeedbackStatus::Resolved ? now() : ($to->isTerminal() ? $item->resolved_at : null);
            $item->save();

            $item->activities()->create([
                'actor_type' => $actor?->getMorphClass(),
                'actor_id' => $actor?->getKey(),
                'action' => ActivityAction::StatusChanged,
                'from_status' => $from,
                'to_status' => $to,
            ]);

            FeedbackStatusChanged::dispatch($item, $from, $to, $actor);

            return $item;
        });
    }
}
```

- [ ] **Step 4: Run — expect PASS.**

- [ ] **Step 5: Commit**

```bash
git add src/Events/FeedbackStatusChanged.php src/Actions/TransitionStatus.php tests/Feature/TransitionStatusTest.php
git commit -m "feat: TransitionStatus action + status-changed event"
```

---

## Task 6: ReplyToFeedback + AssignFeedback actions + FeedbackReplied event

**Files:**
- Create: `src/Events/FeedbackReplied.php`, `src/Actions/ReplyToFeedback.php`, `src/Actions/AssignFeedback.php`
- Test: `tests/Feature/ReplyAndAssignTest.php`

**Interfaces:**
- Consumes: `FeedbackItem`, `FeedbackComment`, `AuthorRole`, `ActivityAction`.
- Produces: `FeedbackReplied(public FeedbackComment $comment)`; `ReplyToFeedback::handle(FeedbackItem $item, string $body, ?Model $author, AuthorRole $role, bool $isInternal = false): FeedbackComment` (creates comment, logs `Replied` activity, fires event); `AssignFeedback::handle(FeedbackItem $item, ?Model $assignee, ?Model $actor): FeedbackItem` (sets/clears assignee, logs `Assigned` activity).

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/ReplyAndAssignTest.php
use Illuminate\Support\Facades\Event;
use YousefAman\FeedbackBoard\Actions\{AssignFeedback, ReplyToFeedback};
use YousefAman\FeedbackBoard\Events\FeedbackReplied;
use YousefAman\FeedbackBoard\Enums\{AuthorRole, ActivityAction};
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\TestUser;

it('adds a reply comment and fires event', function () {
    Event::fake([FeedbackReplied::class]);
    $item = FeedbackItem::factory()->create();
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);

    $comment = app(ReplyToFeedback::class)->handle($item, 'Fixed in build 12', $dev, AuthorRole::Developer);

    expect($comment->body)->toBe('Fixed in build 12')
        ->and($comment->author_role)->toBe(AuthorRole::Developer)
        ->and($comment->is_internal)->toBeFalse()
        ->and($item->activities()->where('action', ActivityAction::Replied)->exists())->toBeTrue();
    Event::assertDispatched(FeedbackReplied::class);
});

it('assigns and unassigns an item', function () {
    $item = FeedbackItem::factory()->create();
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    app(AssignFeedback::class)->handle($item, $dev, $dev);
    expect($item->refresh()->assignee->is($dev))->toBeTrue();
    app(AssignFeedback::class)->handle($item, null, $dev);
    expect($item->refresh()->assignee_id)->toBeNull();
});
```

- [ ] **Step 2: Run — expect FAIL**

- [ ] **Step 3: Implement**

```php
<?php // src/Events/FeedbackReplied.php
namespace YousefAman\FeedbackBoard\Events;

use Illuminate\Foundation\Events\Dispatchable;
use YousefAman\FeedbackBoard\Models\FeedbackComment;

class FeedbackReplied
{
    use Dispatchable;
    public function __construct(public FeedbackComment $comment) {}
}
```

```php
<?php // src/Actions/ReplyToFeedback.php
namespace YousefAman\FeedbackBoard\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use YousefAman\FeedbackBoard\Enums\{ActivityAction, AuthorRole};
use YousefAman\FeedbackBoard\Events\FeedbackReplied;
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};

class ReplyToFeedback
{
    public function handle(FeedbackItem $item, string $body, ?Model $author, AuthorRole $role, bool $isInternal = false): FeedbackComment
    {
        return DB::transaction(function () use ($item, $body, $author, $role, $isInternal) {
            $comment = $item->comments()->create([
                'author_type' => $author?->getMorphClass(),
                'author_id' => $author?->getKey(),
                'author_role' => $role,
                'body' => $body,
                'is_internal' => $isInternal,
            ]);

            $item->activities()->create([
                'actor_type' => $author?->getMorphClass(),
                'actor_id' => $author?->getKey(),
                'action' => ActivityAction::Replied,
                'properties' => ['is_internal' => $isInternal],
            ]);

            FeedbackReplied::dispatch($comment);

            return $comment;
        });
    }
}
```

```php
<?php // src/Actions/AssignFeedback.php
namespace YousefAman\FeedbackBoard\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use YousefAman\FeedbackBoard\Enums\ActivityAction;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class AssignFeedback
{
    public function handle(FeedbackItem $item, ?Model $assignee, ?Model $actor): FeedbackItem
    {
        return DB::transaction(function () use ($item, $assignee, $actor) {
            $assignee ? $item->assignee()->associate($assignee) : $item->assignee()->dissociate();
            $item->save();

            $item->activities()->create([
                'actor_type' => $actor?->getMorphClass(),
                'actor_id' => $actor?->getKey(),
                'action' => ActivityAction::Assigned,
                'properties' => ['assignee_id' => $assignee?->getKey()],
            ]);

            return $item;
        });
    }
}
```

- [ ] **Step 4: Run — expect PASS.**

- [ ] **Step 5: Commit**

```bash
git add src/Events/FeedbackReplied.php src/Actions/ReplyToFeedback.php src/Actions/AssignFeedback.php tests/Feature/ReplyAndAssignTest.php
git commit -m "feat: ReplyToFeedback + AssignFeedback actions"
```

---

## Task 7: Authorization (policy + developer gate + config)

**Files:**
- Create: `src/Policies/FeedbackItemPolicy.php`
- Modify: `config/feedback-board.php` (add `authorization` block), `src/FeedbackBoardServiceProvider.php` (register gate + policy)
- Test: `tests/Feature/AuthorizationTest.php`

**Interfaces:**
- Consumes: `FeedbackItem`.
- Produces: Gate ability `feedback-board.manage` (resolved from `config('feedback-board.authorization.developer_resolver')`, a callable `fn($user): bool`, default `fn () => false`); `FeedbackItemPolicy` with `viewAny($user):bool` (developer only), `view($user,$item):bool` (developer OR reporter owns it), `create($user):bool` (true when authenticated), `reply($user,$item):bool` (developer OR reporter), `transition($user,$item):bool` / `assign($user,$item):bool` / `update($user,$item):bool` (developer only). A helper `FeedbackItemPolicy::isDeveloper($user):bool` calls `Gate::allows('feedback-board.manage')`.

- [ ] **Step 1: Add config block** to `config/feedback-board.php`:

```php
'authorization' => [
    // Return true for users allowed to manage (triage/resolve) feedback.
    'developer_resolver' => null, // e.g. fn ($user) => $user->is_admin === true
],
```

- [ ] **Step 2: Write the failing test**

```php
<?php // tests/Feature/AuthorizationTest.php
use Illuminate\Support\Facades\Gate;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    config()->set('feedback-board.authorization.developer_resolver', fn ($u) => ($u->name ?? null) === 'Dev');
});

it('lets a developer manage but a reporter only see their own', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $client = TestUser::create(['name' => 'Client', 'email' => 'c@x.com']);
    $mine = FeedbackItem::factory()->for($client, 'reporter')->create();
    $others = FeedbackItem::factory()->create();
    $policy = new FeedbackItemPolicy();

    expect($policy->viewAny($dev))->toBeTrue()
        ->and($policy->viewAny($client))->toBeFalse()
        ->and($policy->view($client, $mine))->toBeTrue()
        ->and($policy->view($client, $others))->toBeFalse()
        ->and($policy->view($dev, $others))->toBeTrue()
        ->and($policy->transition($client, $mine))->toBeFalse();
});

it('registers the manage gate', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    expect(Gate::forUser($dev)->allows('feedback-board.manage'))->toBeTrue();
});
```

- [ ] **Step 3: Implement the policy**

```php
<?php // src/Policies/FeedbackItemPolicy.php
namespace YousefAman\FeedbackBoard\Policies;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class FeedbackItemPolicy
{
    public static function isDeveloper(?Model $user): bool
    {
        return $user !== null && Gate::forUser($user)->allows('feedback-board.manage');
    }

    protected function owns(?Model $user, FeedbackItem $item): bool
    {
        return $user !== null
            && $item->reporter_type === $user->getMorphClass()
            && (string) $item->reporter_id === (string) $user->getKey();
    }

    public function viewAny(?Model $user): bool { return self::isDeveloper($user); }
    public function create(?Model $user): bool { return $user !== null; }
    public function view(?Model $user, FeedbackItem $item): bool { return self::isDeveloper($user) || $this->owns($user, $item); }
    public function reply(?Model $user, FeedbackItem $item): bool { return self::isDeveloper($user) || $this->owns($user, $item); }
    public function transition(?Model $user, FeedbackItem $item): bool { return self::isDeveloper($user); }
    public function assign(?Model $user, FeedbackItem $item): bool { return self::isDeveloper($user); }
    public function update(?Model $user, FeedbackItem $item): bool { return self::isDeveloper($user); }
}
```

- [ ] **Step 4: Register the gate + policy in the provider** — add a `packageBooted()` method to `FeedbackBoardServiceProvider`:

```php
public function packageBooted(): void
{
    \Illuminate\Support\Facades\Gate::define('feedback-board.manage', function ($user) {
        $resolver = config('feedback-board.authorization.developer_resolver');
        return is_callable($resolver) ? (bool) $resolver($user) : false;
    });

    \Illuminate\Support\Facades\Gate::policy(
        \YousefAman\FeedbackBoard\Models\FeedbackItem::class,
        \YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy::class,
    );
}
```

- [ ] **Step 5: Run — expect PASS.** `vendor/bin/pest tests/Feature/AuthorizationTest.php`

- [ ] **Step 6: Commit**

```bash
git add src/Policies config/feedback-board.php src/FeedbackBoardServiceProvider.php tests/Feature/AuthorizationTest.php
git commit -m "feat: authorization policy + manage gate"
```

---

## Task 8: Attachments (storage service + validation + gated download)

**Files:**
- Create: `src/Services/AttachmentStorage.php`, `src/Http/Controllers/AttachmentController.php`
- Modify: `config/feedback-board.php` (add `attachments` block), `routes/web.php`
- Test: `tests/Feature/AttachmentTest.php`

**Interfaces:**
- Consumes: `FeedbackItem`, `FeedbackAttachment`, `FeedbackItemPolicy`.
- Produces: `AttachmentStorage::store(UploadedFile $file, FeedbackItem $item, ?FeedbackComment $comment = null): FeedbackAttachment` (validates mime+size against config, stores on private disk, returns record) — throws `Illuminate\Validation\ValidationException` on a disallowed file; `AttachmentStorage::allowedRules(): array` (returns the `file|mimes|max` rule array). Route name `feedback-board.attachments.show` → `AttachmentController@show` gated by `view` policy, streams the file.

- [ ] **Step 1: Add config block** to `config/feedback-board.php`:

```php
'attachments' => [
    'disk' => env('FEEDBACK_BOARD_DISK', 'local'), // private by default
    'directory' => 'feedback-board',
    'max_kb' => 10240, // 10 MB
    'allowed_mimes' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'log'],
],
```

- [ ] **Step 2: Write the failing test**

```php
<?php // tests/Feature/AttachmentTest.php
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Services\AttachmentStorage;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(fn () => Storage::fake('local'));

it('stores an allowed image on the private disk', function () {
    $item = FeedbackItem::factory()->create();
    $att = app(AttachmentStorage::class)->store(UploadedFile::fake()->image('shot.png'), $item);

    expect($att->feedback_item_id)->toBe($item->id)
        ->and($att->disk)->toBe('local');
    Storage::disk('local')->assertExists($att->path);
});

it('rejects a disallowed executable upload', function () {
    $item = FeedbackItem::factory()->create();
    app(AttachmentStorage::class)->store(UploadedFile::fake()->create('x.php', 10, 'application/x-php'), $item);
})->throws(ValidationException::class);

it('gates attachment downloads to authorized users', function () {
    config()->set('feedback-board.authorization.developer_resolver', fn ($u) => $u->name === 'Dev');
    Storage::fake('local');
    $client = TestUser::create(['name' => 'Client', 'email' => 'c@x.com']);
    $stranger = TestUser::create(['name' => 'Nope', 'email' => 'n@x.com']);
    $item = FeedbackItem::factory()->for($client, 'reporter')->create();
    $att = app(AttachmentStorage::class)->store(UploadedFile::fake()->image('s.png'), $item);

    $this->actingAs($stranger)->get(route('feedback-board.attachments.show', $att))->assertForbidden();
    $this->actingAs($client)->get(route('feedback-board.attachments.show', $att))->assertOk();
});
```

- [ ] **Step 3: Implement the storage service**

```php
<?php // src/Services/AttachmentStorage.php
namespace YousefAman\FeedbackBoard\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use YousefAman\FeedbackBoard\Models\{FeedbackAttachment, FeedbackComment, FeedbackItem};

class AttachmentStorage
{
    public function allowedRules(): array
    {
        $mimes = implode(',', config('feedback-board.attachments.allowed_mimes', []));
        $max = (int) config('feedback-board.attachments.max_kb', 10240);
        return ['file', 'mimes:'.$mimes, 'max:'.$max];
    }

    public function store(UploadedFile $file, FeedbackItem $item, ?FeedbackComment $comment = null): FeedbackAttachment
    {
        Validator::make(['file' => $file], ['file' => $this->allowedRules()])->validate();

        $disk = config('feedback-board.attachments.disk', 'local');
        $dir = config('feedback-board.attachments.directory', 'feedback-board').'/'.$item->getKey();
        $path = $file->store($dir, $disk);

        return $item->attachments()->create([
            'feedback_comment_id' => $comment?->getKey(),
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);
    }
}
```

- [ ] **Step 4: Implement the gated controller + route**

```php
<?php // src/Http/Controllers/AttachmentController.php
namespace YousefAman\FeedbackBoard\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use YousefAman\FeedbackBoard\Models\FeedbackAttachment;

class AttachmentController
{
    public function show(FeedbackAttachment $attachment)
    {
        abort_unless(
            app(\YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy::class)
                ->view(auth()->user(), $attachment->item),
            403
        );

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name);
    }
}
```

```php
<?php // routes/web.php  (append)
use Illuminate\Support\Facades\Route;
use YousefAman\FeedbackBoard\Http\Controllers\AttachmentController;

Route::middleware(config('feedback-board.route_middleware', ['web', 'auth']))
    ->prefix(config('feedback-board.route_prefix', 'feedback-board'))
    ->name('feedback-board.')
    ->group(function () {
        Route::get('attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');
    });
```

- [ ] **Step 5: Add the route config defaults** to `config/feedback-board.php`:

```php
'route_prefix' => 'feedback-board',
'route_middleware' => ['web', 'auth'],
```

- [ ] **Step 6: Run — expect PASS.** `vendor/bin/pest tests/Feature/AttachmentTest.php`

- [ ] **Step 7: Commit**

```bash
git add src/Services src/Http/Controllers/AttachmentController.php routes/web.php config/feedback-board.php tests/Feature/AttachmentTest.php
git commit -m "feat: attachment storage with validation + gated download"
```

---

## Task 9: Notifications + recipient resolver

**Files:**
- Create: `src/Support/DeveloperRecipients.php`, `src/Notifications/NewFeedbackNotification.php`, `FeedbackRepliedNotification.php`, `FeedbackResolvedNotification.php`, `src/Listeners/SendFeedbackNotifications.php`
- Modify: `config/feedback-board.php` (add `notifications` block), `src/FeedbackBoardServiceProvider.php` (register listener)
- Test: `tests/Feature/NotificationTest.php`

**Interfaces:**
- Consumes: events `FeedbackItemCreated`, `FeedbackReplied`, `FeedbackStatusChanged`; `AuthorRole`, `FeedbackStatus`, `FeedbackItemPolicy`.
- Produces: `DeveloperRecipients::resolve(): \Illuminate\Support\Collection` (notifiables, from `config('feedback-board.notifications.developers')` — an array of emails wrapped via `Notification::route('mail', ...)`, or a callable returning notifiables); `SendFeedbackNotifications` subscriber with `subscribe($events)`. Notifications implement `via()` = `['mail','database']`.

- [ ] **Step 1: Add config block** to `config/feedback-board.php`:

```php
'notifications' => [
    // array of emails OR a callable returning a Collection of notifiables
    'developers' => array_filter([env('FEEDBACK_BOARD_DEVELOPER_EMAIL')]),
    'channels' => ['mail', 'database'],
],
```

- [ ] **Step 2: Write the failing test**

```php
<?php // tests/Feature/NotificationTest.php
use Illuminate\Support\Facades\Notification;
use YousefAman\FeedbackBoard\Actions\{CreateFeedbackItem, ReplyToFeedback, TransitionStatus};
use YousefAman\FeedbackBoard\Enums\{AuthorRole, FeedbackStatus, FeedbackType};
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Notifications\{FeedbackRepliedNotification, FeedbackResolvedNotification, NewFeedbackNotification};
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    Notification::fake();
    config()->set('feedback-board.notifications.developers', ['dev@team.com']);
    config()->set('feedback-board.notifications.channels', ['mail']);
});

it('notifies developers on a new item', function () {
    app(CreateFeedbackItem::class)->handle(['title' => 'X', 'type' => FeedbackType::Bug], null);
    Notification::assertSentOnDemand(NewFeedbackNotification::class);
});

it('notifies the reporter when resolved and on developer reply', function () {
    $client = TestUser::create(['name' => 'C', 'email' => 'c@x.com']);
    $item = FeedbackItem::factory()->for($client, 'reporter')->create(['status' => FeedbackStatus::New]);

    app(TransitionStatus::class)->handle($item, FeedbackStatus::Resolved, null);
    Notification::assertSentTo($client, FeedbackResolvedNotification::class);

    app(ReplyToFeedback::class)->handle($item, 'done', null, AuthorRole::Developer);
    Notification::assertSentTo($client, FeedbackRepliedNotification::class);
});

it('does not notify the reporter for internal notes', function () {
    $client = TestUser::create(['name' => 'C', 'email' => 'c@x.com']);
    $item = FeedbackItem::factory()->for($client, 'reporter')->create();
    app(ReplyToFeedback::class)->handle($item, 'secret', null, AuthorRole::Developer, isInternal: true);
    Notification::assertNotSentTo($client, FeedbackRepliedNotification::class);
});

it('drops the database channel for on-demand (email-only) developer recipients', function () {
    config()->set('feedback-board.notifications.channels', ['mail', 'database']);
    $item = FeedbackItem::factory()->create();
    $onDemand = Notification::route('mail', 'dev@team.com'); // AnonymousNotifiable
    expect((new NewFeedbackNotification($item))->via($onDemand))->toBe(['mail']);
});

it('stores a Filament-format database payload when Filament is installed', function () {
    if (! class_exists(\Filament\Notifications\Notification::class)) {
        test()->markTestSkipped('Filament not installed');
    }
    $item = FeedbackItem::factory()->create(['title' => 'X']);
    $data = (new NewFeedbackNotification($item))->toArray(new \Illuminate\Notifications\AnonymousNotifiable());
    expect($data)->toHaveKey('format', 'filament')->and($data)->toHaveKey('feedback_item_id', $item->id);
});
```

- [ ] **Step 3: Implement the recipient resolver**

```php
<?php // src/Support/DeveloperRecipients.php
namespace YousefAman\FeedbackBoard\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

class DeveloperRecipients
{
    public function resolve(): Collection
    {
        $config = config('feedback-board.notifications.developers');
        if (is_callable($config)) {
            return collect($config());
        }
        return collect($config ?? [])->map(fn ($email) => Notification::route('mail', $email));
    }
}
```

- [ ] **Step 4: Implement the three notifications**

```php
<?php // src/Notifications/NewFeedbackNotification.php
namespace YousefAman\FeedbackBoard\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class NewFeedbackNotification extends Notification
{
    use Queueable;
    public function __construct(public FeedbackItem $item) {}

    public function via(object $notifiable): array
    {
        $channels = config('feedback-board.notifications.channels', ['mail']);
        // on-demand (email-only) recipients can't receive the database channel
        return $notifiable instanceof \Illuminate\Notifications\AnonymousNotifiable
            ? array_values(array_diff($channels, ['database']))
            : $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('feedback-board::feedback-board.mail.new_subject', ['title' => $this->item->title]))
            ->line(__('feedback-board::feedback-board.mail.new_line'))
            ->line($this->item->title);
    }

    public function toArray(object $notifiable): array
    {
        $payload = ['feedback_item_id' => $this->item->id, 'title' => $this->item->title, 'kind' => 'new'];

        // Filament's bell only renders rows carrying format:'filament' (see Global Constraints).
        if (class_exists(\Filament\Notifications\Notification::class)) {
            return \Filament\Notifications\Notification::make()
                ->title(__('feedback-board::feedback-board.mail.new_subject', ['title' => $this->item->title]))
                ->getDatabaseMessage() + $payload;
        }

        return $payload;
    }
}
```

```php
<?php // src/Notifications/FeedbackRepliedNotification.php
namespace YousefAman\FeedbackBoard\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use YousefAman\FeedbackBoard\Models\FeedbackComment;

class FeedbackRepliedNotification extends Notification
{
    use Queueable;
    public function __construct(public FeedbackComment $comment) {}

    public function via(object $notifiable): array
    {
        $channels = config('feedback-board.notifications.channels', ['mail']);
        // on-demand (email-only) recipients can't receive the database channel
        return $notifiable instanceof \Illuminate\Notifications\AnonymousNotifiable
            ? array_values(array_diff($channels, ['database']))
            : $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('feedback-board::feedback-board.mail.reply_subject'))
            ->line($this->comment->body);
    }

    public function toArray(object $notifiable): array
    {
        $payload = ['feedback_item_id' => $this->comment->feedback_item_id, 'kind' => 'reply'];

        if (class_exists(\Filament\Notifications\Notification::class)) {
            return \Filament\Notifications\Notification::make()
                ->title(__('feedback-board::feedback-board.mail.reply_subject'))
                ->getDatabaseMessage() + $payload;
        }

        return $payload;
    }
}
```

```php
<?php // src/Notifications/FeedbackResolvedNotification.php
namespace YousefAman\FeedbackBoard\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class FeedbackResolvedNotification extends Notification
{
    use Queueable;
    public function __construct(public FeedbackItem $item) {}

    public function via(object $notifiable): array
    {
        $channels = config('feedback-board.notifications.channels', ['mail']);
        // on-demand (email-only) recipients can't receive the database channel
        return $notifiable instanceof \Illuminate\Notifications\AnonymousNotifiable
            ? array_values(array_diff($channels, ['database']))
            : $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('feedback-board::feedback-board.mail.resolved_subject', ['title' => $this->item->title]))
            ->line(__('feedback-board::feedback-board.mail.resolved_line'));
    }

    public function toArray(object $notifiable): array
    {
        $payload = ['feedback_item_id' => $this->item->id, 'kind' => 'resolved'];

        if (class_exists(\Filament\Notifications\Notification::class)) {
            return \Filament\Notifications\Notification::make()
                ->title(__('feedback-board::feedback-board.mail.resolved_subject', ['title' => $this->item->title]))
                ->getDatabaseMessage() + $payload;
        }

        return $payload;
    }
}
```

- [ ] **Step 5: Implement the listener/subscriber**

```php
<?php // src/Listeners/SendFeedbackNotifications.php
namespace YousefAman\FeedbackBoard\Listeners;

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Notification;
use YousefAman\FeedbackBoard\Enums\{AuthorRole, FeedbackStatus};
use YousefAman\FeedbackBoard\Events\{FeedbackItemCreated, FeedbackReplied, FeedbackStatusChanged};
use YousefAman\FeedbackBoard\Notifications\{FeedbackRepliedNotification, FeedbackResolvedNotification, NewFeedbackNotification};
use YousefAman\FeedbackBoard\Support\DeveloperRecipients;

class SendFeedbackNotifications
{
    public function __construct(protected DeveloperRecipients $recipients) {}

    public function onCreated(FeedbackItemCreated $event): void
    {
        $devs = $this->recipients->resolve();
        if ($devs->isNotEmpty()) {
            Notification::send($devs, new NewFeedbackNotification($event->item));
        }
    }

    public function onReplied(FeedbackReplied $event): void
    {
        $comment = $event->comment;
        if ($comment->is_internal) { return; }
        $item = $comment->item;

        // developer reply -> notify reporter; reporter reply -> notify developers
        if ($comment->author_role === AuthorRole::Reporter) {
            $devs = $this->recipients->resolve();
            if ($devs->isNotEmpty()) {
                Notification::send($devs, new FeedbackRepliedNotification($comment));
            }
        } elseif ($item->reporter) {
            $item->reporter->notify(new FeedbackRepliedNotification($comment));
        }
    }

    public function onStatusChanged(FeedbackStatusChanged $event): void
    {
        if ($event->to === FeedbackStatus::Resolved && $event->item->reporter) {
            $event->item->reporter->notify(new FeedbackResolvedNotification($event->item));
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

- [ ] **Step 6: Register the subscriber** in `packageBooted()`:

```php
\Illuminate\Support\Facades\Event::subscribe(\YousefAman\FeedbackBoard\Listeners\SendFeedbackNotifications::class);
```

- [ ] **Step 7: Run — expect PASS.** `vendor/bin/pest tests/Feature/NotificationTest.php`

- [ ] **Step 8: Commit**

```bash
git add src/Support src/Notifications src/Listeners/SendFeedbackNotifications.php config/feedback-board.php src/FeedbackBoardServiceProvider.php tests/Feature/NotificationTest.php
git commit -m "feat: feedback notifications (new/reply/resolved) + recipient resolver"
```

---

## Task 10: Destinations driver layer (mail) + dispatch listener

**Files:**
- Create: `src/Destinations/Contracts/Destination.php`, `src/Destinations/DestinationManager.php`, `src/Destinations/Drivers/MailDriver.php`, `src/Listeners/DispatchToDestinations.php`
- Modify: `config/feedback-board.php` (add `destinations` block), `src/FeedbackBoardServiceProvider.php` (bind manager + subscribe listener)
- Test: `tests/Feature/DestinationsTest.php`

**Interfaces:**
- Consumes: `FeedbackItem`, events.
- Produces: `Destination` interface (`send(FeedbackItem $item, object $event): void`); `DestinationManager extends Illuminate\Support\Manager` with `getDefaultDriver()` from config and `createMailDriver(): MailDriver`; `MailDriver` (delegates to the existing notification path — a no-op marker in MVP that records dispatch); `DispatchToDestinations` listener that, for each enabled driver in `config('feedback-board.destinations.enabled')`, calls `send()`. MVP enables only `mail`.

- [ ] **Step 1: Add config block** to `config/feedback-board.php`:

```php
'destinations' => [
    'default' => 'mail',
    'enabled' => ['mail'], // Phase 2 adds: webhook, github, slack, trello, linear
],
```

- [ ] **Step 2: Write the failing test**

```php
<?php // tests/Feature/DestinationsTest.php
use YousefAman\FeedbackBoard\Destinations\DestinationManager;
use YousefAman\FeedbackBoard\Destinations\Drivers\MailDriver;

it('resolves the mail driver from the manager', function () {
    expect(app(DestinationManager::class)->driver('mail'))->toBeInstanceOf(MailDriver::class);
});

it('only enables mail in the MVP', function () {
    expect(config('feedback-board.destinations.enabled'))->toBe(['mail']);
});
```

- [ ] **Step 3: Implement contract, manager, driver**

```php
<?php // src/Destinations/Contracts/Destination.php
namespace YousefAman\FeedbackBoard\Destinations\Contracts;

use YousefAman\FeedbackBoard\Models\FeedbackItem;

interface Destination
{
    public function send(FeedbackItem $item, object $event): void;
}
```

```php
<?php // src/Destinations/DestinationManager.php
namespace YousefAman\FeedbackBoard\Destinations;

use Illuminate\Support\Manager;
use YousefAman\FeedbackBoard\Destinations\Drivers\MailDriver;

class DestinationManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return $this->config->get('feedback-board.destinations.default', 'mail');
    }

    public function createMailDriver(): MailDriver
    {
        return new MailDriver();
    }
}
```

```php
<?php // src/Destinations/Drivers/MailDriver.php
namespace YousefAman\FeedbackBoard\Destinations\Drivers;

use YousefAman\FeedbackBoard\Destinations\Contracts\Destination;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

/**
 * In the MVP, mail notifications are sent by SendFeedbackNotifications.
 * This driver exists so the destinations contract is wired end-to-end; Phase 2
 * adds webhook/github/slack/trello/linear drivers behind the same interface.
 */
class MailDriver implements Destination
{
    public function send(FeedbackItem $item, object $event): void
    {
        // Intentionally a no-op in MVP (notifications handle mail). Kept for contract parity.
    }
}
```

- [ ] **Step 4: Implement the dispatch listener**

```php
<?php // src/Listeners/DispatchToDestinations.php
namespace YousefAman\FeedbackBoard\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\Dispatcher;
use YousefAman\FeedbackBoard\Destinations\DestinationManager;
use YousefAman\FeedbackBoard\Events\{FeedbackItemCreated, FeedbackReplied, FeedbackStatusChanged};

class DispatchToDestinations implements ShouldQueue
{
    public function __construct(protected DestinationManager $manager) {}

    public function handle(object $event): void
    {
        $item = $event->item ?? $event->comment->item;
        foreach (config('feedback-board.destinations.enabled', []) as $driver) {
            $this->manager->driver($driver)->send($item, $event);
        }
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            FeedbackItemCreated::class => 'handle',
            FeedbackReplied::class => 'handle',
            FeedbackStatusChanged::class => 'handle',
        ];
    }
}
```

- [ ] **Step 5: Bind the manager as a singleton + subscribe** in `packageBooted()` / `packageRegistered()`:

```php
// in packageRegistered():
$this->app->singleton(\YousefAman\FeedbackBoard\Destinations\DestinationManager::class);
// in packageBooted():
\Illuminate\Support\Facades\Event::subscribe(\YousefAman\FeedbackBoard\Listeners\DispatchToDestinations::class);
```

- [ ] **Step 6: Run — expect PASS.** `vendor/bin/pest tests/Feature/DestinationsTest.php`

- [ ] **Step 7: Commit**

```bash
git add src/Destinations src/Listeners/DispatchToDestinations.php config/feedback-board.php src/FeedbackBoardServiceProvider.php tests/Feature/DestinationsTest.php
git commit -m "feat: destinations driver layer (mail) + dispatch listener"
```

---

## Task 11: Ingest webhook (HMAC-gated, off by default)

**Files:**
- Create: `src/Http/Middleware/VerifyIngestSignature.php`, `src/Http/Controllers/IngestController.php`
- Modify: `config/feedback-board.php` (add `ingest` block), `routes/web.php`
- Test: `tests/Feature/IngestTest.php`

**Interfaces:**
- Consumes: `FeedbackItem`, `TransitionStatus`, `ReplyToFeedback`, `AuthorRole`, `FeedbackStatus`.
- Produces: route `POST {prefix}/ingest` named `feedback-board.ingest`, behind `VerifyIngestSignature`. Middleware checks `config('feedback-board.ingest.enabled')` (404 when off), verifies header `X-Feedback-Signature` = `hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret)`, header `X-Feedback-Timestamp` within `tolerance` seconds, and a per-signature nonce cached for replay protection. Controller payload: `{item_id, action: 'comment'|'status'|'external_ref', body?, status?, ref?:{key,value}}`.

- [ ] **Step 1: Add config block** to `config/feedback-board.php`:

```php
'ingest' => [
    'enabled' => env('FEEDBACK_BOARD_INGEST_ENABLED', false),
    'secret' => env('FEEDBACK_BOARD_INGEST_SECRET'),
    'tolerance' => 300, // seconds
],
```

- [ ] **Step 2: Write the failing test**

```php
<?php // tests/Feature/IngestTest.php
use YousefAman\FeedbackBoard\Enums\FeedbackStatus;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

function signed(array $payload): array {
    $body = json_encode($payload);
    $ts = (string) now()->timestamp;
    $sig = hash_hmac('sha256', $ts.'.'.$body, 'shhh');
    return [$body, ['X-Feedback-Timestamp' => $ts, 'X-Feedback-Signature' => $sig, 'Content-Type' => 'application/json']];
}

beforeEach(function () {
    config()->set('feedback-board.ingest.enabled', true);
    config()->set('feedback-board.ingest.secret', 'shhh');
});

it('rejects unsigned ingest calls', function () {
    $item = FeedbackItem::factory()->create();
    $this->postJson(route('feedback-board.ingest'), ['item_id' => $item->id, 'action' => 'status', 'status' => 'resolved'])
        ->assertStatus(403);
});

it('applies a signed status change', function () {
    $item = FeedbackItem::factory()->create(['status' => FeedbackStatus::New]);
    [$body, $headers] = signed(['item_id' => $item->id, 'action' => 'status', 'status' => 'resolved']);
    $this->call('POST', route('feedback-board.ingest'), [], [], [], $this->transformHeadersToServerVars($headers), $body)
        ->assertOk();
    expect($item->refresh()->status)->toBe(FeedbackStatus::Resolved);
});

it('rejects a replayed signature', function () {
    $item = FeedbackItem::factory()->create();
    [$body, $headers] = signed(['item_id' => $item->id, 'action' => 'comment', 'body' => 'hi']);
    $server = $this->transformHeadersToServerVars($headers);
    $this->call('POST', route('feedback-board.ingest'), [], [], [], $server, $body)->assertOk();
    $this->call('POST', route('feedback-board.ingest'), [], [], [], $server, $body)->assertStatus(403);
});

it('returns 404 when ingest is disabled', function () {
    config()->set('feedback-board.ingest.enabled', false);
    [$body, $headers] = signed(['item_id' => 1, 'action' => 'comment', 'body' => 'x']);
    $this->call('POST', route('feedback-board.ingest'), [], [], [], $this->transformHeadersToServerVars($headers), $body)
        ->assertNotFound();
});
```

- [ ] **Step 3: Implement the middleware**

```php
<?php // src/Http/Middleware/VerifyIngestSignature.php
namespace YousefAman\FeedbackBoard\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class VerifyIngestSignature
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('feedback-board.ingest.enabled'), 404);

        $secret = (string) config('feedback-board.ingest.secret');
        $ts = (string) $request->header('X-Feedback-Timestamp', '');
        $sig = (string) $request->header('X-Feedback-Signature', '');
        abort_if($secret === '' || $ts === '' || $sig === '', 403);

        $tolerance = (int) config('feedback-board.ingest.tolerance', 300);
        abort_if(abs(now()->timestamp - (int) $ts) > $tolerance, 403, 'Stale timestamp');

        $expected = hash_hmac('sha256', $ts.'.'.$request->getContent(), $secret);
        abort_unless(hash_equals($expected, $sig), 403, 'Bad signature');

        // replay protection: a given signature may be used once within the window
        $nonceKey = 'feedback-board:ingest:'.$sig;
        abort_if(Cache::has($nonceKey), 403, 'Replay');
        Cache::put($nonceKey, true, $tolerance);

        return $next($request);
    }
}
```

- [ ] **Step 4: Implement the controller**

```php
<?php // src/Http/Controllers/IngestController.php
namespace YousefAman\FeedbackBoard\Http\Controllers;

use Illuminate\Http\Request;
use YousefAman\FeedbackBoard\Actions\{ReplyToFeedback, TransitionStatus};
use YousefAman\FeedbackBoard\Enums\{AuthorRole, FeedbackStatus};
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class IngestController
{
    public function __invoke(Request $request, TransitionStatus $transition, ReplyToFeedback $reply)
    {
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
}
```

- [ ] **Step 5: Register the route** — in `routes/web.php`, add a separate group (no `web`/`auth`, JSON + signature only):

```php
use YousefAman\FeedbackBoard\Http\Controllers\IngestController;
use YousefAman\FeedbackBoard\Http\Middleware\VerifyIngestSignature;

Route::middleware(['throttle:60,1', VerifyIngestSignature::class]) // rate-limit per §11
    ->prefix(config('feedback-board.route_prefix', 'feedback-board'))
    ->name('feedback-board.')
    ->post('ingest', IngestController::class)->name('ingest');
```

- [ ] **Step 6: Run — expect PASS.** `vendor/bin/pest tests/Feature/IngestTest.php`

- [ ] **Step 7: Commit**

```bash
git add src/Http/Middleware src/Http/Controllers/IngestController.php routes/web.php config/feedback-board.php tests/Feature/IngestTest.php
git commit -m "feat: HMAC-gated ingest webhook (off by default)"
```

---

## Task 12: Client Livewire — submission form + launcher

**Files:**
- Create: `src/Livewire/FeedbackForm.php`, `resources/views/livewire/feedback-form.blade.php`, `resources/views/components/launcher.blade.php`
- Modify: `src/FeedbackBoardServiceProvider.php` (register Livewire component + Blade component), `routes/web.php` (the `/feedback` page route comes in Task 13; the form is embedded)
- Test: `tests/Feature/FeedbackFormTest.php`

**Interfaces:**
- Consumes: `CreateFeedbackItem`, `AttachmentStorage`, `FeedbackType`.
- Produces: Livewire component alias `feedback-board::feedback-form` (class `FeedbackForm`) with public `$title,$description,$type,$files=[]` and a `submit()` method that authorizes `create`, calls `CreateFeedbackItem` with server-derived `auth()->user()` + auto-captured `meta`, stores uploaded files, resets the form, and emits `feedback-submitted`. Blade component `<x-feedback-board::launcher/>` renders a button that opens the form (Alpine modal).

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/FeedbackFormTest.php
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use YousefAman\FeedbackBoard\Enums\FeedbackType;
use YousefAman\FeedbackBoard\Livewire\FeedbackForm;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\TestUser;

it('submits feedback with the authenticated user as reporter', function () {
    Storage::fake('local');
    $user = TestUser::create(['name' => 'Lena', 'email' => 'l@x.com']);

    Livewire::actingAs($user)->test(FeedbackForm::class)
        ->set('title', 'Cannot export')
        ->set('type', FeedbackType::Feature->value)
        ->set('description', 'need excel')
        ->set('files', [UploadedFile::fake()->image('a.png')])
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('feedback-submitted');

    $item = FeedbackItem::first();
    expect($item->title)->toBe('Cannot export')
        ->and($item->reporter->is($user))->toBeTrue()
        ->and($item->attachments)->toHaveCount(1)
        ->and($item->meta)->toHaveKey('locale');
});

it('validates required title', function () {
    $user = TestUser::create(['name' => 'L', 'email' => 'l@x.com']);
    Livewire::actingAs($user)->test(FeedbackForm::class)->set('title', '')->call('submit')->assertHasErrors('title');
});
```

- [ ] **Step 2: Run — expect FAIL**

- [ ] **Step 3: Implement the component**

```php
<?php // src/Livewire/FeedbackForm.php
namespace YousefAman\FeedbackBoard\Livewire;

use Livewire\Component;
use Livewire\WithFileUploads;
use YousefAman\FeedbackBoard\Actions\CreateFeedbackItem;
use YousefAman\FeedbackBoard\Enums\FeedbackType;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Services\AttachmentStorage;

class FeedbackForm extends Component
{
    use WithFileUploads;

    public string $title = '';
    public string $description = '';
    public string $type = 'bug';
    public array $files = [];

    public function submit(CreateFeedbackItem $create, AttachmentStorage $storage): void
    {
        $this->authorize('create', FeedbackItem::class);

        $data = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['required', 'in:bug,feature,question,other'],
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

        foreach ($this->files as $file) {
            $storage->store($file, $item);
        }

        $this->reset('title', 'description', 'files');
        $this->dispatch('feedback-submitted', id: $item->id);
    }

    public function render()
    {
        return view('feedback-board::livewire.feedback-form');
    }
}
```

- [ ] **Step 4: Create the Blade views** (minimal, RTL-aware; strings via `__()`)

```blade
{{-- resources/views/livewire/feedback-form.blade.php --}}
<form wire:submit="submit" class="fb-form" dir="{{ __('feedback-board::feedback-board.dir') }}">
    <input type="text" wire:model="title" placeholder="{{ __('feedback-board::feedback-board.form.title') }}" />
    @error('title') <span class="fb-error">{{ $message }}</span> @enderror

    <select wire:model="type">
        <option value="bug">{{ __('feedback-board::feedback-board.type.bug') }}</option>
        <option value="feature">{{ __('feedback-board::feedback-board.type.feature') }}</option>
        <option value="question">{{ __('feedback-board::feedback-board.type.question') }}</option>
    </select>

    <textarea wire:model="description" placeholder="{{ __('feedback-board::feedback-board.form.description') }}"></textarea>
    <input type="file" wire:model="files" multiple />

    <button type="submit">{{ __('feedback-board::feedback-board.form.submit') }}</button>
</form>
```

```blade
{{-- resources/views/components/launcher.blade.php --}}
<div x-data="{ open: false }">
    <button type="button" @click="open = true" class="fb-launcher">
        {{ __('feedback-board::feedback-board.launcher') }}
    </button>
    <div x-show="open" x-cloak class="fb-modal">
        <div class="fb-modal__panel">
            <button type="button" @click="open = false" class="fb-modal__close">&times;</button>
            @livewire('feedback-board::feedback-form')
        </div>
    </div>
</div>
```

- [ ] **Step 5: Register components** — add to `packageBooted()`:

```php
\Livewire\Livewire::component('feedback-board::feedback-form', \YousefAman\FeedbackBoard\Livewire\FeedbackForm::class);
\Illuminate\Support\Facades\Blade::componentNamespace('YousefAman\\FeedbackBoard\\View\\Components', 'feedback-board');
// anonymous components in resources/views/components are auto-registered via ->hasViews('feedback-board')
```
Note: with `->hasViews()`, reference the launcher as `<x-feedback-board::launcher/>`.

- [ ] **Step 6: Add the translation keys used above** to `resources/lang/en/feedback-board.php` (and mirror in `ar` in Task 16):

```php
<?php
return [
    'dir' => 'ltr',
    'launcher' => 'Report an issue',
    'form' => ['title' => 'Title', 'description' => 'Describe the issue', 'submit' => 'Send'],
    'type' => ['bug' => 'Bug', 'feature' => 'Feature request', 'question' => 'Question', 'other' => 'Other'],
    'status' => ['new' => 'New', 'in_review' => 'In review', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'closed' => 'Closed', 'declined' => 'Declined'],
    'priority' => ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'urgent' => 'Urgent'],
    'mail' => [
        'new_subject' => 'New feedback: :title', 'new_line' => 'A new report was submitted.',
        'reply_subject' => 'New reply on your feedback',
        'resolved_subject' => 'Resolved: :title', 'resolved_line' => 'Your report was resolved.',
    ],
    'board' => ['my_reports' => 'My reports', 'reply' => 'Reply', 'internal_note' => 'Internal note'],
];
```

- [ ] **Step 7: Run — expect PASS.** `vendor/bin/pest tests/Feature/FeedbackFormTest.php`

- [ ] **Step 8: Commit**

```bash
git add src/Livewire/FeedbackForm.php resources/views src/FeedbackBoardServiceProvider.php resources/lang/en tests/Feature/FeedbackFormTest.php
git commit -m "feat: client feedback form + launcher component"
```

---

## Task 13: Client Livewire — "My reports" board + item thread/reply

**Files:**
- Create: `src/Livewire/MyReportsBoard.php`, `src/Livewire/FeedbackItemModal.php`, views `resources/views/livewire/my-reports-board.blade.php`, `feedback-item-modal.blade.php`, `resources/views/pages/feedback.blade.php`
- Modify: `src/FeedbackBoardServiceProvider.php` (register components), `routes/web.php` (the `/feedback` page)
- Test: `tests/Feature/MyReportsTest.php`

**Interfaces:**
- Consumes: `FeedbackItem`, `ReplyToFeedback`, `AuthorRole`, `FeedbackItemPolicy`.
- Produces: Livewire `feedback-board::my-reports-board` (lists `FeedbackItem` where reporter == auth user, newest first); `feedback-board::feedback-item-modal` with `reply(string $body)` that authorizes `reply` and calls `ReplyToFeedback` as `AuthorRole::Reporter`. Route `GET {prefix}/feedback` named `feedback-board.page` renders the page (auth required).

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/MyReportsTest.php
use Livewire\Livewire;
use YousefAman\FeedbackBoard\Enums\AuthorRole;
use YousefAman\FeedbackBoard\Livewire\{FeedbackItemModal, MyReportsBoard};
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\TestUser;

it('shows only the current user reports', function () {
    $me = TestUser::create(['name' => 'Me', 'email' => 'me@x.com']);
    FeedbackItem::factory()->for($me, 'reporter')->create(['title' => 'Mine']);
    FeedbackItem::factory()->create(['title' => 'Theirs']);

    Livewire::actingAs($me)->test(MyReportsBoard::class)
        ->assertSee('Mine')->assertDontSee('Theirs');
});

it('lets the reporter reply on their own item', function () {
    $me = TestUser::create(['name' => 'Me', 'email' => 'me@x.com']);
    $item = FeedbackItem::factory()->for($me, 'reporter')->create();

    Livewire::actingAs($me)->test(FeedbackItemModal::class, ['item' => $item])
        ->set('body', 'any update?')->call('reply')->assertHasNoErrors();

    expect($item->comments()->where('author_role', AuthorRole::Reporter->value)->exists())->toBeTrue();
});

it('opens a report thread from the list (modal reachable)', function () {
    $me = TestUser::create(['name' => 'Me', 'email' => 'me@x.com']);
    $item = FeedbackItem::factory()->for($me, 'reporter')->create(['title' => 'Mine']);

    Livewire::actingAs($me)->test(MyReportsBoard::class)
        ->call('open', $item->id)
        ->assertSet('selectedId', $item->id)
        ->assertSeeLivewire('feedback-board::feedback-item-modal');
});
```

- [ ] **Step 2: Run — expect FAIL**

- [ ] **Step 3: Implement the components**

```php
<?php // src/Livewire/MyReportsBoard.php
namespace YousefAman\FeedbackBoard\Livewire;

use Livewire\Attributes\On;
use Livewire\Component;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class MyReportsBoard extends Component
{
    public ?int $selectedId = null;

    #[On('feedback-submitted')]
    public function refreshList(): void { $this->selectedId = null; }

    public function open(int $id): void { $this->selectedId = $id; }

    public function render()
    {
        $user = auth()->user();
        $base = FeedbackItem::query() // scoped to the reporter — defense in depth on selection
            ->where('reporter_type', $user?->getMorphClass())
            ->where('reporter_id', $user?->getKey());

        $items = (clone $base)->latest()->get();
        $selected = $this->selectedId ? (clone $base)->whereKey($this->selectedId)->first() : null;

        return view('feedback-board::livewire.my-reports-board', ['items' => $items, 'selected' => $selected]);
    }
}
```

```php
<?php // src/Livewire/FeedbackItemModal.php
namespace YousefAman\FeedbackBoard\Livewire;

use Livewire\Component;
use YousefAman\FeedbackBoard\Actions\ReplyToFeedback;
use YousefAman\FeedbackBoard\Enums\AuthorRole;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class FeedbackItemModal extends Component
{
    public FeedbackItem $item;
    public string $body = '';

    public function mount(FeedbackItem $item): void
    {
        $this->item = $item;
    }

    public function reply(ReplyToFeedback $reply): void
    {
        $this->authorize('reply', $this->item);
        $this->validate(['body' => ['required', 'string']]);

        $reply->handle($this->item, $this->body, auth()->user(), AuthorRole::Reporter);
        $this->reset('body');
        $this->item->refresh();
    }

    public function render()
    {
        $comments = $this->item->comments()
            ->when(! \YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy::isDeveloper(auth()->user()),
                fn ($q) => $q->where('is_internal', false))
            ->oldest()->get();

        return view('feedback-board::livewire.feedback-item-modal', ['comments' => $comments]);
    }
}
```

- [ ] **Step 4: Create the views**

```blade
{{-- resources/views/livewire/my-reports-board.blade.php --}}
<div dir="{{ __('feedback-board::feedback-board.dir') }}">
    <h2>{{ __('feedback-board::feedback-board.board.my_reports') }}</h2>
    @livewire('feedback-board::feedback-form')
    <ul>
        @foreach ($items as $item)
            <li wire:key="item-{{ $item->id }}" wire:click="open({{ $item->id }})" style="cursor:pointer">
                <strong>{{ $item->title }}</strong>
                <span class="fb-badge fb-badge--{{ $item->status->color() }}">{{ $item->status->label() }}</span>
            </li>
        @endforeach
    </ul>

    @if ($selected)
        @livewire('feedback-board::feedback-item-modal', ['item' => $selected], key('modal-'.$selected->id))
    @endif
</div>
```

```blade
{{-- resources/views/livewire/feedback-item-modal.blade.php --}}
<div dir="{{ __('feedback-board::feedback-board.dir') }}">
    <h3>{{ $item->title }}</h3>
    <p>{{ $item->description }}</p>
    @foreach ($comments as $c)
        <div wire:key="c-{{ $c->id }}" class="fb-comment fb-comment--{{ $c->author_role->value }}">{{ $c->body }}</div>
    @endforeach
    <form wire:submit="reply">
        <textarea wire:model="body"></textarea>
        @error('body') <span class="fb-error">{{ $message }}</span> @enderror
        <button type="submit">{{ __('feedback-board::feedback-board.board.reply') }}</button>
    </form>
</div>
```

```blade
{{-- resources/views/pages/feedback.blade.php --}}
<!doctype html>
<html dir="{{ __('feedback-board::feedback-board.dir') }}">
<head><meta charset="utf-8"><title>{{ __('feedback-board::feedback-board.board.my_reports') }}</title>@livewireStyles</head>
<body>
    @livewire('feedback-board::my-reports-board')
    @livewireScripts
</body>
</html>
```

- [ ] **Step 5: Register components + the page route**

In `packageBooted()`:
```php
\Livewire\Livewire::component('feedback-board::my-reports-board', \YousefAman\FeedbackBoard\Livewire\MyReportsBoard::class);
\Livewire\Livewire::component('feedback-board::feedback-item-modal', \YousefAman\FeedbackBoard\Livewire\FeedbackItemModal::class);
```
In `routes/web.php` (inside the existing `web`+`auth` group):
```php
Route::view('feedback', 'feedback-board::pages.feedback')->name('page');
```

- [ ] **Step 6: Run — expect PASS.** `vendor/bin/pest tests/Feature/MyReportsTest.php`

- [ ] **Step 7: Commit**

```bash
git add src/Livewire resources/views src/FeedbackBoardServiceProvider.php routes/web.php tests/Feature/MyReportsTest.php
git commit -m "feat: my-reports board + item thread/reply for clients"
```

---

## Task 14: Developer Livewire surface — kanban board + item detail (no-Filament path)

**Files:**
- Create: `src/Livewire/FeedbackKanban.php`, `src/Livewire/FeedbackDetail.php`, `resources/views/livewire/feedback-kanban.blade.php`, `resources/views/livewire/feedback-detail.blade.php`, `resources/views/pages/board.blade.php`
- Modify: `src/FeedbackBoardServiceProvider.php` (register components), `routes/web.php` (gated `/feedback/board` route)
- Test: `tests/Feature/KanbanTest.php`, `tests/Feature/FeedbackDetailTest.php`

**Interfaces:**
- Consumes: `FeedbackItem`, `TransitionStatus`, `ReplyToFeedback`, `AssignFeedback`, `FeedbackStatus`, `FeedbackPriority`, `AuthorRole`, `FeedbackItemPolicy`.
- Produces: Livewire `feedback-board::feedback-kanban` (page-gated by route middleware) grouping items by status into columns; `moveTo(int $itemId, string $status)` authorizes `transition` and calls `TransitionStatus`; clicking a card dispatches `open-feedback` with the id. `feedback-board::feedback-detail` is a developer item-detail panel exposing `reply()` (role Developer), `internalNote()` (`ReplyToFeedback(..., isInternal: true)`), `assignToMe()`/`unassign()` (`AssignFeedback`), `setPriority(string)`, and `changeStatus(string)` — every write authorizes via the policy AND hard-guards with `abort_unless(FeedbackItemPolicy::isDeveloper(auth()->user()), 403)`. It renders the full thread (including internal notes), the activity timeline (`$item->activities()`), attachment download links, priority, and assignee. Route `GET {prefix}/feedback/board` named `feedback-board.board.page`, gated by `can:viewAny,YousefAman\FeedbackBoard\Models\FeedbackItem`.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/KanbanTest.php
use Livewire\Livewire;
use YousefAman\FeedbackBoard\Enums\FeedbackStatus;
use YousefAman\FeedbackBoard\Livewire\FeedbackKanban;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(fn () => config()->set('feedback-board.authorization.developer_resolver', fn ($u) => $u->name === 'Dev'));

it('forbids non-developers and lets a developer move a card', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $client = TestUser::create(['name' => 'Client', 'email' => 'c@x.com']);
    $item = FeedbackItem::factory()->create(['status' => FeedbackStatus::New]);

    Livewire::actingAs($client)->test(FeedbackKanban::class)->call('moveTo', $item->id, 'in_progress')->assertForbidden();

    Livewire::actingAs($dev)->test(FeedbackKanban::class)->call('moveTo', $item->id, 'in_progress')->assertOk();
    expect($item->refresh()->status)->toBe(FeedbackStatus::InProgress);
});

it('opens a card detail panel from the board', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $item = FeedbackItem::factory()->create(['title' => 'Card X']);
    Livewire::actingAs($dev)->test(FeedbackKanban::class)
        ->call('open', $item->id)
        ->assertSet('selectedId', $item->id)
        ->assertSeeLivewire('feedback-board::feedback-detail');
});
```

```php
<?php // tests/Feature/FeedbackDetailTest.php
use Livewire\Livewire;
use YousefAman\FeedbackBoard\Enums\{AuthorRole, FeedbackPriority, FeedbackStatus};
use YousefAman\FeedbackBoard\Livewire\FeedbackDetail;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(fn () => config()->set('feedback-board.authorization.developer_resolver', fn ($u) => $u->name === 'Dev'));

it('lets a developer reply, add an internal note, assign, set priority and status', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $item = FeedbackItem::factory()->create(['status' => FeedbackStatus::New]);

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->set('body', 'we are on it')->call('reply')->assertHasNoErrors()
        ->set('note', 'root cause: cache')->call('internalNote')->assertHasNoErrors()
        ->call('assignToMe')->assertHasNoErrors()
        ->call('setPriority', FeedbackPriority::High->value)->assertHasNoErrors()
        ->call('changeStatus', FeedbackStatus::InProgress->value)->assertHasNoErrors();

    $item->refresh();
    expect($item->comments()->where('author_role', AuthorRole::Developer->value)->where('is_internal', false)->exists())->toBeTrue()
        ->and($item->comments()->where('is_internal', true)->exists())->toBeTrue()
        ->and($item->assignee->is($dev))->toBeTrue()
        ->and($item->priority)->toBe(FeedbackPriority::High)
        ->and($item->status)->toBe(FeedbackStatus::InProgress)
        ->and($item->activities()->count())->toBeGreaterThan(0);
});

it('forbids a non-developer from opening the detail panel', function () {
    $client = TestUser::create(['name' => 'Client', 'email' => 'c@x.com']);
    $item = FeedbackItem::factory()->create();
    Livewire::actingAs($client);
    // mount() calls abort(403); Livewire re-throws on the initial render, so assert the throw directly.
    expect(fn () => Livewire::test(FeedbackDetail::class, ['item' => $item]))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});
```

- [ ] **Step 2: Run — expect FAIL**

- [ ] **Step 3: Implement the kanban, the detail panel, and the board page**

```php
<?php // src/Livewire/FeedbackKanban.php
namespace YousefAman\FeedbackBoard\Livewire;

use Livewire\Component;
use YousefAman\FeedbackBoard\Actions\TransitionStatus;
use YousefAman\FeedbackBoard\Enums\FeedbackStatus;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class FeedbackKanban extends Component
{
    public ?int $selectedId = null;

    // Page access is enforced by the route's `can:viewAny` middleware (Step 4); each
    // write re-authorizes below. No mount() guard — that keeps the component mountable
    // in tests (Livewire re-throws auth exceptions raw on initial render) while the
    // real gate lives on the route + the per-action authorize('transition').

    public function open(int $id): void { $this->selectedId = $id; }

    public function moveTo(int $itemId, string $status, TransitionStatus $transition): void
    {
        $item = FeedbackItem::findOrFail($itemId);
        $this->authorize('transition', $item);
        $transition->handle($item, FeedbackStatus::from($status), auth()->user());
    }

    public function render()
    {
        $columns = collect(FeedbackStatus::cases())->mapWithKeys(fn ($s) => [
            $s->value => FeedbackItem::where('status', $s->value)->latest()->get(),
        ]);
        $selected = $this->selectedId ? FeedbackItem::find($this->selectedId) : null;

        return view('feedback-board::livewire.feedback-kanban', ['columns' => $columns, 'selected' => $selected]);
    }
}
```

```php
<?php // src/Livewire/FeedbackDetail.php
namespace YousefAman\FeedbackBoard\Livewire;

use Livewire\Component;
use YousefAman\FeedbackBoard\Actions\{AssignFeedback, ReplyToFeedback, TransitionStatus};
use YousefAman\FeedbackBoard\Enums\{AuthorRole, FeedbackPriority, FeedbackStatus};
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy;

class FeedbackDetail extends Component
{
    public FeedbackItem $item;
    public string $body = '';
    public string $note = '';

    public function mount(FeedbackItem $item): void
    {
        abort_unless(FeedbackItemPolicy::isDeveloper(auth()->user()), 403); // hard enforcement (§11)
        $this->item = $item;
    }

    protected function guard(): void
    {
        abort_unless(FeedbackItemPolicy::isDeveloper(auth()->user()), 403);
    }

    public function reply(ReplyToFeedback $reply): void
    {
        $this->guard();
        $this->validate(['body' => ['required', 'string']]);
        $reply->handle($this->item, $this->body, auth()->user(), AuthorRole::Developer);
        $this->reset('body');
        $this->item->refresh();
    }

    public function internalNote(ReplyToFeedback $reply): void
    {
        $this->guard();
        $this->validate(['note' => ['required', 'string']]);
        $reply->handle($this->item, $this->note, auth()->user(), AuthorRole::Developer, isInternal: true);
        $this->reset('note');
        $this->item->refresh();
    }

    public function assignToMe(AssignFeedback $assign): void
    {
        $this->guard();
        $assign->handle($this->item, auth()->user(), auth()->user());
        $this->item->refresh();
    }

    public function unassign(AssignFeedback $assign): void
    {
        $this->guard();
        $assign->handle($this->item, null, auth()->user());
        $this->item->refresh();
    }

    public function setPriority(string $priority): void
    {
        $this->guard();
        $this->item->update(['priority' => FeedbackPriority::from($priority)]);
        $this->item->refresh();
    }

    public function changeStatus(string $status, TransitionStatus $transition): void
    {
        $this->guard();
        $transition->handle($this->item, FeedbackStatus::from($status), auth()->user());
        $this->item->refresh();
    }

    public function render()
    {
        return view('feedback-board::livewire.feedback-detail', [
            'comments' => $this->item->comments()->oldest()->get(), // developer sees internal too
            'activities' => $this->item->activities, // latest-first (relation default)
            'attachments' => $this->item->attachments,
            'statuses' => FeedbackStatus::cases(),
            'priorities' => FeedbackPriority::cases(),
        ]);
    }
}
```

```blade
{{-- resources/views/livewire/feedback-kanban.blade.php --}}
<div class="fb-kanban" dir="{{ __('feedback-board::feedback-board.dir') }}"
     x-data x-on:dragover.prevent="">
    @foreach ($columns as $status => $items)
        <div class="fb-col" data-status="{{ $status }}"
             x-on:drop="$wire.moveTo($event.dataTransfer.getData('id'), '{{ $status }}')">
            <h4>{{ \YousefAman\FeedbackBoard\Enums\FeedbackStatus::from($status)->label() }}</h4>
            @foreach ($items as $item)
                <div class="fb-card" wire:key="k-{{ $item->id }}"
                     wire:click="open({{ $item->id }})"
                     draggable="true" x-on:dragstart="$event.dataTransfer.setData('id', '{{ $item->id }}')">
                    {{ $item->title }}
                    @if ($item->priority)<span class="fb-prio">{{ $item->priority->label() }}</span>@endif
                </div>
            @endforeach
        </div>
    @endforeach

    @if ($selected)
        <div class="fb-detail-pane">
            @livewire('feedback-board::feedback-detail', ['item' => $selected], key('detail-'.$selected->id))
        </div>
    @endif
</div>
```

```blade
{{-- resources/views/livewire/feedback-detail.blade.php --}}
<div class="fb-detail" dir="{{ __('feedback-board::feedback-board.dir') }}">
    <h3>{{ $item->title }}</h3>
    <p>{{ $item->description }}</p>

    <div class="fb-detail__controls">
        <select wire:change="changeStatus($event.target.value)">
            @foreach ($statuses as $s)
                <option value="{{ $s->value }}" @selected($item->status === $s)>{{ $s->label() }}</option>
            @endforeach
        </select>
        <select wire:change="setPriority($event.target.value)">
            @foreach ($priorities as $p)
                <option value="{{ $p->value }}" @selected($item->priority === $p)>{{ $p->label() }}</option>
            @endforeach
        </select>
        @if ($item->assignee_id)
            <button type="button" wire:click="unassign">{{ __('feedback-board::feedback-board.board.unassign') }}</button>
        @else
            <button type="button" wire:click="assignToMe">{{ __('feedback-board::feedback-board.board.assign_me') }}</button>
        @endif
    </div>

    <h4>{{ __('feedback-board::feedback-board.board.attachments') }}</h4>
    <ul>
        @foreach ($attachments as $att)
            <li><a href="{{ route('feedback-board.attachments.show', $att) }}">{{ $att->original_name }}</a></li>
        @endforeach
    </ul>

    <h4>{{ __('feedback-board::feedback-board.board.thread') }}</h4>
    @foreach ($comments as $c)
        <div wire:key="dc-{{ $c->id }}" class="fb-comment fb-comment--{{ $c->author_role->value }} @if($c->is_internal) fb-comment--internal @endif">
            {{ $c->body }}
        </div>
    @endforeach

    <form wire:submit="reply">
        <textarea wire:model="body" placeholder="{{ __('feedback-board::feedback-board.board.reply') }}"></textarea>
        @error('body') <span class="fb-error">{{ $message }}</span> @enderror
        <button type="submit">{{ __('feedback-board::feedback-board.board.reply') }}</button>
    </form>

    <form wire:submit="internalNote">
        <textarea wire:model="note" placeholder="{{ __('feedback-board::feedback-board.board.internal_note') }}"></textarea>
        @error('note') <span class="fb-error">{{ $message }}</span> @enderror
        <button type="submit">{{ __('feedback-board::feedback-board.board.internal_note') }}</button>
    </form>

    <h4>{{ __('feedback-board::feedback-board.board.activity') }}</h4>
    <ul class="fb-activity">
        @foreach ($activities as $a)
            <li wire:key="act-{{ $a->id }}">{{ $a->action->value }} — {{ $a->created_at }}</li>
        @endforeach
    </ul>
</div>
```

```blade
{{-- resources/views/pages/board.blade.php --}}
<!doctype html>
<html dir="{{ __('feedback-board::feedback-board.dir') }}">
<head><meta charset="utf-8"><title>{{ __('feedback-board::feedback-board.board.title') }}</title>@livewireStyles</head>
<body>
    @livewire('feedback-board::feedback-kanban')
    @livewireScripts
</body>
</html>
```

- [ ] **Step 4: Register components + gated route + new translation keys**

In `packageBooted()`:
```php
\Livewire\Livewire::component('feedback-board::feedback-kanban', \YousefAman\FeedbackBoard\Livewire\FeedbackKanban::class);
\Livewire\Livewire::component('feedback-board::feedback-detail', \YousefAman\FeedbackBoard\Livewire\FeedbackDetail::class);
```
In `routes/web.php` (web+auth group):
```php
Route::view('feedback/board', 'feedback-board::pages.board')
    ->middleware('can:viewAny,YousefAman\\FeedbackBoard\\Models\\FeedbackItem')
    ->name('board.page');
```
Add these keys to the `board` array in `resources/lang/en/feedback-board.php` (mirror in `ar` in Task 16):
```php
'title' => 'Feedback board', 'thread' => 'Thread', 'activity' => 'Activity', 'attachments' => 'Attachments',
'assign_me' => 'Assign to me', 'unassign' => 'Unassign',
```

- [ ] **Step 5: Run — expect PASS.** `vendor/bin/pest tests/Feature/KanbanTest.php tests/Feature/FeedbackDetailTest.php`

- [ ] **Step 6: Commit**

```bash
git add src/Livewire resources/views resources/lang/en src/FeedbackBoardServiceProvider.php routes/web.php tests/Feature/KanbanTest.php tests/Feature/FeedbackDetailTest.php
git commit -m "feat: developer kanban + item-detail surface (reply/internal-note/assign/priority/status/activity)"
```

---

## Task 15: Filament plugin adapter (optional)

**Files:**
- Create: `src/Filament/FeedbackBoardPlugin.php`, `src/Filament/Resources/FeedbackItemResource.php`, `src/Filament/Resources/FeedbackItemResource/Pages/ListFeedbackItems.php`, `src/Filament/Pages/FeedbackBoardPage.php`
- Test: `tests/Feature/FilamentPluginTest.php`

**Interfaces:**
- Consumes: `FeedbackItem`, `FeedbackItemResource`, `FeedbackKanban` (Livewire), `FeedbackItemPolicy`.
- Produces: `FeedbackBoardPlugin implements Filament\Contracts\Plugin` registering BOTH `FeedbackItemResource` (a table list) and `FeedbackBoardPage` (a panel page embedding the Livewire `feedback-board::feedback-kanban`, which carries the full reply/internal-note/assign/priority/status/activity surface from Task 14). `FeedbackItemResource` defines `table()` (else ListRecords fatals) and `canViewAny()`; both the list page `mount()` and `FeedbackBoardPage` hard-enforce with `abort_unless(FeedbackItemPolicy::isDeveloper(auth()->user()), 403)` (not just `->authorize()`). The Filament/Tables API is verified on both v4 and v5.

- [ ] **Step 1: Write the failing test** (skips when Filament absent)

```php
<?php // tests/Feature/FilamentPluginTest.php
use YousefAman\FeedbackBoard\Filament\FeedbackBoardPlugin;
use YousefAman\FeedbackBoard\Filament\Resources\FeedbackItemResource;

it('exposes a Filament plugin wiring the resource', function () {
    if (! class_exists(\Filament\Contracts\Plugin::class)) {
        $this->markTestSkipped('Filament not installed');
    }
    $plugin = FeedbackBoardPlugin::make();
    expect($plugin->getId())->toBe('feedback-board')
        ->and(FeedbackItemResource::getModel())->toBe(\YousefAman\FeedbackBoard\Models\FeedbackItem::class)
        ->and(method_exists(FeedbackItemResource::class, 'table'))->toBeTrue()
        ->and(class_exists(\YousefAman\FeedbackBoard\Filament\Pages\FeedbackBoardPage::class))->toBeTrue();
});
```

- [ ] **Step 2: Run — expect FAIL** (`Class "FeedbackBoardPlugin" not found`)

- [ ] **Step 3: Implement plugin + resource skeleton**

```php
<?php // src/Filament/FeedbackBoardPlugin.php
namespace YousefAman\FeedbackBoard\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use YousefAman\FeedbackBoard\Filament\Resources\FeedbackItemResource;

class FeedbackBoardPlugin implements Plugin
{
    public function getId(): string { return 'feedback-board'; }
    public static function make(): static { return app(static::class); }

    public function register(Panel $panel): void
    {
        $panel->resources([FeedbackItemResource::class])
            ->pages([\YousefAman\FeedbackBoard\Filament\Pages\FeedbackBoardPage::class]);
    }

    public function boot(Panel $panel): void {}
}
```

```php
<?php // src/Filament/Resources/FeedbackItemResource.php
namespace YousefAman\FeedbackBoard\Filament\Resources;

use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use YousefAman\FeedbackBoard\Filament\Resources\FeedbackItemResource\Pages\ListFeedbackItems;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy;

class FeedbackItemResource extends Resource
{
    protected static ?string $model = FeedbackItem::class;

    public static function canViewAny(): bool
    {
        return FeedbackItemPolicy::isDeveloper(auth()->user());
    }

    public static function table(Table $table): Table
    {
        // Without table() a ListRecords page fatals at render. (Optionally have the
        // enums implement Filament\Support\Contracts\HasLabel + HasColor for nicer badges.)
        return $table->columns([
            TextColumn::make('title')->searchable()->limit(40),
            TextColumn::make('type')->badge(),
            TextColumn::make('status')->badge(),
            TextColumn::make('priority')->badge()->toggleable(),
            TextColumn::make('reporter_name')->toggleable(),
            TextColumn::make('created_at')->dateTime()->sortable(),
        ])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListFeedbackItems::route('/')];
    }
}
```

```php
<?php // src/Filament/Pages/FeedbackBoardPage.php
namespace YousefAman\FeedbackBoard\Filament\Pages;

use Filament\Pages\Page;
use YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy;

class FeedbackBoardPage extends Page
{
    protected static ?string $navigationLabel = 'Feedback board';
    protected static string $view = 'feedback-board::filament.board-page';

    public static function canAccess(): bool
    {
        return FeedbackItemPolicy::isDeveloper(auth()->user());
    }

    public function mount(): void
    {
        abort_unless(FeedbackItemPolicy::isDeveloper(auth()->user()), 403); // hard enforcement
    }
}
```

```blade
{{-- resources/views/filament/board-page.blade.php --}}
<x-filament-panels::page>
    @livewire('feedback-board::feedback-kanban')
</x-filament-panels::page>
```

```php
<?php // src/Filament/Resources/FeedbackItemResource/Pages/ListFeedbackItems.php
namespace YousefAman\FeedbackBoard\Filament\Resources\FeedbackItemResource\Pages;

use Filament\Resources\Pages\ListRecords;
use YousefAman\FeedbackBoard\Filament\Resources\FeedbackItemResource;
use YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy;

class ListFeedbackItems extends ListRecords
{
    protected static string $resource = FeedbackItemResource::class;

    public function mount(): void
    {
        abort_unless(FeedbackItemPolicy::isDeveloper(auth()->user()), 403); // hard enforcement
        parent::mount();
    }
}
```

- [ ] **Step 4: Run — expect PASS (or skipped if Filament absent).** `vendor/bin/pest tests/Feature/FilamentPluginTest.php`

- [ ] **Step 5: Commit**

```bash
git add src/Filament resources/views/filament tests/Feature/FilamentPluginTest.php
git commit -m "feat: optional Filament plugin (resource table + board page) with hard authorization"
```

---

## Task 16: i18n (Arabic + RTL), config publishing, README

**Files:**
- Create: `resources/lang/ar/feedback-board.php`, `README.md`
- Modify: `src/FeedbackBoardServiceProvider.php` (publishables), `config/feedback-board.php` (locale note)
- Test: `tests/Feature/LocalizationTest.php`

**Interfaces:**
- Consumes: translation files.
- Produces: full `ar` translations with `'dir' => 'rtl'`; publishable groups `feedback-board-config`, `feedback-board-migrations`, `feedback-board-views`, `feedback-board-translations`.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/LocalizationTest.php
it('serves Arabic strings and RTL direction', function () {
    app()->setLocale('ar');
    expect(__('feedback-board::feedback-board.dir'))->toBe('rtl')
        ->and(__('feedback-board::feedback-board.status.resolved'))->not->toBe('feedback-board::feedback-board.status.resolved');
});
```

- [ ] **Step 2: Run — expect FAIL**

- [ ] **Step 3: Create `resources/lang/ar/feedback-board.php`**

```php
<?php
return [
    'dir' => 'rtl',
    'launcher' => 'الإبلاغ عن مشكلة',
    'form' => ['title' => 'العنوان', 'description' => 'صف المشكلة', 'submit' => 'إرسال'],
    'type' => ['bug' => 'خلل', 'feature' => 'طلب ميزة', 'question' => 'سؤال', 'other' => 'أخرى'],
    'status' => ['new' => 'جديد', 'in_review' => 'قيد المراجعة', 'in_progress' => 'قيد العمل', 'resolved' => 'تم الحل', 'closed' => 'مغلق', 'declined' => 'مرفوض'],
    'priority' => ['low' => 'منخفضة', 'medium' => 'متوسطة', 'high' => 'عالية', 'urgent' => 'عاجلة'],
    'mail' => [
        'new_subject' => 'ملاحظة جديدة: :title', 'new_line' => 'تم رفع ملاحظة جديدة.',
        'reply_subject' => 'رد جديد على ملاحظتك',
        'resolved_subject' => 'تم الحل: :title', 'resolved_line' => 'تم حل ملاحظتك.',
    ],
    'board' => [
        'my_reports' => 'بلاغاتي', 'reply' => 'رد', 'internal_note' => 'ملاحظة داخلية',
        'title' => 'لوحة الملاحظات', 'thread' => 'النقاش', 'activity' => 'النشاط', 'attachments' => 'المرفقات',
        'assign_me' => 'إسناد لي', 'unassign' => 'إلغاء الإسناد',
    ],
];
```

- [ ] **Step 4: Add publishables** in `configurePackage()` (laravel-package-tools auto-publishes config/migrations/views/translations via the `hasX()` calls; verify `php artisan vendor:publish --tag=feedback-board-translations` works). No code change needed beyond the existing `hasConfigFile()/hasMigrations()/hasViews()/hasTranslations()`.

- [ ] **Step 5: Write `README.md`** with: install (`composer require yousefaman/feedback-board`), publish/migrate, add `<x-feedback-board::launcher/>` to the layout, set `feedback-board.authorization.developer_resolver`, set `notifications.developers`, and the **positioning paragraph** from the spec (self-hosted, in their own session, your data, your runner). Note Phase 2 (destinations drivers, AI A/B/C, hub) as roadmap.

- [ ] **Step 6: Run — expect PASS.** `vendor/bin/pest tests/Feature/LocalizationTest.php`

- [ ] **Step 7: Run the FULL suite — expect all green.**

Run: `vendor/bin/pest`
Expected: all tests pass (Filament test passes or is skipped).

- [ ] **Step 8: Commit**

```bash
git add resources/lang/ar README.md src/FeedbackBoardServiceProvider.php config/feedback-board.php tests/Feature/LocalizationTest.php
git commit -m "feat: arabic/RTL localization, publishing, README"
```

---

## Self-Review (plan author + adversarial verification pass)

- **Spec coverage:** §4 architecture → Tasks 1–16; §5 data model → Task 3; §6a client surface → Tasks 12–13; §6b developer surface (reply / internal note / assign / priority / status / **activity log** / **attachments**) → Task 14 (`FeedbackDetail`) + Task 15 (Filament resource table + `FeedbackBoardPage`); §7 workflow/status → Tasks 2,5; §8 notifications incl. Filament `format:'filament'` bell → Task 9; §9 destinations+ingest → Tasks 10,11; §11 security → Tasks 7,8,11,14,15; §12 i18n/RTL → Tasks 12,16; §13 config → Tasks 1,7,8,9,10,11; §14 testing → every task. §10 AI A/B/C and the Phase-2 active drivers/hub are intentionally out of this plan (separate Phase-2 spec→plan).
- **Adversarial verification:** a 5-critic + synthesis workflow checked this plan against the spec. It found 4 blockers (developer write-surface half-built; Filament bell wrong payload format; Kanban `mount()` authorize breaking its own test; client list never opened the thread) plus majors/minors. ALL were applied: Task 14 now ships `FeedbackDetail` (reply/internal-note/assign/priority/status + activity + attachments); Task 9 notifications are notifiable-aware and emit `format:'filament'`; Task 13 wires the list→modal; Task 15 adds `table()` + a real `FeedbackBoardPage`; Tasks 3/11/1 minors fixed (Testbench hook, ingest validation/throttle, phpunit.xml.dist).
- **Placeholder scan:** none — every code step contains concrete code; no TBD/TODO.
- **Type consistency:** action signatures (`handle(...)`), event constructor props (`$item`/`$comment`/`$from`/`$to`/`$actor`), enum case names, Livewire aliases (`feedback-board::feedback-form|my-reports-board|feedback-item-modal|feedback-kanban|feedback-detail`), route names (`feedback-board.page|board.page|attachments.show|ingest`), and config keys (`feedback-board.*`) are consistent across Tasks 1–16.
