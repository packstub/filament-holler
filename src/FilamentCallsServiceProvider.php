<?php

namespace Packstub\FilamentCalls;

use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Packstub\Calls\Contracts\CallContext;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * The panel side of packstub/calls: the context that answers from the current
 * panel's plugin, the widget's views, labels and assets.
 */
class FilamentCallsServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-calls';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasViews(static::$name)
            ->hasTranslations();
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(PanelContext::class);

        // Whatever the provider order, the panel context wins: the core binds its own only when nothing is bound.
        $this->app->bind(CallContext::class, fn ($app) => $app->make(PanelContext::class));
    }

    public function packageBooted(): void
    {
        // The Alpine wrapper imports ../calls-core.js (@packstub/calls) relative to its own URL
        // (components land one folder deeper), so the core is published but never put in <head>.
        FilamentAsset::register([
            AlpineComponent::make('calls', __DIR__.'/../resources/dist/calls.js'),
            Js::make('calls-core', __DIR__.'/../resources/dist/calls-core.js')->loadedOnRequest(),
            Css::make('calls', __DIR__.'/../resources/dist/calls.css')->loadedOnRequest(),
        ], 'packstub/filament-calls');
    }
}
