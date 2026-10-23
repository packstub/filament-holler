<?php

namespace Packstub\Calls;

use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class CallsServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-calls';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasConfigFile()
            ->hasViews(static::$name)
            ->hasTranslations()
            ->hasMigration('create_calls_table')
            ->runsMigrations();
    }

    public function packageBooted(): void
    {
        // An SDP ends in CRLF and the parser wants it there: no trimming on signals.
        TrimStrings::skipWhen(fn (Request $request): bool => $request->is('*/filament-calls/calls/*/signals'));

        // The Alpine wrapper imports ../calls-core.js (the framework-agnostic client) relative to
        // its own URL (components land one folder deeper), so the core is published but never put in <head>.
        FilamentAsset::register([
            AlpineComponent::make('calls', __DIR__.'/../resources/dist/calls.js'),
            Js::make('calls-core', __DIR__.'/../resources/dist/calls-core.js')->loadedOnRequest(),
            Css::make('calls', __DIR__.'/../resources/dist/calls.css')->loadedOnRequest(),
        ], 'packstub/filament-calls');
    }
}
