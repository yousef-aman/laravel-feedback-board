<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FeedbackBoardServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('feedback-board')
            ->hasConfigFile()
            ->hasMigrations([
                'create_feedback_items_table',
                'create_feedback_comments_table',
                'create_feedback_attachments_table',
                'create_feedback_activities_table',
            ])
            ->hasTranslations()
            ->hasViews()
            ->hasRoute('web');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(FeedbackBoard::class);

        $this->app->singleton(
            \YousefAman\FeedbackBoard\Destinations\DestinationManager::class,
            fn ($app) => new \YousefAman\FeedbackBoard\Destinations\DestinationManager($app),
        );

        $this->app->singleton(
            \YousefAman\FeedbackBoard\Triage\TriageManager::class,
            fn ($app) => new \YousefAman\FeedbackBoard\Triage\TriageManager($app),
        );
    }

    public function packageBooted(): void
    {
        // Untyped, non-nullable parameter: Gate denies guests before this runs.
        \Illuminate\Support\Facades\Gate::define(
            'feedback-board.manage',
            fn ($user) => app(FeedbackBoard::class)->resolveDeveloper($user),
        );

        \Illuminate\Support\Facades\Gate::policy(
            \YousefAman\FeedbackBoard\Models\FeedbackItem::class,
            \YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy::class,
        );

        \Illuminate\Support\Facades\Event::subscribe(\YousefAman\FeedbackBoard\Listeners\SendFeedbackNotifications::class);
        \Illuminate\Support\Facades\Event::subscribe(\YousefAman\FeedbackBoard\Listeners\DispatchToDestinations::class);
        \Illuminate\Support\Facades\Event::subscribe(\YousefAman\FeedbackBoard\Listeners\TriageNewFeedback::class);

        // Livewire 4 resolves "namespace::name" through registered class namespaces only;
        // Livewire 3 has no addNamespace() and resolves the explicit registrations below.
        if (method_exists(app('livewire'), 'addNamespace')) {
            \Livewire\Livewire::addNamespace('feedback-board', classNamespace: 'YousefAman\\FeedbackBoard\\Livewire');
        }

        \Livewire\Livewire::component('feedback-board::feedback-form',\YousefAman\FeedbackBoard\Livewire\FeedbackForm::class);
        \Livewire\Livewire::component('feedback-board::my-reports-board', \YousefAman\FeedbackBoard\Livewire\MyReportsBoard::class);
        \Livewire\Livewire::component('feedback-board::feedback-item-modal', \YousefAman\FeedbackBoard\Livewire\FeedbackItemModal::class);
        \Livewire\Livewire::component('feedback-board::feedback-kanban', \YousefAman\FeedbackBoard\Livewire\FeedbackKanban::class);
        \Livewire\Livewire::component('feedback-board::feedback-detail', \YousefAman\FeedbackBoard\Livewire\FeedbackDetail::class);
        // anonymous components in resources/views/components are auto-registered via ->hasViews('feedback-board')

        // Filament-only views (they use <x-filament-panels::*>) live outside resources/views so
        // `php artisan view:cache` does not compile them in apps without Filament.
        if ($this->app->providerIsLoaded(\Filament\FilamentServiceProvider::class)) {
            $this->loadViewsFrom(__DIR__.'/../resources/views-filament', 'feedback-board');
            $this->publishes(
                [__DIR__.'/../resources/views-filament' => resource_path('views/vendor/feedback-board')],
                'feedback-board-views',
            );
        }
    }
}
