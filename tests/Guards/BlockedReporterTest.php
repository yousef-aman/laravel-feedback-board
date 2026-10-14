<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Blade, Gate, Storage};
use Livewire\Livewire;
use YousefAman\FeedbackBoard\Facades\FeedbackBoard;
use YousefAman\FeedbackBoard\Filament\Pages\MyFeedbackPage;
use YousefAman\FeedbackBoard\Livewire\{FeedbackForm, FeedbackItemModal, MyReportsBoard};
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\Guards\TestClient;

beforeEach(function () {
    Storage::fake('local');
    $this->staffAndClients();
    FeedbackBoard::resolveReportersUsing(fn ($user) => $user->name !== 'Blocked');
    $this->allowed = TestClient::create(['name' => 'Allowed', 'email' => 'a@x.com']);
    $this->blocked = TestClient::create(['name' => 'Blocked', 'email' => 'b@x.com']);
    $this->blockedNoReports = TestClient::create(['name' => 'Blocked', 'email' => 'n@x.com']);
    $this->earlier = FeedbackItem::factory()->for($this->blocked, 'reporter')->create(['title' => 'Earlier report']);
});

function fbGuardsLauncher(): string
{
    return Blade::render('<x-feedback-board::launcher />');
}

it('gives a user who may report the report button and its form', function () {
    $this->signIn($this->allowed, 'client');

    expect(fbGuardsLauncher())->toContain('<button type="button" class="fb-launcher"')
        ->toContain('wire:submit="submit"');
});

it('gives a blocked user with reports a My reports link in place of the button', function () {
    $this->signIn($this->blocked, 'client');

    expect(fbGuardsLauncher())->toContain('<a class="fb-launcher" href="'.route('feedback-board.page').'">')
        ->toContain('<span class="fb-launcher__label">My reports</span>')
        ->not->toContain('<button')
        ->not->toContain('wire:submit');
});

it('points the My reports link at the panel page inside a panel', function () {
    $this->signIn($this->blocked, 'client')->inPanel('client');

    expect(fbGuardsLauncher())->toContain('<a class="fb-launcher" href="'.MyFeedbackPage::getUrl(panel: 'client').'">');
});

it('renders nothing for a blocked user without reports', function () {
    $this->signIn($this->blockedNoReports, 'client');

    expect(trim(fbGuardsLauncher()))->toBe('');
});

it('refuses a report from a blocked user, with or without earlier reports', function (string $who) {
    $this->signIn($this->{$who}, 'client');

    Livewire::test(FeedbackForm::class)->set('title', 'Sneaked in')->call('submit')->assertForbidden();
    expect(FeedbackItem::where('title', 'Sneaked in')->exists())->toBeFalse();
})->with(['blocked', 'blockedNoReports']);

it('opens My reports for a blocked user with reports, without New report or the form', function () {
    $this->signIn($this->blocked, 'client');
    $panel = $this->get(MyFeedbackPage::getUrl(panel: 'client'))->assertOk()->getContent();
    $standalone = $this->nextRequest()->get(route('feedback-board.page'))->assertOk()->getContent();

    foreach ([$panel, $standalone] as $html) {
        expect($html)->toContain('Earlier report')
            ->not->toContain('fb-open-report-form')
            ->not->toContain('wire:submit="submit"')
            ->not->toContain('New report');
    }
});

it('refuses My reports to a blocked user without reports, in the panel and on the standalone page', function () {
    $this->signIn($this->blockedNoReports, 'client');

    $this->get(MyFeedbackPage::getUrl(panel: 'client'))->assertForbidden();
    $this->nextRequest()->get(route('feedback-board.page'))->assertForbidden();

    $this->inPanel('client');
    expect(MyFeedbackPage::canAccess())->toBeFalse();
});

it('shows New report and the form to a user who may report, in the panel and on the standalone page', function () {
    $this->signIn($this->allowed, 'client');
    $panel = $this->get(MyFeedbackPage::getUrl(panel: 'client'))->assertOk()->getContent();
    $standalone = $this->nextRequest()->get(route('feedback-board.page'))->assertOk()->getContent();

    foreach ([$panel, $standalone] as $html) {
        expect($html)->toContain('fb-open-report-form')
            ->toContain('wire:submit="submit"')
            ->toContain('New report');
    }

    $this->inPanel('client');
    expect(MyFeedbackPage::canAccess())->toBeTrue();
});

it('lets a blocked user open an earlier report and reply with an image', function () {
    $this->signIn($this->blocked, 'client');

    Livewire::test(MyReportsBoard::class)->call('open', $this->earlier->id)->assertSet('report', $this->earlier->id);
    Livewire::test(FeedbackItemModal::class, ['item' => $this->earlier])
        ->set('upload', UploadedFile::fake()->image('shot.png'))
        ->call('reply')
        ->assertHasNoErrors();

    expect($this->earlier->comments()->sole()->attachments)->toHaveCount(1);
});

it('opens a blocked user own report from a deep link, and nobody else report', function () {
    $other = FeedbackItem::factory()->for($this->allowed, 'reporter')->create(['title' => 'Not yours']);
    $this->signIn($this->blocked, 'client');

    $mine = $this->get(route('feedback-board.page', ['report' => $this->earlier->id]))->assertOk()->getContent();
    $theirs = $this->nextRequest()->get(route('feedback-board.page', ['report' => $other->id]))->assertOk()->getContent();

    expect($mine)->toMatch('/-report-title"/')
        ->and($theirs)->not->toMatch('/-report-title"/')
        ->and($theirs)->not->toContain('Not yours');
});

it('hides reporting without an error when a Gate::before hook denies abilities it does not know', function () {
    Gate::before(fn () => false);
    $this->signIn($this->allowed, 'client');

    expect(trim(fbGuardsLauncher()))->toBe('');
    $this->get(route('feedback-board.page'))->assertForbidden();
});

it('treats a session on an unlisted guard as a guest, without asking the reporter resolver', function () {
    config()->set('feedback-board.auth.guards', ['web']);
    config()->set('feedback-board.auth.developer_guards', null);
    $asked = 0;
    FeedbackBoard::resolveReportersUsing(function () use (&$asked) {
        $asked++;

        return true;
    });
    $this->signIn($this->allowed, 'client');

    expect(trim(fbGuardsLauncher()))->toBe('')
        ->and($asked)->toBe(0);
    $this->getJson(route('feedback-board.page'))->assertUnauthorized();
});
