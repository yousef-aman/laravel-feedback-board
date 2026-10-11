<?php

use YousefAman\FeedbackBoard\Support\Images;

it('accepts an allow-listed image only when its extension matches its type', function (?string $mime, ?string $name, bool $expected) {
    expect(Images::matches($mime, $name))->toBe($expected);
})->with([
    ['image/png', 'shot.png', true],
    ['image/jpeg', 'photo.JPG', true],
    ['image/jpeg', 'photo.jpeg', true],
    ['image/gif', 'a.gif', true],
    ['image/webp', 'a.webp', true],
    ['image/png', 'shot.jpg', false],
    ['image/svg+xml', 'logo.svg', false],
    ['text/html', 'page.png', false],
    ['image/png', 'no-extension', false],
    [null, 'a.png', false],
    ['image/png', null, false],
]);

it('lists the allow-listed types and extensions', function () {
    expect(Images::mimes())->toBe(['image/jpeg', 'image/png', 'image/gif', 'image/webp'])
        ->and(Images::extensions())->toBe(['jpg', 'jpeg', 'png', 'gif', 'webp', 'jfif', 'jpe']);
});
