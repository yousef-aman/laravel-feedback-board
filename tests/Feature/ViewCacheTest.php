<?php

use Illuminate\Support\Facades\Artisan;

afterEach(fn () => Artisan::call('view:clear'));

it('compiles every package view without Filament registered (php artisan optimize)', function () {
    // The default TestCase installs Filament but does not register its providers,
    // like a host app without Filament.
    expect(Artisan::call('view:cache'))->toBe(0);
});
