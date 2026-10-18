<?php

namespace YousefAman\FeedbackBoard\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use YousefAman\FeedbackBoard\FeedbackBoardServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Livewire keeps @assets bookkeeping in statics that only flushState() resets.
        app('livewire')->flushState();

        \YousefAman\FeedbackBoard\Support\Ui::useStylesheetPath(null);
        \YousefAman\FeedbackBoard\Support\DeveloperReads::resetMigrationWarning();
    }

    protected function getPackageProviders($app): array
    {
        return [FeedbackBoardServiceProvider::class, \Livewire\LivewireServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('feedback-board.ai.lock_store', 'array');
    }

    protected function defineDatabaseMigrations(): void
    {
        \Illuminate\Support\Facades\Schema::create('users', function ($t) {
            $t->id(); $t->string('name')->nullable(); $t->string('email')->nullable();
        });
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
