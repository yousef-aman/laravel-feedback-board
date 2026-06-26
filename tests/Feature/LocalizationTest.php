<?php

it('serves Arabic strings and RTL direction', function () {
    app()->setLocale('ar');
    expect(__('feedback-board::feedback-board.dir'))->toBe('rtl')
        ->and(__('feedback-board::feedback-board.status.resolved'))->not->toBe('feedback-board::feedback-board.status.resolved');
});
