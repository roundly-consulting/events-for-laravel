<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
use RoundlyConsulting\Events\Listeners\OnEventTransitioned;
use RoundlyConsulting\Events\Listeners\OnEventTransitioning;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventsConfig;
use RoundlyConsulting\Events\Support\LifecycleCheck;
use RoundlyConsulting\Events\Support\SqliteGeometry;
use RoundlyConsulting\Events\Support\TransitionNotes;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitioned;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitioning;
use RoundlyConsulting\PackageToolkit\Concerns\RegistersBlueprintMacros;
use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;
use Throwable;

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
        $this->app->singleton(SqliteGeometry::class);
        // Per request / job: what an action tells the lifecycle bridge about its transition.
        $this->app->scoped(TransitionNotes::class);
    }

    public function boot(): void
    {
        parent::boot();

        // The migrations use the toolkit's key-type macros; lifecycle, tags, translatable,
        // sluggable and money register theirs from their own providers.
        $this->registerBlueprintMacros();

        $events = $this->app->make(Dispatcher::class);
        $events->listen(LifecycleTransitioning::class, OnEventTransitioning::class);
        $events->listen(LifecycleTransitioned::class, OnEventTransitioned::class);

        $this->registerPolicies();
    }

    /**
     * The event and venue policies, unless the host turned registration off. A class that does not
     * exist registers nothing — never a boot failure (`events:doctor` reports it).
     */
    private function registerPolicies(): void
    {
        try {
            if (! EventsConfig::registerPolicy()) {
                return;
            }

            $gate = $this->app->make(Gate::class);

            foreach ([EventModels::event() => EventsConfig::policy(), EventModels::venue() => EventsConfig::venuePolicy()] as $model => $policy) {
                if ($policy !== null && class_exists($policy)) {
                    $gate->policy($model, $policy);
                }
            }
        } catch (Throwable) {
            // A misconfigured key surfaces on use and in events:doctor, never while booting.
        }
    }
}
