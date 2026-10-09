<?php

// `php artisan config:cache` writes var_export(config) and requires it back; a closure
// anywhere in the config cannot survive that round-trip. This guards the shipped file.
function roundTripsThroughVarExport(array $config): bool
{
    return eval('return '.var_export($config, true).';') === $config;
}

it('ships a config file that survives config:cache', function () {
    $raw = require __DIR__.'/../../config/feedback-board.php';

    expect(roundTripsThroughVarExport($raw))->toBeTrue();
});

it('boots a feedback-board config that survives config:cache', function () {
    expect(roundTripsThroughVarExport(config('feedback-board')))->toBeTrue();
});
