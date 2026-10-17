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

it('skips a panel the recipient may not open when two panels share its model', function () {
    // Both panels' guards now use the users provider, so both match the customer's model.
    config()->set('auth.providers.users.model', YousefAman\FeedbackBoard\Tests\Guards\PortalUser::class);
    config()->set('auth.guards.client.provider', 'users');
    $customer = YousefAman\FeedbackBoard\Tests\Guards\PortalUser::create(['name' => 'Customer']);
    $item = FeedbackItem::factory()->for($customer, 'reporter')->create();

    expect(FeedbackBoard::reportUrl($item, 'reporter', $customer))->toBe('http://localhost/client/my-feedback?report='.$item->id);
});

it("links each developer to the board in a panel on the developer's own guard", function () {
    config()->set('feedback-board.auth.guards', ['web', 'client']);
    config()->set('feedback-board.auth.developer_guards', ['client', 'web']);
    $item = FeedbackItem::factory()->create();

    expect(FeedbackBoard::reportUrl($item, 'developer', TestClient::create(['name' => 'Client dev'])))
        ->toBe('http://localhost/client/feedback-board-page?report='.$item->id)
        ->and(FeedbackBoard::reportUrl($item, 'developer', TestUser::create(['name' => 'Staff dev'])))
        ->toBe('http://localhost/admin/feedback-board-page?report='.$item->id);
});

it('falls back to the standalone page when the matching panel has My reports off', function () {
    $this->staffAndClients();
    $item = FeedbackItem::factory()->for(TestUser::create(['name' => 'Staff']), 'reporter')->create();
    Filament\Facades\Filament::getPanel('admin')->getPlugin('feedback-board')->myReports(false);

    expect(FeedbackBoard::reportUrl($item, 'reporter'))->toBe('http://localhost/feedback-board/feedback?report='.$item->id);
});

it('reads a reporter recorded under a morph map alias', function () {
    $this->staffAndClients();
    Illuminate\Database\Eloquent\Relations\Relation::morphMap(['client' => TestClient::class]);
    $client = TestClient::create(['name' => 'Client']);
    $item = FeedbackItem::factory()->create(['reporter_type' => 'client', 'reporter_id' => $client->id]);

    try {
        expect(FeedbackBoard::reportUrl($item, 'reporter', $client))->toBe('http://localhost/client/my-feedback?report='.$item->id);
    } finally {
        Illuminate\Database\Eloquent\Relations\Relation::morphMap([], merge: false);
    }
});

it('puts the link that fits the notified user in the e-mail and the bell', function () {
    config()->set('auth.providers.users.model', YousefAman\FeedbackBoard\Tests\Guards\PortalUser::class);
    config()->set('auth.guards.client.provider', 'users');
    $customer = YousefAman\FeedbackBoard\Tests\Guards\PortalUser::create(['name' => 'Customer', 'email' => 'c@x.com']);
    $item = FeedbackItem::factory()->for($customer, 'reporter')->create();
    $comment = YousefAman\FeedbackBoard\Models\FeedbackComment::factory()->for($item, 'item')
        ->create(['author_role' => YousefAman\FeedbackBoard\Enums\AuthorRole::Developer, 'body' => 'Fixed']);
    $notification = new YousefAman\FeedbackBoard\Notifications\FeedbackRepliedNotification($comment);
    $url = 'http://localhost/client/my-feedback?report='.$item->id;

    $mail = $notification->toMail($customer);

    expect($mail->actionUrl)->toBe($url)
        ->and($mail->introLines)->toBe(['There is a new reply on report #'.$item->id.'.', "\u{2068}Fixed\u{2069}"])
        ->and($notification->toArray($customer)['feedback_url'])->toBe($url);
});

it('links each developer in the delivery-failure bell to the board in their own panel', function () {
    Illuminate\Support\Facades\Schema::create('notifications', function ($t) {
        $t->uuid('id')->primary();
        $t->string('type');
        $t->morphs('notifiable');
        $t->text('data');
        $t->timestamp('read_at')->nullable();
        $t->timestamps();
    });
    config()->set('feedback-board.auth.guards', ['web', 'client']);
    config()->set('feedback-board.auth.developer_guards', ['client', 'web']);
    config()->set('feedback-board.destinations.lock_store', 'array');
    $clientDev = TestClient::create(['name' => 'Client dev']);
    $staffDev = TestUser::create(['name' => 'Staff dev']);
    FeedbackBoard::notifyDevelopersUsing(fn () => [$clientDev, $staffDev]);
    $item = FeedbackItem::factory()->create();

    (new YousefAman\FeedbackBoard\Jobs\DeliverToDestination(new YousefAman\FeedbackBoard\Events\FeedbackItemCreated($item), 'slack', 'dlv-1'))
        ->failed(new Illuminate\Http\Client\ConnectionException('refused'));

    expect($clientDev->notifications()->first()->data['actions'][0]['url'])->toBe('http://localhost/client/feedback-board-page?report='.$item->id)
        ->and($staffDev->notifications()->first()->data['actions'][0]['url'])->toBe('http://localhost/admin/feedback-board-page?report='.$item->id);
});
