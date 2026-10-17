<?php

namespace YousefAman\FeedbackBoard\Tests\Guards;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use YousefAman\FeedbackBoard\Tests\TestUser;

/** A customer in the `users` table who may open the client panel only: two panels can share its model. */
class PortalUser extends TestUser implements FilamentUser
{
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'client';
    }
}
