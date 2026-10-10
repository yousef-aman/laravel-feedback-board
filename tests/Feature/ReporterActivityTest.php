<?php

use Illuminate\Support\Facades\{DB, Schema};
use YousefAman\FeedbackBoard\Actions\{AssignFeedback, ReplyToFeedback, TransitionStatus};
use YousefAman\FeedbackBoard\Enums\{AuthorRole, FeedbackStatus};
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(fn () => $this->travelTo('2026-10-01 09:00:00'));
// The prefixed-connection test switches the default; the teardown rollback must run on the original one.
afterEach(fn () => DB::setDefaultConnection('testing'));

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
