<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Events\Contracts\CollectsAdmissionPayment;
use RoundlyConsulting\Events\DataTransferObjects\DoctorFinding;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Enums\FindingSeverity;
use RoundlyConsulting\Events\Events\RsvpHeld;
use RoundlyConsulting\Events\EventsManager;
use RoundlyConsulting\Events\Payments\NoPaymentCollector;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;
use Throwable;

/**
 * The checks behind `events:doctor`. Errors break the package; warnings are worth a look.
 *
 * @internal
 */
final readonly class Doctor
{
    private const array TABLES = ['events', 'venues', 'tiers', 'rsvps', 'hosts', 'invitations', 'reminder_deliveries'];

    public function __construct(
        private Container $container,
    ) {}

    /**
     * @return list<DoctorFinding>
     */
    public function run(bool $counts = false): array
    {
        $findings = [
            ...$this->keyTypes(),
            ...$this->lifecycle(),
            ...$this->tagTypes(),
            ...$this->tables(),
            ...$this->distance(),
            ...$this->classes(),
            ...$this->warnings(),
        ];

        if ($counts) {
            $findings = [...$findings, ...$this->counters()];
        }

        return $findings;
    }

    /**
     * @return list<DoctorFinding>
     */
    private function keyTypes(): array
    {
        $findings = [];
        $primary = config('events.primary_key_type');
        $outbound = config('events.key_type');

        foreach (['lifecycle.key_type' => $primary, 'tags.key_type' => $primary, 'lifecycle.actor_key_type' => $outbound] as $key => $expected) {
            $actual = config($key);

            if ($actual !== null && $actual !== $expected) {
                $findings[] = self::error('key_types', sprintf('%s is [%s] but must equal the events key type [%s].', $key, (string) $actual, (string) $expected));
            }
        }

        if (EventsConfig::tagsScopedByOrganizer() && config('tags.key_type') !== $outbound) {
            $findings[] = self::error('key_types', 'With events.tags.scope = organizer, tags.key_type must equal events.key_type.');
        }

        if (EventsConfig::slugHistory() && config('sluggable.key_type') !== null && config('sluggable.key_type') !== $primary) {
            $findings[] = self::error('key_types', 'With slug history on, sluggable.key_type must equal events.primary_key_type.');
        }

        return $findings;
    }

    /**
     * @return list<DoctorFinding>
     */
    private function lifecycle(): array
    {
        $problem = $this->container->make(LifecycleCheck::class)->problem(EventsConfig::lifecycle());

        return $problem === null ? [] : [self::error('lifecycle', 'The event lifecycle is not usable: '.$problem)];
    }

    /**
     * @return list<DoctorFinding>
     */
    private function tagTypes(): array
    {
        try {
            $manager = $this->container->make(EventsManager::class);

            if (! $manager->categories()->definition()->isSingle()) {
                return [self::error('tag_types', 'events:category must allow one category per event (an override made it allow more).')];
            }

            $manager->labels()->definition();
        } catch (Throwable $exception) {
            return [self::error('tag_types', 'The events tag types are not defined: '.$exception->getMessage())];
        }

        return [];
    }

    /**
     * @return list<DoctorFinding>
     */
    private function tables(): array
    {
        $findings = [];

        foreach (self::TABLES as $name) {
            $table = EventsConfig::table($name);

            if (! Schema::hasTable($table)) {
                $findings[] = self::error('tables', sprintf('The table [%s] is missing: publish events-migrations and migrate.', $table));
            }
        }

        if (EventsConfig::slugHistory() && ! Schema::hasTable('slug_history')) {
            $findings[] = self::error('tables', 'Slug history is on but sluggable\'s slug_history table is missing: publish sluggable-migrations.');
        }

        return $findings;
    }

    /**
     * @return list<DoctorFinding>
     */
    private function distance(): array
    {
        try {
            $connection = DB::connection();
            $sql = DistanceExpression::for($connection, 'probe.latitude', 'probe.longitude', new Coordinates(0.0, 0.0));
            $connection->selectOne('select '.$sql->sql.' as km from (select 0.0 as latitude, 180.0 as longitude) probe', $sql->bindings);
        } catch (Throwable $exception) {
            return [self::error('distance', 'The near-me distance query fails on the default connection: '.$exception->getMessage())];
        }

        return [];
    }

    /**
     * @return list<DoctorFinding>
     */
    private function classes(): array
    {
        $findings = [];
        $checks = [
            'policy' => static fn (): mixed => ($policy = EventsConfig::policy()) === null || class_exists($policy) ? null : throw new \RuntimeException('events.authorization.policy names a missing class.'),
            'venue_policy' => static fn (): mixed => ($policy = EventsConfig::venuePolicy()) === null || class_exists($policy) ? null : throw new \RuntimeException('events.authorization.venue_policy names a missing class.'),
            'resources' => static fn (): array => [
                EventsConfig::organizerResource(), EventsConfig::hostResource(), EventsConfig::attendeeResource(),
                EventsConfig::inviteeResource(), EventsConfig::actorResource(), EventsConfig::venueOwnerResource(),
            ],
            'payments' => fn (): object => $this->container->make(CollectsAdmissionPayment::class),
        ];

        foreach ($checks as $check => $probe) {
            try {
                $probe();
            } catch (Throwable $exception) {
                $findings[] = self::error($check, $exception->getMessage());
            }
        }

        return $findings;
    }

    /**
     * @return list<DoctorFinding>
     */
    private function warnings(): array
    {
        $findings = [];
        $tiers = EventModels::tier();

        if (Schema::hasTable(EventsConfig::table('tiers'))
            && $tiers::query()->whereNotNull('price')->exists()
            && in_array(config('events.payments.collector'), [null, '', NoPaymentCollector::class], true)
            && ! $this->container->make(Dispatcher::class)->hasListeners(RsvpHeld::class)) {
            $findings[] = self::warning('payments', 'Paid ticket types exist, but no payment collector is bound and nothing listens to RsvpHeld: held seats will simply expire.');
        }

        if (EventsConfig::eventUrl() === null) {
            $findings[] = self::warning('urls', 'events.urls.event is blank: JSON-LD, calendar files and notices carry no event link.');
        }

        if (EventsConfig::routesEnabled() && EventsConfig::authMiddleware() === []) {
            $findings[] = self::warning('routes', 'The JSON API is on without events.routes.auth_middleware: writes are open to anyone your other middleware lets through.');
        }

        if (in_array('database', EventsConfig::notificationChannels(), true) && ! Schema::hasTable('notifications')) {
            $findings[] = self::warning('notifications', 'The database channel is on but the notifications table is missing (php artisan make:notifications-table).');
        }

        foreach (EventsConfig::attendees() as $alias => $class) {
            if (! method_exists($class, 'notify')) {
                $findings[] = self::warning('notifications', sprintf('Attendees of type [%s] are not notifiable: they receive no notices.', $alias));
            }
        }

        if (Schema::hasTable(EventsConfig::table('events'))) {
            $events = EventModels::event();
            $mismatched = $events::query()
                ->join(EventsConfig::table('venues').' as ev_doctor_venue', 'ev_doctor_venue.id', '=', (new $events)->qualifyColumn('venue_id'))
                ->whereColumn('ev_doctor_venue.timezone', '!=', (new $events)->qualifyColumn('timezone'))
                ->count();

            if ($mismatched > 0) {
                $findings[] = self::warning('timezones', sprintf('%d event(s) use a timezone other than their venue\'s.', $mismatched));
            }

            try {
                $stale = $events::query()->inPhase(EventPhase::Postponed)->where('postponed_at', '<', Clock::format(Clock::now()->subDays(90)))->count();
            } catch (Throwable) {
                // An unusable lifecycle is already an error above.
                $stale = 0;
            }

            if ($stale > 0) {
                $findings[] = self::warning('postponed', sprintf('%d event(s) have been postponed for more than 90 days: reschedule or cancel them.', $stale));
            }
        }

        return $findings;
    }

    /**
     * @return list<DoctorFinding>
     */
    private function counters(): array
    {
        $drifted = 0;

        EventModels::event()::withTrashed()->chunkById(200, static function ($events) use (&$drifted): void {
            foreach ($events as $event) {
                $drifted += EventCounters::stored($event) == EventCounters::recount($event) ? 0 : 1;
            }
        });

        return $drifted === 0 ? [] : [self::warning('counters', sprintf('%d event(s) have drifted counters: run events:repair --counts.', $drifted))];
    }

    private static function error(string $check, string $message): DoctorFinding
    {
        return new DoctorFinding(FindingSeverity::Error, $check, $message);
    }

    private static function warning(string $check, string $message): DoctorFinding
    {
        return new DoctorFinding(FindingSeverity::Warning, $check, $message);
    }
}
