<?php

namespace YousefAman\FeedbackBoard\Tests\Guards;

use Filament\Http\Middleware\{Authenticate, DisableBladeIconComponents, DispatchServingFilamentEvent};
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\{AddQueuedCookiesToResponse, EncryptCookies};
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use YousefAman\FeedbackBoard\Filament\FeedbackBoardPlugin;

/** What both test panels share: the plugin, the BODY_END launcher (README) and the usual middleware. */
abstract class GuardsPanelProvider extends PanelProvider
{
    protected function shared(Panel $panel): Panel
    {
        return $panel
            ->plugin(FeedbackBoardPlugin::make())
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => Blade::render('<x-feedback-board::launcher />'))
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                ShareErrorsFromSession::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Authenticate::class]);
    }
}
