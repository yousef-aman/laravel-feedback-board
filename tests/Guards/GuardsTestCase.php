<?php

namespace YousefAman\FeedbackBoard\Tests\Guards;

use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\{Auth, Schema};
use YousefAman\FeedbackBoard\Tests\Filament\{FilamentTestCase, TestPanelProvider};
use YousefAman\FeedbackBoard\Tests\TestUser;

/**
 * The ecosail shape: staff on the default `web` guard in an "admin" panel, clients on a `client`
 * guard (a `clients` provider) in a "client" panel. The guard config lives here only, so every
 * other test keeps today's setup.
 *
 * The Filament manager is scoped and the default guard is global, and neither resets between
 * requests inside one test. Call nextRequest() before a request that should start fresh.
 */
class GuardsTestCase extends FilamentTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ...array_values(array_diff(parent::getPackageProviders($app), [TestPanelProvider::class])),
            AdminPanelProvider::class,
            ClientPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // TestUser, not testbench's Illuminate\Foundation\Auth\User, which TestClient also extends.
        $app['config']->set('auth.providers.users.model', TestUser::class);
        $app['config']->set('auth.providers.clients', ['driver' => 'eloquent', 'model' => TestClient::class]);
        $app['config']->set('auth.guards.client', ['driver' => 'session', 'provider' => 'clients']);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::create('clients', function ($t) {
            $t->id(); $t->string('name')->nullable(); $t->string('email')->nullable();
        });
    }

    /** Signs in on a guard without making it the default, like a session from another login. */
    protected function signIn(Authenticatable $user, string $guard): static
    {
        Auth::guard($guard)->setUser($user);

        return $this;
    }

    /** As if SetUpPanel ran for this panel (null: outside any panel). */
    protected function inPanel(?string $id): static
    {
        Filament::setCurrentPanel($id === null ? null : Filament::getPanel($id));

        return $this;
    }

    /** What a new request starts with: no panel, and the app's default guard. Sessions stay signed in. */
    protected function nextRequest(): static
    {
        Auth::shouldUse('web');

        return $this->inPanel(null);
    }

    /** The ecosail configuration: both guards recognised, developers on `web` only. */
    protected function staffAndClients(): static
    {
        config()->set('feedback-board.auth.guards', ['web', 'client']);
        config()->set('feedback-board.auth.developer_guards', ['web']);

        return $this;
    }
}
