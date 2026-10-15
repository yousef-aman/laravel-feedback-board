<?php

namespace YousefAman\FeedbackBoard\Tests\Tenancy;

use Illuminate\Support\Facades\Schema;
use YousefAman\FeedbackBoard\Tests\Filament\{FilamentTestCase, TestPanelProvider};

/**
 * The suite's "admin" panel with ->tenant(Team::class). On boot, Filament registers tenancy hooks
 * for each of the panel's resources that is scoped to the tenant.
 */
class TenancyTestCase extends FilamentTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ...array_values(array_diff(parent::getPackageProviders($app), [TestPanelProvider::class])),
            TenantPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', TenantUser::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::create('teams', function ($t) {
            $t->id();
            $t->string('name');
        });
    }
}
