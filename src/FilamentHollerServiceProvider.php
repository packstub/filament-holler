<?php

namespace Packstub\FilamentHoller;

use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Packstub\Holler\Contracts\CallContext;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * The panel side of packstub/holler: the context that answers from the current
 * panel's plugin, the widget's views, labels and assets.
 */
class FilamentHollerServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-holler';

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
        // The Alpine wrapper imports ../holler-core.js (@packstub/holler) relative to its own URL
        // (components land one folder deeper), so the core is published but never put in <head>.
        FilamentAsset::register([
            AlpineComponent::make('holler', __DIR__.'/../resources/dist/holler.js'),
            Js::make('holler-core', __DIR__.'/../resources/dist/holler-core.js')->loadedOnRequest(),
            Css::make('holler', __DIR__.'/../resources/dist/holler.css')->loadedOnRequest(),
        ], 'packstub/filament-holler');
    }
}
