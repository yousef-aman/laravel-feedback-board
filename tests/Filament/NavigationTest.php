<?php

use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Log, Schema};
use Livewire\Livewire;
use YousefAman\FeedbackBoard\Enums\{AuthorRole, FeedbackPriority, FeedbackStatus, FeedbackType};
use YousefAman\FeedbackBoard\Filament\Pages\{FeedbackBoardPage, MyFeedbackPage};
use YousefAman\FeedbackBoard\Filament\Resources\FeedbackItemResource;
use YousefAman\FeedbackBoard\Livewire\FeedbackForm;
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};
use YousefAman\FeedbackBoard\Support\Ui;
use YousefAman\FeedbackBoard\Tests\Filament\FilamentTestUser;

beforeEach(fn () => config()->set('feedback-board.authorization.developer_resolver', fn ($user) => $user->name === 'Dev'));

it('gives every panel user a My reports page inside the panel, with the page owning the heading and the New report action', function () {
    $client = FilamentTestUser::create(['name' => 'Client', 'email' => 'c@x.com']);
    FeedbackItem::factory()->for($client, 'reporter')->create(['title' => 'Mine']);
    FeedbackItem::factory()->create(['title' => 'Theirs']);

    $html = $this->actingAs($client)->get(MyFeedbackPage::getUrl())->assertOk()->getContent();

    expect($html)->toContain('class="fb-scope fb-reports"')
        ->toContain('Mine')
        ->not->toContain('Theirs')
        ->not->toContain('class="fb-head"')
        // The English subheading holds an apostrophe, which Blade escapes.
        ->toContain(e(__('feedback-board::feedback-board.reports.subheading')))
        ->toMatch("/\\\$dispatch\\((?:'|&#039;)fb-open-report-form(?:'|&#039;)\\)/")
        ->not->toContain(FeedbackBoardPage::getUrl())
        ->not->toContain(FeedbackItemResource::getUrl('index'));
});

it('groups the package pages under one group, translated at request time, with icons and an order', function () {
    $dev = FilamentTestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    app()->setLocale('ar'); // after the panel was built: labels must still follow the request locale

    $html = $this->actingAs($dev)->get(FeedbackBoardPage::getUrl())->assertOk()->getContent();

    expect($html)->toContain('الملاحظات')
        ->toContain('بلاغاتي')
        ->toContain('كل البلاغات')
        ->toContain('لوحة الملاحظات')
        ->toContain('اسحب البطاقة بين الأعمدة');

    expect([MyFeedbackPage::getNavigationIcon(), FeedbackBoardPage::getNavigationIcon(), FeedbackItemResource::getNavigationIcon()])
        ->toBe(['heroicon-o-chat-bubble-left-right', 'heroicon-o-view-columns', 'heroicon-o-inbox-stack'])
        ->and([MyFeedbackPage::getNavigationSort(), FeedbackBoardPage::getNavigationSort(), FeedbackItemResource::getNavigationSort()])->toBe([1, 2, 3]);
});

it('badges My reports with unread replies and the board with open reports', function () {
    $this->travelTo('2026-10-01 09:00:00');
    $dev = FilamentTestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $this->actingAs($dev);
    $mine = FeedbackItem::factory()->for($dev, 'reporter')->create();
    FeedbackItem::factory()->create(['status' => FeedbackStatus::Resolved]);

    expect(MyFeedbackPage::getNavigationBadge())->toBeNull()
        ->and(MyFeedbackPage::getNavigationBadgeColor())->toBe('primary')
        ->and(FeedbackBoardPage::getNavigationBadge())->toBe('1')
        ->and(FeedbackBoardPage::getNavigationBadgeColor())->toBe('gray');

    $this->travel(1)->minutes();
    FeedbackComment::factory()->for($mine, 'item')->create(['author_role' => AuthorRole::Developer]);

    expect(MyFeedbackPage::getNavigationBadge())->toBe('1');
});

it('keeps the board and the resource developer-only', function () {
    $this->actingAs(FilamentTestUser::create(['name' => 'Client', 'email' => 'c@x.com']));

    $this->get(FeedbackBoardPage::getUrl())->assertForbidden();
    $this->get(FeedbackItemResource::getUrl('index'))->assertForbidden();
    $this->get(MyFeedbackPage::getUrl())->assertOk();
});

it('links My reports inside a panel and the standalone page outside one', function () {
    Filament::setCurrentPanel(null);
    expect(Ui::reportsUrl())->toBe(route('feedback-board.page'));

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    expect(Ui::reportsUrl())->toBe(MyFeedbackPage::getUrl())
        ->and(Ui::reportsUrl(7))->toBe(MyFeedbackPage::getUrl().'?report=7');

    Livewire::actingAs(FilamentTestUser::create(['name' => 'Client', 'email' => 'c@x.com']))
        ->test(FeedbackForm::class)
        ->assertSet('reportsUrl', MyFeedbackPage::getUrl());
});

it('leaves My reports out of a panel that turns it off', function () {
    Filament::getPanel('admin')->getPlugin('feedback-board')->myReports(false);
    $client = FilamentTestUser::create(['name' => 'Client', 'email' => 'c@x.com']);

    $this->actingAs($client)->get(MyFeedbackPage::getUrl())->assertForbidden();

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    expect(Ui::reportsUrl(5))->toBe(route('feedback-board.page').'?report=5');
});

it('translates the resource and shows the enum labels as badges', function () {
    config()->set('feedback-board.destinations.github.token', 'token');
    config()->set('feedback-board.destinations.github.repo', 'acme/app');
    app()->setLocale('ar');
    FeedbackItem::factory()->create(['title' => 'Export fails', 'type' => FeedbackType::Feature, 'priority' => FeedbackPriority::Urgent]);

    $html = $this->actingAs(FilamentTestUser::create(['name' => 'Dev', 'email' => 'd@x.com']))
        ->get(FeedbackItemResource::getUrl('index'))->assertOk()->getContent();

    expect($html)->toContain('العنوان')
        ->toContain('اقتراح')
        ->toContain('عاجلة')
        ->toContain('جديد')
        ->toContain('إرسال إلى GitHub')
        ->and(FeedbackItemResource::getModelLabel())->toBe('بلاغ')
        ->and(FeedbackItemResource::getPluralModelLabel())->toBe('بلاغات');
});

it('keeps the panel browsable before the new migration has run, with one warning', function () {
    Schema::table('feedback_items', fn (Blueprint $table) => $table->dropColumn(['reporter_read_at', 'reporter_activity_at']));

    try {
        Log::spy();
        $dev = FilamentTestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);

        $this->actingAs($dev)->get(FeedbackBoardPage::getUrl())->assertOk();
        $this->actingAs($dev)->get(FeedbackItemResource::getUrl('index'))->assertOk();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'feedback-board'))
            ->once();
    } finally {
        // The migration's rollback at teardown drops these columns again.
        Schema::table('feedback_items', function (Blueprint $table) {
            $table->timestamp('reporter_read_at')->nullable();
            $table->timestamp('reporter_activity_at')->nullable();
        });
    }
});
