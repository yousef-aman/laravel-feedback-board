<?php

namespace YousefAman\FeedbackBoard\Tests\Guards;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/** A client on the `client` guard: its own model and table, like ecosail-web's Client. */
class TestClient extends Authenticatable implements FilamentUser
{
    use Notifiable;

    protected $table = 'clients';
    protected $guarded = [];
    public $timestamps = false;

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'client';
    }
}
