<?php

namespace Workbench\App\Providers;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Packstub\FilamentHoller\FilamentHollerPlugin;
use Workbench\App\Filament\Resources\Users\UserResource;

class AdminPanelProvider extends PanelProvider
{
    public function boot(): void
    {
        // HOLLER_HTTPS=1 behind an HTTPS tunnel (cloudflared, for testing on a phone): URLs come out as https.
        if (env('HOLLER_HTTPS')) {
            URL::forceScheme('https');
        }

        // The teammates' avatars (User::getFilamentAvatarUrl): a photo from workbench/resources/avatars,
        // or initials on a colour for someone without one.
        Route::get('/workbench/avatars/{file}', function (string $file) {
            if (preg_match('/^([a-z]+)\.jpg$/', $file, $m) && is_file($path = __DIR__."/../../resources/avatars/{$m[1]}.jpg")) {
                return response()->file($path, ['Cache-Control' => 'public, max-age=86400']);
            }
            abort_unless(preg_match('/^(\p{Lu}{1,2})-([0-9a-f]{6})\.svg$/u', $file, $m), 404);

            return response(
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="#'.$m[2].'"/>'
                .'<text x="32" y="33" dy=".35em" text-anchor="middle" font-family="Inter, system-ui, sans-serif" font-size="24" font-weight="600" fill="#fff">'.$m[1].'</text></svg>',
                200,
                ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'public, max-age=86400'],
            );
        });
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->spa((bool) env('HOLLER_SPA', false))
            ->pages([Dashboard::class])
            ->resources([UserResource::class])
            ->brandName('Acme')
            ->colors(['primary' => Color::Emerald, 'gray' => Color::Zinc])
            ->font('Inter')
            ->plugins([
                // HOLLER_POP_OUT=true|false forces the pop-out; unset, it follows SPA mode.
                FilamentHollerPlugin::make()->popOut(env('HOLLER_POP_OUT') === null ? null : filter_var(env('HOLLER_POP_OUT'), FILTER_VALIDATE_BOOL)),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
