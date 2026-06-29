<?php

it('ships a disabled runner block with sane defaults', function () {
    expect(config('feedback-board.runner.enabled'))->toBeFalse()
        ->and(config('feedback-board.runner.timeout'))->toBe(8)
        ->and(config('feedback-board.runner.max_analysis_chars'))->toBe(10000)
        ->and(config('feedback-board.runner.max_diff_chars'))->toBe(20000)
        ->and(config('feedback-board.runner.stale_after'))->toBe(1800);
});
