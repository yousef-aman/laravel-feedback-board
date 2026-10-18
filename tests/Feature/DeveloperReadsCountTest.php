<?php

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\{DB, Schema};
use YousefAman\FeedbackBoard\Actions\{AssignFeedback, SetPriority, TransitionStatus};
use YousefAman\FeedbackBoard\Enums\{AuthorRole, FeedbackPriority, FeedbackStatus, FeedbackType};
use YousefAman\FeedbackBoard\Facades\FeedbackBoard;
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem, FeedbackRead};
use YousefAman\FeedbackBoard\Support\DeveloperReads;
use YousefAman\FeedbackBoard\Tests\Filament\FilamentTestUser;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    $this->travelTo('2026-10-18 09:00:00');
    FeedbackBoard::resolveDevelopersUsing(fn ($u) => in_array($u->name, ['Dev', 'Other'], true));
});
// The prefixed-connection test switches the default; the teardown rollback must run on the original one.
afterEach(fn () => DB::setDefaultConnection('testing'));

function ubCountDev(): TestUser
{
    return TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
}

function ubCountOtherDev(): TestUser
{
    return TestUser::create(['name' => 'Other', 'email' => 'o@x.com']);
}

function ubComment(FeedbackItem $item, ?Model $author, AuthorRole $role = AuthorRole::Developer, bool $internal = false): FeedbackComment
{
    return FeedbackComment::factory()->for($item, 'item')->create([
        'author_type' => $author?->getMorphClass(),
        'author_id' => $author?->getKey(),
        'author_role' => $role,
        'is_internal' => $internal,
    ]);
}

$marker = fn (FeedbackItem $item, Model $dev, CarbonInterface $baseline): array => DeveloperReads::marker(
    DeveloperReads::apply(FeedbackItem::query(), $dev, $baseline)->find($item->id),
    $dev,
    $baseline,
);

it('counts comments by others after the read: reporter, colleague, internal note and authorless AI', function () use ($marker) {
    $dev = ubCountDev();
    $other = ubCountOtherDev();
    $baseline = DeveloperReads::begin($dev);
    $item = FeedbackItem::factory()->create();

    $this->travel(1)->minutes();
    DeveloperReads::markRead($item, $dev);

    $this->travel(1)->minutes();
    ubComment($item, null, AuthorRole::Reporter);
    ubComment($item, $other);
    ubComment($item, $other, internal: true);
    ubComment($item, null, AuthorRole::Ai);

    expect($marker($item, $dev, $baseline))->toBe(['new' => false, 'unread' => 4]);
});

it('never counts the developer\'s own comments, public, internal or written from My reports', function () use ($marker) {
    $dev = ubCountDev();
    $baseline = DeveloperReads::begin($dev);
    $item = FeedbackItem::factory()->for($dev, 'reporter')->create();

    $this->travel(1)->minutes();
    ubComment($item, $dev);
    ubComment($item, $dev, internal: true);
    ubComment($item, $dev, AuthorRole::Reporter);

    expect($marker($item, $dev, $baseline)['unread'])->toBe(0);
});

it('ignores status, priority, type and assignment changes', function () use ($marker) {
    $dev = ubCountDev();
    $other = ubCountOtherDev();
    $baseline = DeveloperReads::begin($dev);
    $item = FeedbackItem::factory()->create();

    $this->travel(1)->minutes();
    DeveloperReads::markRead($item, $dev);

    $this->travel(1)->minutes();
    app(TransitionStatus::class)->handle($item->fresh(), FeedbackStatus::InProgress, $other);
    app(SetPriority::class)->handle($item->fresh(), FeedbackPriority::High, $other);
    app(AssignFeedback::class)->handle($item->fresh(), $dev, $other);
    $item->fresh()->update(['type' => FeedbackType::Feature]);

    expect($marker($item, $dev, $baseline)['unread'])->toBe(0);
});

it('treats a comment in the same second as the read as read', function () use ($marker) {
    $dev = ubCountDev();
    $other = ubCountOtherDev();
    $baseline = DeveloperReads::begin($dev);
    $item = FeedbackItem::factory()->create();

    $this->travelTo('2026-10-18 09:01:00');
    DeveloperReads::markRead($item, $dev);
    ubComment($item, $other);

    expect($marker($item, $dev, $baseline)['unread'])->toBe(0);

    $this->travelTo('2026-10-18 09:01:01');
    ubComment($item, $other);

    expect($marker($item, $dev, $baseline)['unread'])->toBe(1);
});

it('marks an open report nobody opened as new, and only then', function (FeedbackStatus $status, bool $new) use ($marker) {
    $dev = ubCountDev();
    $baseline = DeveloperReads::begin($dev);

    $this->travel(1)->minutes();
    $item = FeedbackItem::factory()->create(['status' => $status]);

    expect($marker($item, $dev, $baseline)['new'])->toBe($new);
})->with([
    'new' => [FeedbackStatus::New, true],
    'in review' => [FeedbackStatus::InReview, true],
    'in progress' => [FeedbackStatus::InProgress, true],
    'resolved' => [FeedbackStatus::Resolved, false],
    'closed' => [FeedbackStatus::Closed, false],
    'declined' => [FeedbackStatus::Declined, false],
]);

it('is not new when created before the baseline, sent by the developer, or opened', function () use ($marker) {
    $dev = ubCountDev();
    $before = FeedbackItem::factory()->create();

    $this->travel(1)->minutes();
    $baseline = DeveloperReads::begin($dev);

    $this->travel(1)->minutes();
    $sent = FeedbackItem::factory()->for($dev, 'reporter')->create();
    $opened = FeedbackItem::factory()->create();
    DeveloperReads::markRead($opened, $dev);

    expect($marker($before, $dev, $baseline)['new'])->toBeFalse()
        ->and($marker($sent, $dev, $baseline)['new'])->toBeFalse()
        ->and($marker($opened, $dev, $baseline)['new'])->toBeFalse();
});

it('is new again when a closed report is reopened and still unopened', function () use ($marker) {
    $dev = ubCountDev();
    $baseline = DeveloperReads::begin($dev);

    $this->travel(1)->minutes();
    $item = FeedbackItem::factory()->create(['status' => FeedbackStatus::Closed]);

    expect($marker($item, $dev, $baseline)['new'])->toBeFalse();

    $item->update(['status' => FeedbackStatus::InProgress]);

    expect($marker($item, $dev, $baseline)['new'])->toBeTrue();
});

it('counts comments by others on a closed report', function () use ($marker) {
    $dev = ubCountDev();
    $client = TestUser::create(['name' => 'Client', 'email' => 'c@x.com']);
    $baseline = DeveloperReads::begin($dev);
    $item = FeedbackItem::factory()->for($client, 'reporter')->create(['status' => FeedbackStatus::Resolved]);

    $this->travel(1)->minutes();
    DeveloperReads::markRead($item, $dev);

    $this->travel(1)->minutes();
    ubComment($item, $client, AuthorRole::Reporter);

    expect($marker($item, $dev, $baseline)['unread'])->toBe(1);
});

it('counts comments after the baseline on a report never opened', function () use ($marker) {
    $dev = ubCountDev();
    $item = FeedbackItem::factory()->create();
    ubComment($item, null, AuthorRole::Reporter);

    $this->travel(1)->minutes();
    $baseline = DeveloperReads::begin($dev);

    $this->travel(1)->minutes();
    ubComment($item, null, AuthorRole::Reporter);

    expect($marker($item, $dev, $baseline))->toBe(['new' => false, 'unread' => 1]);
});

it('keeps two developers apart, and the same key on two models apart', function () use ($marker) {
    $dev = ubCountDev();
    $other = ubCountOtherDev();
    $twin = FilamentTestUser::find($dev->id);
    $item = FeedbackItem::factory()->create();
    $devBaseline = DeveloperReads::begin($dev);
    $otherBaseline = DeveloperReads::begin($other);
    $twinBaseline = DeveloperReads::begin($twin);

    $this->travel(1)->minutes();
    ubComment($item, $dev);
    ubComment($item, $other);

    expect($marker($item, $dev, $devBaseline)['unread'])->toBe(1)
        ->and($marker($item, $other, $otherBaseline)['unread'])->toBe(1)
        ->and($marker($item, $twin, $twinBaseline)['unread'])->toBe(2);
});

it('counts the cards with something new, and nothing before the first visit', function () {
    $dev = ubCountDev();
    $other = ubCountOtherDev();
    $early = FeedbackItem::factory()->create();
    ubComment($early, $other);

    expect(DeveloperReads::cardsWithNews($dev))->toBe(0)
        ->and(DeveloperReads::baseline($dev))->toBeNull();

    $this->travel(1)->minutes();
    DeveloperReads::begin($dev);

    $this->travel(1)->minutes();
    FeedbackItem::factory()->create();
    $read = FeedbackItem::factory()->create();
    $quiet = FeedbackItem::factory()->create();
    ubComment($quiet, $other);
    FeedbackItem::factory()->create(['status' => FeedbackStatus::Closed]);
    FeedbackItem::factory()->for($dev, 'reporter')->create();

    $this->travel(1)->minutes();
    DeveloperReads::markRead($read, $dev);
    DeveloperReads::markRead($quiet, $dev);

    $this->travel(1)->minutes();
    ubComment($read, $other);

    expect(DeveloperReads::cardsWithNews($dev))->toBe(2);
});

it('works on a connection with a table prefix', function () use ($marker) {
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

    $dev = ubCountDev();
    $baseline = DeveloperReads::begin($dev);

    $this->travel(1)->minutes();
    $item = FeedbackItem::factory()->create();
    ubComment($item, null, AuthorRole::Reporter);

    expect(DB::select("select name from sqlite_master where name = 'fb_feedback_reads'"))->toHaveCount(1)
        ->and($marker($item, $dev, $baseline)['unread'])->toBe(1)
        ->and(DeveloperReads::cardsWithNews($dev))->toBe(1);
});

it('keys reads and own comments by the morph alias', function () use ($marker) {
    Relation::enforceMorphMap(['staff' => TestUser::class]);

    try {
        $dev = ubCountDev();
        $baseline = DeveloperReads::begin($dev);

        $this->travel(1)->minutes();
        $item = FeedbackItem::factory()->create();
        ubComment($item, $dev);

        expect($marker($item, $dev, $baseline)['unread'])->toBe(0)
            ->and(FeedbackRead::first()->reader_type)->toBe('staff');
    } finally {
        Relation::morphMap([], false);
        Relation::requireMorphMap(false);
    }
});
