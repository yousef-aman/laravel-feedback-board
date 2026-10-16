<?php

namespace YousefAman\FeedbackBoard\Tests\Tenancy;

use Filament\Models\Contracts\{FilamentUser, HasTenants};
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use YousefAman\FeedbackBoard\Tests\TestUser;

/** Every user may use the panel and every team; the package's own gates decide the rest. */
class TenantUser extends TestUser implements FilamentUser, HasTenants
{
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return true;
    }

    public function getTenants(Panel $panel): array|Collection
    {
        return Team::all();
    }
}
