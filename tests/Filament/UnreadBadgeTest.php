<?php

use Illuminate\Support\Facades\{DB, Log, Schema};
use Illuminate\Support\Once;
use YousefAman\FeedbackBoard\Enums\{AuthorRole, FeedbackStatus};
use YousefAman\FeedbackBoard\Filament\Pages\FeedbackBoardPage;
use YousefAman\FeedbackBoard\Filament\Resources\FeedbackItemResource;
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};
use YousefAman\FeedbackBoard\Support\DeveloperReads;
use YousefAman\FeedbackBoard\Tests\Filament\FilamentTestUser;

beforeEach(function () {
    config()->set('feedback-board.authorization.developer_resolver', fn ($user) => $user->name === 'Dev');
    $this->travelTo('2026-10-18 09:00:00');
});

function ubBadgeDev(): FilamentTestUser
{
    return FilamentTestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
}

function ubBadgeReporterComment(FeedbackItem $item): void
{
    FeedbackComment::factory()->for($item, 'item')->create([
        'author_type' => null,
        'author_id' => null,
        'author_role' => AuthorRole::Reporter,
        'is_internal' => false,
    ]);
}

it('keeps the gray open count when nothing is new, with no tooltip', function () {
    $dev = ubBadgeDev();
    $this->actingAs($dev);
    FeedbackItem::factory()->count(2)->create()->each(fn (FeedbackItem $item) => ubBadgeReporterComment($item));

    expect(FeedbackBoardPage::getNavigationBadge())->toBe('2')
        ->and(FeedbackBoardPage::getNavigationBadgeColor())->toBe('gray')
        ->and(FeedbackBoardPage::getNavigationBadgeTooltip())->toBeNull();

    DeveloperReads::begin($dev);
    Once::flush();

    expect(FeedbackBoardPage::getNavigationBadge())->toBe('2')
        ->and(FeedbackBoardPage::getNavigationBadgeColor())->toBe('gray')
        ->and(FeedbackBoardPage::getNavigationBadgeTooltip())->toBeNull();
});

it('turns red with the number of cards that have something new, and tells the open count on hover', function () {
    $dev = ubBadgeDev();
    $this->actingAs($dev);
    $old = FeedbackItem::factory()->count(3)->create();
    DeveloperReads::begin($dev);

    $this->travel(1)->minutes();
    FeedbackItem::factory()->create();
    ubBadgeReporterComment($old->first());
    Once::flush();

    expect(FeedbackBoardPage::getNavigationBadge())->toBe('2')
        ->and(FeedbackBoardPage::getNavigationBadgeColor())->toBe('danger')
        ->and(FeedbackBoardPage::getNavigationBadgeTooltip())->toBe(__('feedback-board::feedback-board.nav.open_reports', ['count' => 4]));
});

it('counts a comment on a resolved report but not the resolved report as New', function () {
    $dev = ubBadgeDev();
    $this->actingAs($dev);
    $older = FeedbackItem::factory()->create(['status' => FeedbackStatus::Resolved]);
    DeveloperReads::begin($dev);

    $this->travel(1)->minutes();
    FeedbackItem::factory()->create(['status' => FeedbackStatus::Resolved]);
    ubBadgeReporterComment($older);
    Once::flush();

    expect(FeedbackBoardPage::getNavigationBadge())->toBe('1')
        ->and(FeedbackBoardPage::getNavigationBadgeColor())->toBe('danger');
});

it('renders the red badge and its tooltip in the panel\'s sidebar', function () {
    $dev = ubBadgeDev();
    DeveloperReads::begin($dev);
    $this->travel(1)->minutes();
    FeedbackItem::factory()->create();

    $html = $this->actingAs($dev)->get(FeedbackBoardPage::getUrl())->assertOk()->getContent();

    // `fi-color-danger` is the colour class both Filament 4.0 and 5.x put on the badge.
    expect($html)->toMatch('/class="(?=[^"]*\bfi-color-danger\b)[^"]*\bfi-badge\b/')
        ->toContain(e(__('feedback-board::feedback-board.nav.open_reports', ['count' => 1])));
});

it('renders the red badge and its tooltip in Arabic too', function () {
    $dev = ubBadgeDev();
    DeveloperReads::begin($dev);
    $this->travel(1)->minutes();
    FeedbackItem::factory()->create();
    app()->setLocale('ar');

    $html = $this->actingAs($dev)->get(FeedbackBoardPage::getUrl())->assertOk()->getContent();

    expect($html)->toMatch('/class="(?=[^"]*\bfi-color-danger\b)[^"]*\bfi-badge\b/')->toContain('البلاغات المفتوحة: 1');
});

it('computes the badge once for its value, colour and tooltip', function () {
    $dev = ubBadgeDev();
    $this->actingAs($dev);
    DeveloperReads::begin($dev);
    $this->travel(1)->minutes();
    FeedbackItem::factory()->create();
    Once::flush();

    DB::enableQueryLog();
    FeedbackBoardPage::getNavigationBadge();
    FeedbackBoardPage::getNavigationBadgeColor();
    FeedbackBoardPage::getNavigationBadgeTooltip();

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(2);
});

it('keeps the panel browsable without the reads table, with one warning', function () {
    Schema::drop('feedback_reads');

    try {
        Log::spy();
        $dev = ubBadgeDev();
        FeedbackItem::factory()->create();

        $this->actingAs($dev)->get(FeedbackBoardPage::getUrl())->assertOk();
        $this->actingAs($dev)->get(FeedbackItemResource::getUrl('index'))->assertOk();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'feedback-board'))
            ->once();
    } finally {
        (require __DIR__.'/../../database/migrations/create_feedback_reads_table.php')->up();
    }
});
