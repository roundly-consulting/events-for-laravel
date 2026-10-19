<?php

declare(strict_types=1);

use Carbon\CarbonInterval;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventBus;
use RoundlyConsulting\Events\Casts\UtcDateTime;
use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\Enums\AttendanceMode;
use RoundlyConsulting\Events\Enums\EventStatus;
use RoundlyConsulting\Events\Enums\InvitationStatus;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Events\EventDeleted;
use RoundlyConsulting\Events\EventsManager;
use RoundlyConsulting\Events\Exceptions\EventTransitionDeniedException;
use RoundlyConsulting\Events\Exceptions\InvalidEventException;
use RoundlyConsulting\Events\Exceptions\InvalidEventLifecycleException;
use RoundlyConsulting\Events\Exceptions\InvalidEventScheduleException;
use RoundlyConsulting\Events\Exceptions\InvalidEventsConfigurationException;
use RoundlyConsulting\Events\Exceptions\TierSoldOutException;
use RoundlyConsulting\Events\Exceptions\VenueNotFoundException;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Jobs\GeocodeVenueJob;
use RoundlyConsulting\Events\Lifecycle\EventLifecycle;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\EventHost;
use RoundlyConsulting\Events\Models\EventInvitation;
use RoundlyConsulting\Events\Models\ReminderDelivery;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Support\DistanceExpression;
use RoundlyConsulting\Events\Support\Durations;
use RoundlyConsulting\Events\Support\EventsConfig;
use RoundlyConsulting\Events\Support\LifecycleCheck;
use RoundlyConsulting\Events\Support\WallClock;
use RoundlyConsulting\Events\Tests\Fixtures\Lifecycles\ScheduledFirstLifecycle;
use RoundlyConsulting\Events\Tests\Fixtures\Lifecycles\UncompilableLifecycle;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Fixtures\Payments\FakePaymentCollector;
use RoundlyConsulting\Events\Tests\Support\Scenarios;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\Exceptions\RateLimitExceededException;
use RoundlyConsulting\Geolocation\GeolocationManager;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Decision;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Denial;
use RoundlyConsulting\Lifecycle\Exceptions\TransitionDeniedException;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

beforeEach(fn () => Scenarios::freeze());
afterEach(fn () => Scenarios::thaw());

it('writes portable distance SQL for each engine', function (): void {
    $point = new Coordinates(48.1, 17.1);
    $pgsql = new PostgresConnection(static fn () => null, 'testing', '', ['driver' => 'pgsql']);
    $mysql = new MySqlConnection(static fn () => null, 'testing', '', ['driver' => 'mysql']);

    $pg = DistanceExpression::for($pgsql, 'v.latitude', 'v.longitude', $point);
    $my = DistanceExpression::for($mysql, 'v.latitude', 'v.longitude', $point);

    expect($pg->sql)->toContain('CAST("v"."latitude" AS double precision)')
        ->toContain('LEAST(1.0')
        ->and($pg->bindings)->toBe([48.1, 48.1, 17.1])
        ->and($my->sql)->toContain('CAST(`v`.`latitude` AS DOUBLE)');

    // SQLite registers its function once per connection and answers like PHP.
    $sqlite = DB::connection();
    $sql = DistanceExpression::for($sqlite, 'a', 'b', $point);
    $row = $sqlite->selectOne('select '.str_replace(['"a"', '"b"'], ['?', '?'], $sql->sql).' as km', [48.2, 17.2, ...$sql->bindings]);
    $null = $sqlite->selectOne('select events_distance_km(null, 1, 2, 3) as km');

    expect(abs((float) $row->km - (new Coordinates(48.2, 17.2))->distanceTo($point) / 1000))->toBeLessThan(0.001)
        ->and($null->km)->toBeNull();
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'sqlite', 'the SQLite function runs on sqlite');

it('reads the configuration strictly', function (): void {
    config()->set('events.tables.events', 'Bad Table');
    expect(fn () => EventsConfig::table('events'))->toThrow(InvalidEventsConfigurationException::class, 'events.tables.events');
    config()->set('events.tables.events', 'events');
    expect(fn () => EventsConfig::table('nope'))->toThrow(InvalidEventsConfigurationException::class, 'events.tables.nope');

    config()->set('events.defaults.duration', '2 hours');
    expect(fn () => EventsConfig::defaultDuration())->toThrow(InvalidEventsConfigurationException::class, 'must be an ISO-8601 duration such as PT2H, [2 hours] given');

    config()->set('events.defaults.timezone', 'Mars/Base');
    expect(fn () => EventsConfig::defaultTimezone())->toThrow(InvalidEventsConfigurationException::class, 'events.defaults.timezone');
    config()->set('events.defaults.timezone', ['x']);
    expect(fn () => EventsConfig::defaultTimezone())->toThrow(InvalidEventsConfigurationException::class, '[array] given');
    config()->set('events.defaults.timezone', null);
    config()->set('app.timezone', 'Nowhere');
    expect(EventsConfig::defaultTimezone())->toBe('UTC');

    config()->set('events.lifecycle', '');
    expect(EventsConfig::lifecycle())->toBe(EventLifecycle::class);
    config()->set('events.lifecycle', 42);
    expect(fn () => EventsConfig::lifecycle())->toThrow(InvalidEventLifecycleException::class, '[int] given');

    config()->set('events.poster.variants', 'small');
    expect(fn () => EventsConfig::posterVariants())->toThrow(InvalidConfigurationException::class);
    config()->set('events.poster.variants', ['card' => [800]]);
    expect(fn () => EventsConfig::posterVariants())->toThrow(InvalidConfigurationException::class);
    config()->set('events.poster.variants', null);
    expect(EventsConfig::posterVariants())->toBe([]);

    config()->set('events.organizers', 'user');
    expect(fn () => EventsConfig::organizers())->toThrow(InvalidConfigurationException::class);
    config()->set('events.organizers', null);
    expect(EventsConfig::organizers())->toBe([]);
    config()->set('events.poster.disk', 5);
    expect(fn () => EventsConfig::posterDisk())->toThrow(InvalidConfigurationException::class);

    expect(InvalidEventsConfigurationException::invalidTime('events.reminders.all_day_at', true)->getMessage())->toContain('[true] given')
        ->and(InvalidEventsConfigurationException::invalidDuration('k', 3)->getMessage())->toContain('[3] given')
        ->and(InvalidEventsConfigurationException::invalidDuration('k', null)->getMessage())->toContain('[null] given');
});

it('checks the configured lifecycle once and names what is wrong', function (): void {
    $check = app(LifecycleCheck::class);

    config()->set('events.lifecycle', ScheduledFirstLifecycle::class);
    expect($check->problem(ScheduledFirstLifecycle::class))->toBe('its initial state must be in the Draft phase.')
        ->and(fn () => $check->assertValid())->toThrow(InvalidEventLifecycleException::class, 'Draft phase');

    config()->set('events.lifecycle', UncompilableLifecycle::class);
    expect((string) $check->problem(UncompilableLifecycle::class))->toStartWith('it does not compile');

    config()->set('events.lifecycle', null);
    expect($check->problem(EventLifecycle::class))->toBeNull();
    $check->assertValid();
    $check->assertValid();
});

it('wraps lifecycle denials with their codes', function (): void {
    $event = Scenarios::event();
    Scenarios::freeze($event->ends_at->addHour()->format('Y-m-d H:i:s'));
    $late = Scenarios::event(publish: false);
    $late->forceFill(['starts_at' => Scenarios::now()->subHours(3), 'ends_at' => Scenarios::now()->subHour()])->save();

    try {
        Events::for($late)->publish();
        $this->fail('Expected a denial.');
    } catch (EventTransitionDeniedException $denied) {
        expect($denied->denials())->not->toBeEmpty()
            ->and($denied->status())->toBe(422)
            ->and($denied->retryAfterSeconds())->toBe(0)
            ->and($denied->previousDenial())->toBeInstanceOf(TransitionDeniedException::class);
    }

    $limited = EventTransitionDeniedException::wrap(TransitionDeniedException::because(Decision::deny(Denial::of('rate_limited', retryAfter: Scenarios::now()->addSeconds(30)))));
    expect($limited->status())->toBe(429)
        ->and($limited->retryAfterSeconds())->toBe(30);
});

it('refuses to delete an announced event people are going to', function (): void {
    EventBus::fake([EventDeleted::class]);
    $event = Scenarios::event(capacity: 5);
    Events::for($event)->rsvp(User::factory()->create())->going();

    expect(fn () => Events::for($event)->delete())->toThrow(InvalidEventException::class, 'People are coming to this event.');

    Events::for($event)->cancel();
    Events::for($event)->delete();
    EventBus::assertDispatched(EventDeleted::class);
});

it('covers the manager ticket type change branches', function (): void {
    FakePaymentCollector::reset();
    config()->set('events.payments.collector', FakePaymentCollector::class);
    $event = Scenarios::event(capacity: 10);
    Events::for($event)->tiers()->add('Paid', price: Money::ofMajor('9', 'EUR'), key: 'paid');
    Events::for($event)->tiers()->add('Also paid', price: Money::ofMajor('12', 'EUR'), key: 'paid-2');
    Events::for($event)->tiers()->add('Free', key: 'free');
    Events::for($event)->tiers()->make('Tiny')->key('tiny')->capacity(0)->create();
    $user = User::factory()->create();
    $held = Events::for($event)->rsvp($user)->going(tier: 'paid');

    // Same ticket type: nothing to do.
    expect(Events::for($event)->attendees()->changeTier($user, 'paid')->is($held))->toBeTrue();

    // No room on the target.
    expect(fn () => Events::for($event)->attendees()->changeTier($user, 'tiny'))->toThrow(TierSoldOutException::class);

    // Held on a paid type, moved to another paid type: re-priced, new request queued.
    expect(Events::for($event)->attendees()->changeTier($user, 'paid-2')->amount?->toDecimal())->toBe('12.00');

    // Held, moved to a free type: going at once.
    expect(Events::for($event)->attendees()->changeTier($user, 'free')->status)->toBe(RsvpStatus::Going);

    // Changing nothing and a manager's guest change on a free RSVP.
    expect(Events::for($event)->attendees()->changeGuests($user, 0)->guests)->toBe(0);
});

it('reads the remaining model helpers', function (): void {
    $event = Scenarios::event(capacity: 2);
    $delivery = ReminderDelivery::factory()->for($event)->create();
    $invitation = EventInvitation::factory()->for($event)->invitee(User::factory()->create())->create();
    $host = EventHost::factory()->for($event)->host(User::factory()->create())->create(['added_by_type' => (new User)->getMorphClass(), 'added_by_id' => User::factory()->create()->getKey()]);

    expect($delivery->event?->is($event))->toBeTrue()
        ->and($delivery->recipients)->toBe(0)
        ->and($invitation->isPending())->toBeTrue()
        ->and($invitation->invitedBy)->toBeNull()
        ->and($invitation->status)->toBe(InvitationStatus::Pending)
        ->and($host->addedBy)->toBeInstanceOf(User::class)
        ->and($event->isFull())->toBeFalse()
        ->and($event->seatsLeft())->toBe(2)
        ->and($event->isUpcoming())->toBeTrue()
        ->and($event->isOngoing())->toBeFalse()
        ->and($event->isPast())->toBeFalse()
        ->and($event->venue?->events()->count())->toBe(1)
        ->and(EventStatus::Cancelled->color())->toBe('red')
        ->and(RsvpStatus::Waitlisted->color())->toBe('orange')
        ->and(AttendanceMode::Mixed->schemaOrg())->toBe('https://schema.org/MixedEventAttendanceMode')
        ->and(AttendanceMode::Online->label())->toBe('Online');

    expect(Events::venues()->find(999_999))->toBeNull();
});

it('refuses unreadable times and durations', function (): void {
    expect(Durations::iso('P'))->toBeNull()
        ->and(Durations::lenient('nonsense words'))->toBeNull()
        ->and(Durations::lenient(CarbonInterval::seconds(0)))->toBeNull()
        ->and(fn () => WallClock::toUtc('next blursday', 'UTC'))->toThrow(InvalidEventScheduleException::class);

    $cast = new UtcDateTime;
    $event = new Event;
    expect(fn () => $cast->set($event, 'starts_at', 'tomorrow', []))->toThrow(InvalidEventException::class, 'The starts_at value is not a date and time.')
        ->and(fn () => $cast->get($event, 'starts_at', 'garbage', []))->toThrow(InvalidEventException::class)
        ->and($cast->get($event, 'starts_at', Scenarios::now(), [])?->toIso8601String())->toBe(Scenarios::now()->toIso8601String());
});

it('releases the geocoding job on a rate limit', function (): void {
    $venue = Venue::factory()->withoutCoordinates()->create();
    app()->instance(GeolocationManager::class, new class(app()) extends GeolocationManager
    {
        public function locateAddress(string $address): ?Location
        {
            throw RateLimitExceededException::for('google', 12);
        }
    });

    $job = new GeocodeVenueJob($venue->getKey());
    $queued = new SyncJob(app(), '{}', 'sync', 'default');
    $job->setJob($queued);
    $job->handle(app(EventsManager::class));

    expect($queued->isReleased())->toBeTrue()
        ->and($venue->fresh()?->coordinates)->toBeNull();
});

it('resolves a venue by id and refuses an unknown one on update', function (): void {
    $event = Scenarios::event(publish: false);
    $venue = Venue::factory()->create();

    Events::for($event)->update(new UpdateEventData(venue: $venue->getKey()));
    expect($event->fresh()?->venue_id)->toBe($venue->getKey());

    expect(fn () => Events::for($event)->update(new UpdateEventData(venue: 999_999)))->toThrow(VenueNotFoundException::class);
});
