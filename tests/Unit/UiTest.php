<?php

use YousefAman\FeedbackBoard\Support\Ui;

it('normalises the configured theme', function (mixed $value, string $expected) {
    config()->set('feedback-board.ui.theme', $value);

    expect(Ui::theme())->toBe($expected);
})->with([
    ['class', 'class'],
    ['system', 'system'],
    ['light', 'light'],
    ['dark', 'dark'],
    ['blue', 'class'],
    ['DARK', 'class'],
    [null, 'class'],
    [1, 'class'],
]);

it('defaults the theme to class', function () {
    expect(config('feedback-board.ui.theme'))->toBe('class')
        ->and(Ui::theme())->toBe('class');
});

it('accepts only http and https URLs as link targets', function (mixed $url, ?string $expected) {
    expect(Ui::safeUrl($url))->toBe($expected);
})->with([
    ['https://app.test/orders?id=1', 'https://app.test/orders?id=1'],
    ['HTTP://APP.TEST/', 'HTTP://APP.TEST/'],
    ['javascript:alert(1)', null],
    ['JaVaScRiPt:alert(1)', null],
    [' javascript:alert(1)', null],
    ["java\tscript:alert(1)", null],
    ['data:text/html,<script>alert(1)</script>', null],
    ['//evil.test/x', null],
    ['/relative/path', null],
    ['', null],
    [null, null],
    [['https://x.test'], null],
]);

it('versions the stylesheet by content and links it absolutely', function () {
    $version = Ui::stylesheetVersion();

    expect($version)->toMatch('/^[0-9a-f]{12}$/')
        ->and($version)->toBe(substr(md5_file(Ui::stylesheetPath()), 0, 12))
        ->and(Ui::stylesheetUrl())->toBe('http://localhost/feedback-board/assets/stylesheet?id='.$version)
        ->and(is_file(Ui::stylesheetPath()))->toBeTrue();
});

it('keeps a sub-directory base path in the stylesheet URL', function () {
    url()->forceRootUrl('http://host.test/sub');

    expect(Ui::stylesheetUrl())->toBe('http://host.test/sub/feedback-board/assets/stylesheet?id='.Ui::stylesheetVersion());
});

it('maps the theme to the page color-scheme', function (string $theme, string $scheme) {
    config()->set('feedback-board.ui.theme', $theme);

    expect(Ui::colorScheme())->toBe($scheme);
})->with([['class', 'light dark'], ['system', 'light dark'], ['light', 'light'], ['dark', 'dark']]);
