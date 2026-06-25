<?php

it('boots the package and loads config', function () {
    expect(config('feedback-board.project'))->toBe('default');
});
