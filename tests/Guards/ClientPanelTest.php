<?php

use Illuminate\Support\Facades\Blade;
use YousefAman\FeedbackBoard\Filament\Pages\MyFeedbackPage;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\Filament\FilamentTestUser;
use YousefAman\FeedbackBoard\Tests\Guards\TestClient;

beforeEach(function () {
    $this->staff = FilamentTestUser::create(['name' => 'Staff', 'email' => 's@x.com']);
    $this->client = TestClient::create(['name' => 'Client', 'email' => 'c@x.com']);
    FeedbackItem::factory()->for($this->staff, 'reporter')->create(['title' => 'Staff report']);
    FeedbackItem::factory()->for($this->client, 'reporter')->create(['title' => 'Client report']);
});

it('gives a client the launcher and their own reports in the client panel', function () {
    $this->staffAndClients()->signIn($this->client, 'client');

    $this->get(MyFeedbackPage::getUrl(panel: 'client'))->assertOk()
        ->assertSee('class="fb-launcher"', false)
        ->assertSee('Client report')
        ->assertDontSee('Staff report');
});

it('shows each panel its own session when one browser holds both', function () {
    $this->staffAndClients()->signIn($this->staff, 'web')->signIn($this->client, 'client');

    $this->get(MyFeedbackPage::getUrl(panel: 'client'))->assertOk()->assertSee('Client report')->assertDontSee('Staff report');
    $this->nextRequest()->get(MyFeedbackPage::getUrl(panel: 'admin'))->assertOk()->assertSee('Staff report')->assertDontSee('Client report');
});

it('shows no launcher on a client panel guest page to a user signed in on web only', function () {
    // Implicit mode, as on the panel's login page: SetUpPanel ran, the panel's Authenticate did not.
    $this->signIn($this->staff, 'web')->inPanel('client');

    expect(trim(Blade::render('<x-feedback-board::launcher />')))->toBe('');
});
