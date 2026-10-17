<?php

use Illuminate\Support\Facades\Exceptions;
use YousefAman\FeedbackBoard\Facades\FeedbackBoard;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\TestUser;

it('links the reporter to My reports and developers to the board, on the standalone pages without Filament', function () {
    $item = FeedbackItem::factory()->for(TestUser::create(['name' => 'C']), 'reporter')->create();

    expect(FeedbackBoard::reportUrl($item, 'reporter'))->toBe('http://localhost/feedback-board/feedback?report='.$item->id)
        ->and(FeedbackBoard::reportUrl($item, 'developer'))->toBe('http://localhost/feedback-board/feedback/board?report='.$item->id);
});

it('uses the link the app builds, for each audience', function () {
    $item = FeedbackItem::factory()->create();
    FeedbackBoard::linkReportsUsing(fn (FeedbackItem $report, string $audience) => "https://app.test/{$audience}/{$report->id}");

    expect(FeedbackBoard::reportUrl($item, 'reporter'))->toBe('https://app.test/reporter/'.$item->id)
        ->and(FeedbackBoard::reportUrl($item, 'developer'))->toBe('https://app.test/developer/'.$item->id);
});

it('leaves the link out when the app builds none, or one that is not http(s)', function (mixed $link) {
    $item = FeedbackItem::factory()->create();
    FeedbackBoard::linkReportsUsing(fn () => $link);

    expect(FeedbackBoard::reportUrl($item, 'reporter'))->toBeNull();
})->with(['null' => null, 'javascript' => 'javascript:alert(1)', 'not a string' => 42, 'relative' => '/feedback?report=1']);

it('leaves the link out and reports the error once when the app link builder throws', function () {
    Exceptions::fake();
    $item = FeedbackItem::factory()->create();
    FeedbackBoard::linkReportsUsing(fn () => throw new RuntimeException('no route'));

    expect(FeedbackBoard::reportUrl($item, 'reporter'))->toBeNull()
        ->and(FeedbackBoard::reportUrl($item, 'developer'))->toBeNull();
    Exceptions::assertReportedCount(1);
});
