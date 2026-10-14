<?php

namespace YousefAman\FeedbackBoard\Tests\Guards;

use Filament\Panel;

/** Clients on their own `client` guard. */
class ClientPanelProvider extends GuardsPanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $this->shared($panel->id('client')->path('client')->authGuard('client'));
    }
}
