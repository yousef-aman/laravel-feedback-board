<?php

it('serves Arabic strings and RTL direction', function () {
    app()->setLocale('ar');
    expect(__('feedback-board::feedback-board.dir'))->toBe('rtl')
        ->and(__('feedback-board::feedback-board.status.resolved'))->not->toBe('feedback-board::feedback-board.status.resolved');
});

it('defines the same translation keys in English and Arabic', function () {
    $flatten = function (array $lines, string $prefix = '') use (&$flatten): array {
        $keys = [];

        foreach ($lines as $key => $value) {
            $keys = is_array($value)
                ? [...$keys, ...$flatten($value, $prefix.$key.'.')]
                : [...$keys, $prefix.$key];
        }

        return $keys;
    };

    $en = $flatten(require __DIR__.'/../../resources/lang/en/feedback-board.php');
    $ar = $flatten(require __DIR__.'/../../resources/lang/ar/feedback-board.php');
    sort($en);
    sort($ar);

    expect($ar)->toBe($en)
        ->and($en)->toContain('close', 'form.attachments_hint', 'board.empty_column', 'role.ai', 'activity.assigned');
});
