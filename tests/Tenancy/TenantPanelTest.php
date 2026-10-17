<?php

use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Livewire\Livewire;
use YousefAman\FeedbackBoard\Facades\FeedbackBoard;
use YousefAman\FeedbackBoard\Filament\Pages\{FeedbackBoardPage, MyFeedbackPage};
use YousefAman\FeedbackBoard\Filament\Resources\FeedbackItemResource;
use YousefAman\FeedbackBoard\Livewire\FeedbackForm;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Support\Ui;
use YousefAman\FeedbackBoard\Tests\Tenancy\{Team, TenantUser};

beforeEach(function () {
    FeedbackBoard::resolveDevelopersUsing(fn ($user) => $user->name === 'Dev');
    $this->team = Team::create(['name' => 'Acme']);
    FeedbackItem::factory()->create(['title' => 'Smoke item']);
});

it('serves the board, All reports, My reports and the dashboard to a developer on a tenant panel', function () {
    $this->actingAs(TenantUser::create(['name' => 'Dev']));

    $this->get(FeedbackBoardPage::getUrl(panel: 'admin', tenant: $this->team))->assertOk()->assertSee('Smoke item');
    $this->get(FeedbackItemResource::getUrl('index', panel: 'admin', tenant: $this->team))->assertOk()->assertSee('Smoke item');
    $this->get(MyFeedbackPage::getUrl(panel: 'admin', tenant: $this->team))->assertOk();
    $this->get(Dashboard::getUrl(panel: 'admin', tenant: $this->team))->assertOk();
});

it('serves My reports and the dashboard to a reporter on a tenant panel', function () {
    $this->actingAs(TenantUser::create(['name' => 'Client']));

    $this->get(MyFeedbackPage::getUrl(panel: 'admin', tenant: $this->team))->assertOk();
    $this->get(Dashboard::getUrl(panel: 'admin', tenant: $this->team))->assertOk();
});

it('saves a report submitted inside a tenant panel', function () {
    $this->actingAs(TenantUser::create(['name' => 'Client']));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    Filament::setTenant($this->team);

    Livewire::test(FeedbackForm::class)->set('title', 'Broken invoice')->call('submit')->assertHasNoErrors();

    expect(FeedbackItem::where('title', 'Broken invoice')->exists())->toBeTrue();
});

it('links the standalone My reports page from a tenant panel page that has no tenant', function () {
    $this->actingAs(TenantUser::create(['name' => 'Client']));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    // The profile page and tenant registration have no tenant: the panel URL cannot be built.
    expect(Ui::reportsUrl())->toBe(route('feedback-board.page'));
    Livewire::test(FeedbackForm::class)->assertSet('reportsUrl', route('feedback-board.page'));

    Filament::setTenant($this->team);

    expect(Ui::reportsUrl())->toBe(MyFeedbackPage::getUrl(panel: 'admin', tenant: $this->team));
});

it('links notifications to the standalone pages, since a tenant panel URL needs a tenant', function () {
    $item = FeedbackItem::factory()->for(TenantUser::create(['name' => 'Client']), 'reporter')->create();

    expect(FeedbackBoard::reportUrl($item, 'reporter'))->toBe('http://localhost/feedback-board/feedback?report='.$item->id)
        ->and(FeedbackBoard::reportUrl($item, 'developer'))->toBe('http://localhost/feedback-board/feedback/board?report='.$item->id);
});
