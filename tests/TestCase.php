<?php

namespace YousefAman\FeedbackBoard\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use YousefAman\FeedbackBoard\FeedbackBoardServiceProvider;

class TestCase extends Orchestra
{
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
    }

    protected function defineDatabaseMigrations(): void
    {
        \Illuminate\Support\Facades\Schema::create('users', function ($t) {
            $t->id(); $t->string('name')->nullable(); $t->string('email')->nullable();
        });
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
