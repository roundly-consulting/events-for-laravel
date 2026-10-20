<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use RoundlyConsulting\Events\Commands\DoctorCommand;
use RoundlyConsulting\Events\Commands\GeocodeCommand;
use RoundlyConsulting\Events\Commands\InstallCommand;
use RoundlyConsulting\Events\Commands\RepairCommand;
use RoundlyConsulting\Events\Commands\SweepCommand;
use RoundlyConsulting\Events\Contracts\CheckInCodes;
use RoundlyConsulting\Events\Contracts\CollectsAdmissionPayment;
use RoundlyConsulting\Events\Contracts\EventUrls;
use RoundlyConsulting\Events\Enums\EventTagType;
use RoundlyConsulting\Events\Events\AttendeeConfirmed;
use RoundlyConsulting\Events\Events\AttendeePromoted;
use RoundlyConsulting\Events\Events\EventCancelled;
use RoundlyConsulting\Events\Events\EventMovedOnline;
use RoundlyConsulting\Events\Events\EventPostponed;
use RoundlyConsulting\Events\Events\EventPublished;
use RoundlyConsulting\Events\Events\EventRescheduled;
use RoundlyConsulting\Events\Events\EventUpdated;
use RoundlyConsulting\Events\Events\InvitationSent;
use RoundlyConsulting\Events\Lifecycle\EventLifecycle;
use RoundlyConsulting\Events\Listeners\OnEventTransitioned;
use RoundlyConsulting\Events\Listeners\OnEventTransitioning;
use RoundlyConsulting\Events\Listeners\SendEventNotifications;
use RoundlyConsulting\Events\Payments\NoPaymentCollector;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventsConfig;
use RoundlyConsulting\Events\Support\LifecycleCheck;
use RoundlyConsulting\Events\Support\RandomCheckInCodes;
use RoundlyConsulting\Events\Support\SqliteGeometry;
use RoundlyConsulting\Events\Support\TemplateEventUrls;
use RoundlyConsulting\Events\Support\TransitionNotes;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitioned;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitioning;
use RoundlyConsulting\PackageToolkit\Concerns\RegistersBladeDirectives;
use RoundlyConsulting\PackageToolkit\Concerns\RegistersBlueprintMacros;
use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;
use RoundlyConsulting\Tags\TagsManager;
use Throwable;

final class EventsServiceProvider extends PackageServiceProvider
{
    use RegistersBladeDirectives;
    use RegistersBlueprintMacros;

    public function configurePackage(Package $package): void
    {
        $package
            ->name('events')
            ->hasConfigFile()
            ->hasMigrations()
            ->hasTranslations()
            ->hasViews()
            ->hasRoutes('events.php', 'events.routes.enabled')
            ->hasCommands([
                InstallCommand::class,
                DoctorCommand::class,
                SweepCommand::class,
                RepairCommand::class,
                GeocodeCommand::class,
            ])
            ->contributesToAbout($this->aboutSection(...));
    }

    public function register(): void
    {
        parent::register();
        self::keepUnsetRoutesSwitchOff();

        $this->app->singleton(EventsManager::class);
        $this->bindFromConfig(CollectsAdmissionPayment::class, 'events.payments.collector', NoPaymentCollector::class);
        $this->app->bindIf(CheckInCodes::class, RandomCheckInCodes::class);
        $this->bindFromConfig(EventUrls::class, 'events.urls.resolver', TemplateEventUrls::class);
        $this->app->singleton(LifecycleCheck::class);
        $this->app->singleton(SqliteGeometry::class);
        // Per request / job: what an action tells the lifecycle bridge about its transition.
        $this->app->scoped(TransitionNotes::class);
    }

    public function boot(): void
    {
        // Again before the toolkit reads the switch: config may change between register and boot.
        self::keepUnsetRoutesSwitchOff();

        parent::boot();

        // The migrations use the toolkit's key-type macros; lifecycle, tags, translatable,
        // sluggable and money register theirs from their own providers.
        $this->registerBlueprintMacros();

        $events = $this->app->make(Dispatcher::class);
        $events->listen(LifecycleTransitioning::class, OnEventTransitioning::class);
        $events->listen(LifecycleTransitioned::class, OnEventTransitioned::class);

        // Notices, after the commit (the domain events are ShouldDispatchAfterCommit).
        foreach ([
            InvitationSent::class => 'invited',
            EventPublished::class => 'published',
            AttendeeConfirmed::class => 'confirmed',
            AttendeePromoted::class => 'promoted',
            EventUpdated::class => 'updated',
            EventPostponed::class => 'postponed',
            EventRescheduled::class => 'rescheduled',
            EventCancelled::class => 'cancelled',
            EventMovedOnline::class => 'movedOnline',
        ] as $event => $method) {
            $events->listen($event, [SendEventNotifications::class, $method]);
        }

        $this->registerPolicies();
        $this->defineTagTypes();
        $this->registerRateLimiters();

        // @eventJsonLd($event): the schema.org script tag, or nothing.
        $this->registerBladeDirective('eventJsonLd', static fn (string $expression): string => "<?php echo ({$expression})->renderJsonLd(); ?>");
    }

    /**
     * `php artisan about`: shape, never secrets — key types, the lifecycle, the API and notices,
     * reminders, the payment collector, geocoding and slug history.
     *
     * @return array<string, string>
     */
    private function aboutSection(): array
    {
        $collector = config('events.payments.collector');
        $offsets = config('events.reminders.offsets');

        return [
            'Key type' => sprintf('%s (events), %s (people)', (string) config('events.primary_key_type', 'bigint'), (string) config('events.key_type', 'bigint')),
            'Lifecycle' => class_basename((string) (config('events.lifecycle') ?: EventLifecycle::class)),
            'Routes' => EventsConfig::routesEnabled() ? 'ON' : 'OFF',
            'Notifications' => EventsConfig::notificationsEnabled() ? 'ON ('.implode(', ', EventsConfig::notificationChannels()).')' : 'OFF',
            'Reminders' => is_array($offsets) && $offsets !== [] ? implode(', ', array_map('strval', $offsets)) : 'NONE',
            'Payments' => class_basename(is_string($collector) && $collector !== '' ? $collector : NoPaymentCollector::class),
            'Geocoding' => EventsConfig::geocodeOnCreate() ? 'ON' : 'OFF',
            'Slug history' => EventsConfig::slugHistory() ? 'ON' : 'OFF',
        ];
    }

    /**
     * The JSON API is opt-in, but the toolkit reads a switch that is not set as on: a published
     * config that overrides only part of `routes` (the merge is shallow), `null`, or a bare
     * `env()` with the variable unset would load every route. Interim guard (tags' precedent):
     * replace it with `hasRoutes(..., enabledByDefault: false)` once the toolkit ships it.
     */
    private static function keepUnsetRoutesSwitchOff(): void
    {
        $enabled = config('events.routes.enabled');

        if ($enabled === null || (is_string($enabled) && trim($enabled) === '')) {
            config()->set('events.routes.enabled', 'off');
        }
    }

    /**
     * The API's limits, per minute: the API as a whole, RSVP answers (per user and event), door
     * scans (bursty) and calendar feeds (per IP). Only with the routes on.
     */
    private function registerRateLimiters(): void
    {
        if (! EventsConfig::routesEnabled()) {
            return;
        }

        $who = static fn (Request $request): string => (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());

        RateLimiter::for('events-api', static fn (Request $request): Limit => Limit::perMinute(EventsConfig::apiRateLimit())->by('api:'.$who($request)));
        RateLimiter::for('events-rsvp', static fn (Request $request): Limit => Limit::perMinute(EventsConfig::rsvpRateLimit())
            ->by('rsvp:'.$who($request).':'.(string) $request->route('event')));
        RateLimiter::for('events-check-in', static fn (Request $request): Limit => Limit::perMinute(EventsConfig::checkInRateLimit())->by('check-in:'.$who($request)));
        RateLimiter::for('events-feeds', static fn (Request $request): Limit => Limit::perMinute(EventsConfig::feedsRateLimit())->by('feeds:'.(string) $request->ip()));
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
