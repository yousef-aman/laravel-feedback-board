<?php

namespace YousefAman\FeedbackBoard\Tests\Guards;

use Filament\Panel;

/** Staff on the app's default `web` guard. */
class AdminPanelProvider extends GuardsPanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $this->shared($panel->default()->id('admin')->path('admin'));
    }
}
