<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use YousefAman\FeedbackBoard\Livewire\{FeedbackDetail, FeedbackForm, FeedbackItemModal, FeedbackKanban, MyReportsBoard};
use YousefAman\FeedbackBoard\Support\Ui;
use YousefAman\FeedbackBoard\Tests\TestUser;

it('resolves every feedback-board:: component name on the installed Livewire major', function (string $name, string $class) {
    // Livewire::new() exists on Livewire 3 and 4; Livewire::exists() is 4-only.
    expect(Livewire::new('feedback-board::'.$name))->toBeInstanceOf($class);
})->with([
    ['feedback-form', FeedbackForm::class],
    ['my-reports-board', MyReportsBoard::class],
    ['feedback-item-modal', FeedbackItemModal::class],
    ['feedback-kanban', FeedbackKanban::class],
    ['feedback-detail', FeedbackDetail::class],
]);

it('renders the launcher with the nested feedback form for an authenticated user', function () {
    $this->actingAs(TestUser::create(['name' => 'Client', 'email' => 'c@x.com']));

    $html = Blade::render('<x-feedback-board::launcher />');

    expect($html)->toContain('fb-launcher')
        ->and($html)->toContain('wire:submit="submit"');
});

it('renders nothing from the launcher for a guest, so a sign-in page shows no report button', function () {
    $html = Blade::render('<x-feedback-board::launcher />');

    expect(trim($html))->toBe('');
});

it('adds the stylesheet to the head of a host page that only renders the launcher', function () {
    $this->actingAs(TestUser::create(['name' => 'Client', 'email' => 'c@x.com']));
    Route::get('/fb-host-page', fn () => Blade::render(
        '<!doctype html><html><head><title>Host</title></head><body><main>App</main><x-feedback-board::launcher /></body></html>'
    ));

    $html = $this->get('/fb-host-page')->assertOk()->getContent();
    $link = '<link rel="stylesheet" href="'.Ui::stylesheetUrl().'"';

    expect(substr_count($html, $link))->toBe(1)
        ->and(strpos($html, $link))->toBeLessThan(strpos($html, '</head>'));
});

it('renders the launcher as a themed, direction-aware slide-over trigger', function () {
    $this->actingAs(TestUser::create(['name' => 'Client', 'email' => 'c@x.com']));
    config()->set('feedback-board.ui.theme', 'dark');
    app()->setLocale('ar');

    $html = Blade::render('<x-feedback-board::launcher />');

    expect($html)->toContain('class="fb-scope"')
        ->and($html)->toContain('data-fb-theme="dark"')
        ->and($html)->toContain('dir="rtl"')
        ->and($html)->toContain('aria-haspopup="dialog"')
        ->and($html)->toContain('<span class="fb-launcher__label">أبلغ عن مشكلة</span>')
        ->and($html)->toContain('class="fb-slide-over" id="fb-launcher-panel"')
        ->and($html)->toContain('role="dialog" aria-modal="true" aria-labelledby="fb-launcher-title" x-trap.noscroll="open"')
        ->and($html)->toContain('id="fb-launcher-title"')
        ->and($html)->toContain('x-on:keydown.escape.window="open && (open = false)"')
        ->and($html)->not->toContain('x-transition')
        ->and($html)->not->toContain('fb-modal')
        ->and($html)->toContain("\$dispatch('fb-launcher-opened')")
        ->and($html)->toContain("x-on:fb-launcher-opened.window=\"\$wire.sent && \$wire.set('sent', false)\"")
        ->and($html)->toContain('wire:model="title" maxlength="255" aria-required="true" dir="auto" placeholder="مثال: زر الحفظ لا يستجيب في صفحة الطلبات"')
        ->and($html)->toContain('aria-label="إغلاق"');
});
