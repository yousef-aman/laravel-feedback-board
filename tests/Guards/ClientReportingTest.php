<?php

use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use YousefAman\FeedbackBoard\Livewire\{FeedbackDetail, FeedbackForm, FeedbackItemModal, FeedbackKanban, MyReportsBoard};
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Notifications\{FeedbackRepliedNotification, FeedbackResolvedNotification};
use YousefAman\FeedbackBoard\Tests\Filament\FilamentTestUser;
use YousefAman\FeedbackBoard\Tests\Guards\TestClient;

// Clients are signed in on `client` while the default guard stays `web`, as in a Livewire update from
// a host page with no guard middleware: only the package's own resolution finds them.
beforeEach(function () {
    config()->set('feedback-board.authorization.developer_resolver', fn ($user) => $user->name === 'Dev');
    $this->staffAndClients();
    $this->dev = FilamentTestUser::create(['name' => 'Dev', 'email' => 'dev@x.com']);
    $this->client = TestClient::create(['name' => 'Client', 'email' => 'c@x.com']);
});

it('files a report as the client', function () {
    $this->signIn($this->client, 'client');

    Livewire::test(FeedbackForm::class)
        ->set('title', 'Invoice total is wrong')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('sent', true);

    $item = FeedbackItem::sole();
    expect($item->isReportedBy($this->client))->toBeTrue()
        ->and($item->reporter)->toBeInstanceOf(TestClient::class)
        ->and($item->reporter_email)->toBe('c@x.com');
});

it('lists, opens and answers only the client own reports', function () {
    $mine = FeedbackItem::factory()->for($this->client, 'reporter')->create(['title' => 'Mine']);
    FeedbackItem::factory()->for(TestClient::create(['name' => 'Other']), 'reporter')->create(['title' => 'Another client']);
    $this->signIn($this->client, 'client');

    Livewire::test(MyReportsBoard::class)
        ->assertSee('Mine')
        ->assertDontSee('Another client')
        ->call('open', $mine->id)
        ->assertSet('report', $mine->id);

    Livewire::test(FeedbackItemModal::class, ['item' => $mine])
        ->set('body', 'It happens on every invoice')
        ->call('reply')
        ->assertHasNoErrors();

    expect($mine->comments()->sole()->author_type)->toBe($this->client->getMorphClass());
});

it('keeps a client and a staff member with the same id apart', function () {
    expect($this->client->getKey())->toBe($this->dev->getKey());
    FeedbackItem::factory()->for($this->dev, 'reporter')->create(['title' => 'Staff report']);
    FeedbackItem::factory()->for($this->client, 'reporter')->create(['title' => 'Client report']);
    $this->signIn($this->client, 'client');

    Livewire::test(MyReportsBoard::class)->assertSee('Client report')->assertDontSee('Staff report');
});

it('refuses a client a board move even when the developer rule matches their name', function () {
    $named = TestClient::create(['name' => 'Dev', 'email' => 'imposter@x.com']);
    $item = FeedbackItem::factory()->create();
    $this->signIn($named, 'client');

    Livewire::test(FeedbackKanban::class)->call('moveTo', $item->id, 'in_progress')->assertForbidden();
});

it('lets a developer on web answer and resolve a client report, and mails the client both times', function () {
    Notification::fake();
    $item = FeedbackItem::factory()->for($this->client, 'reporter')->create();
    $this->signIn($this->dev, 'web');

    Livewire::test(FeedbackDetail::class, ['item' => $item])
        ->set('body', 'Fixed on our side')
        ->call('reply')
        ->call('changeStatus', 'resolved')
        ->assertHasNoErrors();

    Notification::assertSentTo($this->client, FeedbackRepliedNotification::class);
    Notification::assertSentTo($this->client, FeedbackResolvedNotification::class);
});
