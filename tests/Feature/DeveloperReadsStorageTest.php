<?php

use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\{DB, Log, Schema};
use YousefAman\FeedbackBoard\Models\{FeedbackItem, FeedbackRead};
use YousefAman\FeedbackBoard\Support\DeveloperReads;
use YousefAman\FeedbackBoard\Tests\Filament\FilamentTestUser;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(fn () => $this->travelTo('2026-10-18 09:00:00'));

function ubDev(): TestUser
{
    return TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
}

it('creates the reads table with a unique reader-and-report index, and drops it on rollback', function () {
    expect(Schema::hasTable('feedback_reads'))->toBeTrue()
        ->and(Schema::hasIndex('feedback_reads', 'feedback_reads_reader_item_unique'))->toBeTrue();

    (require __DIR__.'/../../database/migrations/create_feedback_reads_table.php')->down();
    expect(Schema::hasTable('feedback_reads'))->toBeFalse();

    (require __DIR__.'/../../database/migrations/create_feedback_reads_table.php')->up();
    expect(Schema::hasTable('feedback_reads'))->toBeTrue();
});

it('indexes the reader only through the unique index, on the columns morphs() makes', function () {
    $indexes = collect(Schema::getIndexes('feedback_reads'));

    expect($indexes->filter(fn (array $index) => $index['columns'] === ['reader_type', 'reader_id']))->toBeEmpty()
        ->and($indexes->firstWhere('name', 'feedback_reads_reader_item_unique')['columns'])->toBe(['reader_type', 'reader_id', 'feedback_item_id'])
        ->and(Schema::getColumnType('feedback_reads', 'reader_type'))->toBe('varchar')
        ->and(Schema::getColumnType('feedback_reads', 'reader_id'))->toBe('integer');
});

it('keys the reader as the host keys its morphs', function () {
    $migration = require __DIR__.'/../../database/migrations/create_feedback_reads_table.php';
    Builder::defaultMorphKeyType('uuid');

    try {
        $migration->down();
        $migration->up();

        expect(Schema::getColumnType('feedback_reads', 'reader_id'))->toBe('varchar');
    } finally {
        Builder::defaultMorphKeyType('int');
        $migration->down();
        $migration->up();
    }
});

it('writes the baseline on the first visit only and keeps the earliest', function () {
    $dev = ubDev();

    expect(DeveloperReads::baseline($dev))->toBeNull()
        ->and(DeveloperReads::begin($dev)->toDateTimeString())->toBe('2026-10-18 09:00:00');

    $this->travel(1)->hour();
    expect(DeveloperReads::begin($dev)->toDateTimeString())->toBe('2026-10-18 09:00:00')
        ->and(FeedbackRead::whereNull('feedback_item_id')->count())->toBe(1);

    FeedbackRead::create([
        'feedback_item_id' => null,
        'reader_type' => $dev->getMorphClass(),
        'reader_id' => $dev->getKey(),
        'read_at' => '2026-10-18 08:00:00',
    ]);
    expect(DeveloperReads::baseline($dev)->toDateTimeString())->toBe('2026-10-18 08:00:00');
});

it('marks a report read per developer, updating the same row', function () {
    $dev = ubDev();
    $item = FeedbackItem::factory()->create();

    DeveloperReads::markRead($item, $dev);
    $this->travel(5)->minutes();
    DeveloperReads::markRead($item, $dev);

    $rows = FeedbackRead::where('feedback_item_id', $item->id)->where('reader_type', $dev->getMorphClass())->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->read_at->toDateTimeString())->toBe('2026-10-18 09:05:00');

    $twin = FilamentTestUser::find($dev->id);
    DeveloperReads::markRead($item, $twin);

    expect(FeedbackRead::where('feedback_item_id', $item->id)->count())->toBe(2)
        ->and(FeedbackRead::where('reader_type', $twin->getMorphClass())->count())->toBe(1);
});

it('deletes the reads of a deleted report and keeps the baseline', function () {
    DB::statement('PRAGMA foreign_keys = ON');
    // The migrations roll back alphabetically, dropping feedback_items before feedback_comments,
    // which fails while foreign keys are on.
    $this->beforeApplicationDestroyed(fn () => DB::statement('PRAGMA foreign_keys = OFF'));

    $dev = ubDev();
    $item = FeedbackItem::factory()->create();

    DeveloperReads::begin($dev);
    DeveloperReads::markRead($item, $dev);
    $item->delete();

    expect(FeedbackRead::count())->toBe(1)
        ->and(FeedbackRead::first()->feedback_item_id)->toBeNull();
});

it('keeps working without the table, with one warning', function () {
    $dev = ubDev();
    $item = FeedbackItem::factory()->create();

    Schema::drop('feedback_reads');
    Log::spy();

    try {
        expect(DeveloperReads::begin($dev))->toBeNull()
            ->and(DeveloperReads::baseline($dev))->toBeNull();
        DeveloperReads::markRead($item, $dev);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m) => str_contains($m, 'feedback-board') && str_contains($m, 'publish and run the package migrations'))
            ->once();
    } finally {
        (require __DIR__.'/../../database/migrations/create_feedback_reads_table.php')->up();
    }
});
