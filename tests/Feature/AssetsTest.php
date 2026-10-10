<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use YousefAman\FeedbackBoard\Support\Ui;

it('serves the stylesheet to guests with long-lived caching for the current version', function () {
    $response = $this->get(Ui::stylesheetUrl());

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/css; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->getContent())->toBe(file_get_contents(Ui::stylesheetPath()))
        ->and($response->headers->get('Cache-Control'))->toContain('public')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=31536000')
        ->and($response->headers->get('Cache-Control'))->toContain('immutable');
});

it('does not pin a stale, missing or malformed version', function (string $query) {
    $response = $this->get('/feedback-board/assets/stylesheet'.$query);

    $response->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-cache')
        ->and($response->headers->get('Cache-Control'))->not->toContain('immutable');
})->with(['', '?id=000000000000', '?id[]=x']);

it('registers the stylesheet route without session or auth middleware', function () {
    $route = app('router')->getRoutes()->getByName('feedback-board.assets.css');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('feedback-board/assets/stylesheet')
        ->and($route->gatherMiddleware())->toBe([]);
});

it('renders nothing in place from the assets partial', function () {
    expect(trim(view('feedback-board::partials.assets')->render()))->toBe('');
});

it('injects the stylesheet into the head of a full HTML page exactly once', function () {
    Route::get('/fb-test-page', fn () => Blade::render(
        '<!doctype html><html><head><title>t</title></head><body>'
        .'@include(\'feedback-board::partials.assets\')<main>x</main>@include(\'feedback-board::partials.assets\')'
        .'</body></html>'
    ));

    $html = $this->get('/fb-test-page')->assertOk()->getContent();
    $link = '<link rel="stylesheet" href="'.Ui::stylesheetUrl().'"';

    expect(substr_count($html, $link))->toBe(1)
        ->and(strpos($html, $link))->toBeLessThan(strpos($html, '</head>'));
});

it('adds no stylesheet to a page without a package surface', function () {
    Route::get('/fb-test-plain', fn () => Blade::render('<!doctype html><html><head></head><body>plain</body></html>'));

    expect($this->get('/fb-test-plain')->assertOk()->getContent())->not->toContain('assets/stylesheet');
});

it('carries the Vite CSP nonce on the stylesheet link when one is set', function () {
    Vite::useCspNonce('fb-nonce-123');
    Route::get('/fb-test-nonce', fn () => Blade::render(
        '<!doctype html><html><head></head><body>@include(\'feedback-board::partials.assets\')</body></html>'
    ));

    expect($this->get('/fb-test-nonce')->getContent())->toContain('nonce="fb-nonce-123"');
});
