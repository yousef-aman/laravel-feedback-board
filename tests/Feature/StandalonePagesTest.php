<?php

use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Support\Ui;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(fn () => config()->set('feedback-board.authorization.developer_resolver', fn ($u) => $u->name === 'Dev'));

function fbStylesheetLink(): string
{
    return '<link rel="stylesheet" href="'.Ui::stylesheetUrl().'"';
}

it('serves the reports page as a responsive standalone document with the stylesheet in its head', function () {
    $me = TestUser::create(['name' => 'Me', 'email' => 'me@x.com']);
    FeedbackItem::factory()->for($me, 'reporter')->create(['title' => 'Mine']);

    $html = $this->actingAs($me)->get(route('feedback-board.page'))->assertOk()->getContent();

    expect(substr_count($html, fbStylesheetLink()))->toBe(1)
        ->and(strpos($html, fbStylesheetLink()))->toBeLessThan(strpos($html, '</head>'))
        ->and($html)->toContain('<meta name="viewport" content="width=device-width, initial-scale=1">')
        ->and($html)->toContain('<meta name="color-scheme" content="light">')
        ->and($html)->toContain('<html lang="en" dir="ltr">')
        ->and($html)->toContain('class="fb-scope fb-standalone" data-fb-theme="class"')
        ->and($html)->toContain('fb-standalone__main--narrow')
        ->and($html)->toContain('Mine');
});

it('serves the board page with a heading and the stylesheet in its head', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    FeedbackItem::factory()->create(['title' => 'Board card']);

    $html = $this->actingAs($dev)->get(route('feedback-board.board.page'))->assertOk()->getContent();

    expect(substr_count($html, fbStylesheetLink()))->toBe(1)
        ->and(strpos($html, fbStylesheetLink()))->toBeLessThan(strpos($html, '</head>'))
        ->and($html)->toContain('<h1 class="fb-standalone__title">'.__('feedback-board::feedback-board.board.title').'</h1>')
        ->and($html)->toContain('Board card');
});

it('pins the page color-scheme when the theme is forced', function () {
    $me = TestUser::create(['name' => 'Me', 'email' => 'me@x.com']);
    config()->set('feedback-board.ui.theme', 'dark');

    $html = $this->actingAs($me)->get(route('feedback-board.page'))->assertOk()->getContent();

    expect($html)->toContain('<meta name="color-scheme" content="dark">')
        ->and($html)->toContain('data-fb-theme="dark"');
});

it('renders no duplicate element ids on the reports page with a drawer open', function () {
    $me = TestUser::create(['name' => 'Me', 'email' => 'me@x.com']);
    FeedbackItem::factory()->for($me, 'reporter')->count(2)->create();

    $html = \Livewire\Livewire::actingAs($me)
        ->test(\YousefAman\FeedbackBoard\Livewire\MyReportsBoard::class)
        ->call('open', FeedbackItem::first()->id)
        ->html();

    preg_match_all('/\sid="([^"]+)"/', $html, $ids);

    expect($ids[1])->toContain('fb-drawer-title')
        ->and(array_count_values($ids[1]))->each->toBe(1);
});

it('renders the standalone pages right-to-left in Arabic', function () {
    $me = TestUser::create(['name' => 'Me', 'email' => 'me@x.com']);
    app()->setLocale('ar');

    $html = $this->actingAs($me)->get(route('feedback-board.page'))->assertOk()->getContent();

    expect($html)->toContain('<html lang="ar" dir="rtl">')
        ->and($html)->toContain('dir="rtl"');
});

it('follows the operating system on standalone pages only with the system theme', function () {
    $me = TestUser::create(['name' => 'Me', 'email' => 'me@x.com']);
    config()->set('feedback-board.ui.theme', 'system');

    $html = $this->actingAs($me)->get(route('feedback-board.page'))->assertOk()->getContent();

    expect($html)->toContain('<meta name="color-scheme" content="light dark">')
        ->and($html)->toContain('data-fb-theme="system"');
});
