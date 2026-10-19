<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
use RoundlyConsulting\Events\Contracts\CheckInCodes;
use RoundlyConsulting\Events\Contracts\CollectsAdmissionPayment;
use RoundlyConsulting\Events\Enums\EventTagType;
use RoundlyConsulting\Events\Listeners\OnEventTransitioned;
use RoundlyConsulting\Events\Listeners\OnEventTransitioning;
use RoundlyConsulting\Events\Payments\NoPaymentCollector;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventsConfig;
use RoundlyConsulting\Events\Support\LifecycleCheck;
use RoundlyConsulting\Events\Support\RandomCheckInCodes;
use RoundlyConsulting\Events\Support\SqliteGeometry;
use RoundlyConsulting\Events\Support\TransitionNotes;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitioned;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitioning;
use RoundlyConsulting\PackageToolkit\Concerns\RegistersBlueprintMacros;
use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;
use RoundlyConsulting\Tags\TagsManager;
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
        $this->bindFromConfig(CollectsAdmissionPayment::class, 'events.payments.collector', NoPaymentCollector::class);
        $this->app->bindIf(CheckInCodes::class, RandomCheckInCodes::class);
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
        $this->defineTagTypes();
    }

    /**
     * The category tree and the labels, declared in tags-for-laravel (no I/O, so
     * `package:discover` works before migrating). A second boot leaves them as they are; a host
     * adjusts them with `Tags::extend()` or `tags.overrides`.
     */
    private function defineTagTypes(): void
    {
        $tags = $this->app->make(TagsManager::class);
        $registry = $tags->registry();

        if (! $registry->has(EventTagType::Category)) {
            $tags->define(EventTagType::Category)
                ->label('events::tags.category')
                ->hierarchical(maxDepth: 3)
                ->single()
                ->curated()
                ->stableSlugs()
                ->globalOnly()
                ->on(EventModels::event());
        }

        if (! $registry->has(EventTagType::Label)) {
            // private(): a label typed on a private event never surfaces in tags' JSON API.
            $tags->define(EventTagType::Label)
                ->label('events::tags.label')
                ->max(10)
                ->freeForm()
                ->private()
                ->on(EventModels::event());
        }
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
