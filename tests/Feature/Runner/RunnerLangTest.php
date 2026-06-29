<?php

it('has full runner key parity between en and ar', function () {
    $en = trans('feedback-board::feedback-board.runner', [], 'en');
    $ar = trans('feedback-board::feedback-board.runner', [], 'ar');

    expect($en)->toBeArray()->toHaveKeys(['send', 'resend', 'status', 'dispatched', 'running', 'completed', 'failed', 'diff', 'view_result', 'ingest_disabled'])
        ->and(array_keys($ar))->toEqualCanonicalizing(array_keys($en));
});
