<?php

namespace YousefAman\FeedbackBoard\Tests\Filament;

use YousefAman\FeedbackBoard\FeedbackBoardServiceProvider;
use YousefAman\FeedbackBoard\Tests\TestCase;

/**
 * Boots a real Filament panel ("admin", see TestPanelProvider) with FeedbackBoardPlugin,
 * on whichever Filament major the lane resolves (4 with Livewire 3, 5 with Livewire 4).
 */
class FilamentTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            \BladeUI\Heroicons\BladeHeroiconsServiceProvider::class,
            \BladeUI\Icons\BladeIconsServiceProvider::class,
            \RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider::class,
            \Filament\FilamentServiceProvider::class,
            \Filament\Actions\ActionsServiceProvider::class,
            \Filament\Forms\FormsServiceProvider::class,
            \Filament\Infolists\InfolistsServiceProvider::class,
            \Filament\Notifications\NotificationsServiceProvider::class,
            \Filament\Schemas\SchemasServiceProvider::class,
            // Must be registered before LivewireServiceProvider: on Filament 4 it binds
            // Livewire's DataStore override; otherwise every render fails in ViewErrorBag::put().
            \Filament\Support\SupportServiceProvider::class,
            \Filament\Tables\TablesServiceProvider::class,
            \Filament\Widgets\WidgetsServiceProvider::class,
            \Livewire\LivewireServiceProvider::class,
            FeedbackBoardServiceProvider::class,
            TestPanelProvider::class,
        ];
    }
}
