<?php

use YousefAman\FeedbackBoard\Enums\{FeedbackPriority, FeedbackStatus, FeedbackType};
use YousefAman\FeedbackBoard\Support\Ui;

/** @return list<string> every .fb-* class name that has at least one rule */
function fbStyledClasses(): array
{
    $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(Ui::stylesheetPath()));
    preg_match_all('/\.(fb-[a-z0-9_-]*[a-z0-9])/', $css, $m);

    return array_values(array_unique($m[1]));
}

it('styles every static fb- class used in the package views', function () {
    $views = [
        ...glob(__DIR__.'/../../resources/views/*/*.blade.php'),
        ...glob(__DIR__.'/../../resources/views-filament/*/*.blade.php'),
    ];
    // Element ids and the launcher's browser event share the prefix but are not classes.
    $notClasses = ['fb-launcher-title', 'fb-drawer-title', 'fb-launcher-opened', 'fb-overlay-close', 'fb-launcher-panel', 'fb-open-report-form'];
    $used = [];

    foreach ($views as $view) {
        preg_match_all('/(?<![\w-])fb-[a-z0-9_-]*[a-z0-9]/', (string) file_get_contents($view), $m);
        $used = [...$used, ...$m[0]];
    }

    $unstyled = array_values(array_diff(array_unique($used), fbStyledClasses(), $notClasses));
    sort($unstyled);
    $facebookSdk = ['fb-page', 'fb-post', 'fb-comments', 'fb-like', 'fb-video', 'fb-group', 'fb-quote', 'fb-save', 'fb-send', 'fb-follow'];

    expect($views)->not->toBeEmpty()
        ->and($unstyled)->toBe([])
        ->and(array_values(array_intersect(array_unique($used), $facebookSdk)))->toBe([]);
});

it('styles every dynamic badge, dot and comment variant the enums can produce', function () {
    $colors = array_unique([
        ...array_map(fn ($s) => $s->color(), FeedbackStatus::cases()),
        ...array_map(fn ($p) => $p->color(), FeedbackPriority::cases()),
    ]);
    $expected = [
        ...array_map(fn ($c) => 'fb-badge--'.$c, $colors),
        ...array_map(fn ($c) => 'fb-dot--'.$c, array_map(fn ($s) => $s->color(), FeedbackStatus::cases())),
        ...array_map(fn ($t) => 'fb-type-icon--'.$t->value, FeedbackType::cases()),
        ...array_map(fn ($n) => 'fb-avatar--'.$n, range(1, 4)),
    ];

    expect(array_values(array_diff(array_unique($expected), fbStyledClasses())))->toBe([]);
});
