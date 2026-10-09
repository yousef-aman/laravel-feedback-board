<?php

namespace YousefAman\FeedbackBoard\Tests\Filament;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use YousefAman\FeedbackBoard\Tests\TestUser;

/** Panel access is open; the package's own gate decides developer access. */
class FilamentTestUser extends TestUser implements FilamentUser
{
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
