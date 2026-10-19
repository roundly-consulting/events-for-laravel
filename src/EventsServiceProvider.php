<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events;

use RoundlyConsulting\Events\Support\LifecycleCheck;
use RoundlyConsulting\PackageToolkit\Concerns\RegistersBlueprintMacros;
use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;

final class EventsServiceProvider extends PackageServiceProvider
{
    use RegistersBlueprintMacros;

    public function configurePackage(Package $package): void
    {
        $package
            ->name('events')
            ->hasConfigFile()
            ->hasMigrations()
            ->hasTranslations();
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(EventsManager::class);
        $this->app->singleton(LifecycleCheck::class);
    }

    public function boot(): void
    {
        parent::boot();

        // The migrations use the toolkit's key-type macros; lifecycle, tags, translatable,
        // sluggable and money register theirs from their own providers.
        $this->registerBlueprintMacros();
    }
}
