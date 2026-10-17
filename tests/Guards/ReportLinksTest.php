<?php

use YousefAman\FeedbackBoard\Facades\FeedbackBoard;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\Guards\TestClient;
use YousefAman\FeedbackBoard\Tests\TestUser;

it("links a client's report to My reports in the client panel, and developers to the staff board", function () {
    $this->staffAndClients();
    $item = FeedbackItem::factory()->for(TestClient::create(['name' => 'Client']), 'reporter')->create();

    expect(FeedbackBoard::reportUrl($item, 'reporter'))->toBe('http://localhost/client/my-feedback?report='.$item->id)
        ->and(FeedbackBoard::reportUrl($item, 'developer'))->toBe('http://localhost/admin/feedback-board-page?report='.$item->id);
});

it("links a staff member's report to My reports in the staff panel", function () {
    $this->staffAndClients();
    $item = FeedbackItem::factory()->for(TestUser::create(['name' => 'Staff']), 'reporter')->create();

    expect(FeedbackBoard::reportUrl($item, 'reporter'))->toBe('http://localhost/admin/my-feedback?report='.$item->id);
});

it('picks the same links from inside another panel', function () {
    $this->staffAndClients()->inPanel('admin');
    $item = FeedbackItem::factory()->for(TestClient::create(['name' => 'Client']), 'reporter')->create();

    expect(FeedbackBoard::reportUrl($item, 'reporter'))->toBe('http://localhost/client/my-feedback?report='.$item->id);
});

it('uses the standalone page for a reporter whose guard the package does not recognise', function () {
    config()->set('feedback-board.auth.guards', ['web']);
    $item = FeedbackItem::factory()->for(TestClient::create(['name' => 'Client']), 'reporter')->create();

    expect(FeedbackBoard::reportUrl($item, 'reporter'))->toBe('http://localhost/feedback-board/feedback?report='.$item->id);
});

it('links developers to the default panel when no guards are configured', function () {
    $item = FeedbackItem::factory()->for(TestUser::create(['name' => 'Staff']), 'reporter')->create();

    expect(FeedbackBoard::reportUrl($item, 'developer'))->toBe('http://localhost/admin/feedback-board-page?report='.$item->id)
        ->and(FeedbackBoard::reportUrl($item, 'reporter'))->toBe('http://localhost/admin/my-feedback?report='.$item->id);
});
