<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Events\DataTransferObjects\RepairOptions;
use RoundlyConsulting\Events\Events\EventPublished;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Support\EventCounters;
use RoundlyConsulting\Events\Tests\Fixtures\Lifecycles\ScheduledFirstLifecycle;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\Scenarios;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\Enum\GeolocationType;
use RoundlyConsulting\Geolocation\GeolocationManager;
use RoundlyConsulting\Money\Money;

beforeEach(fn () => Scenarios::freeze());
afterEach(fn () => Scenarios::thaw());

it('describes itself in about, without secrets', function (): void {
    config()->set('events.reminders.offsets', ['P1D']);

    expect('events')->toLeakNoSecrets(secrets: [], mustRender: ['Lifecycle', 'Routes', 'Key type', 'Payments', 'EventLifecycle', 'NoPaymentCollector', 'OFF', 'P1D']);
});

it('sweeps from the command line', function (): void {
    $event = Scenarios::event(capacity: 5);

    expect(Artisan::call('events:sweep', ['--limit' => 10]))->toBe(0)
        ->and(Artisan::output())->toContain('Holds expired')->toContain('Reminders sent');
    expect($event->exists)->toBeTrue();
});

it('repairs drifted counters and orphaned rsvps', function (): void {
    $event = Scenarios::event(capacity: 5);
    $kept = User::factory()->create();
    $gone = User::factory()->create();
    Events::for($event)->rsvp($kept)->going();
    Events::for($event)->rsvp($gone)->going();

    // A hand-made drift and a mass delete (no model events).
    DB::table($event->getTable())->where('id', $event->getKey())->update(['seats_taken' => 4, 'going_count' => 9]);
    User::query()->whereKey($gone->getKey())->forceDelete();

    $dry = Events::repair(new RepairOptions(dryRun: true));
    expect($dry->countersFixed)->toBe(1)
        ->and($dry->orphansReleased)->toBe(1)
        ->and($event->fresh()?->going_count)->toBe(9);

    expect(Artisan::call('events:repair'))->toBe(0)
        ->and(Artisan::output())->toContain('Counters fixed');

    expect(EventCounters::stored($event))->toEqual(EventCounters::recount($event))
        ->and($event->fresh()?->going_count)->toBe(1)
        ->and(Rsvp::query()->where('attendee_id', $gone->getKey())->exists())->toBeFalse();

    expect(Artisan::call('events:repair', ['--counts' => true, '--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('dry run');

    $fake = Events::fake();
    Events::repair(new RepairOptions(orphans: false));
    $fake->assertRepaired();
});

it('geocodes venues from the command line', function (): void {
    $missing = Venue::factory()->withoutCoordinates()->create();
    $located = Venue::factory()->create(['latitude' => 1.0, 'longitude' => 1.0]);
    app()->instance(GeolocationManager::class, new class(app()) extends GeolocationManager
    {
        public function locateAddress(string $address): ?Location
        {
            return new Location('Somewhere', 'Street', 'Bratislava', 'SK', 48.1, 17.1, GeolocationType::Geolocation);
        }
    });

    expect(Artisan::call('events:geocode'))->toBe(0)
        ->and(Artisan::output())->toContain('Geocoded');
    expect($missing->fresh()?->coordinates?->latitude)->toBe(48.1)
        ->and($located->fresh()?->coordinates?->latitude)->toBe(1.0);

    expect(Artisan::call('events:geocode', ['--venue' => (string) $located->getKey(), '--force' => true]))->toBe(0);
    expect($located->fresh()?->coordinates?->latitude)->toBe(48.1);
});

it('reports a healthy installation', function (): void {
    config()->set('events.urls.event', 'https://example.com/events/{uuid}');

    expect(Artisan::call('events:doctor'))->toBe(0)
        ->and(Artisan::output())->toContain('Everything looks right');

    expect(Artisan::call('events:doctor', ['--json' => true]))->toBe(0)
        ->and(json_decode(trim(Artisan::output()), true))->toBe([]);
});

it('reports what is broken and what is worth a look', function (): void {
    config()->set('lifecycle.key_type', 'uuid');
    config()->set('events.resources.organizer', stdClass::class);
    config()->set('events.notifications.channels', ['mail', 'database']);
    config()->set('events.routes.enabled', 'on');
    config()->set('events.routes.auth_middleware', []);
    $event = Scenarios::event();
    Events::for($event)->tiers()->add('Paid', price: Money::ofMajor('5', 'EUR'));
    Event::factory()->postponed()->create(['postponed_at' => Scenarios::now()->subDays(120)]);
    $event->forceFill(['timezone' => 'America/New_York'])->saveQuietly();
    DB::table($event->getTable())->where('id', $event->getKey())->update(['going_count' => 7]);

    expect(Artisan::call('events:doctor', ['--json' => true, '--counts' => true]))->toBe(1);
    $checks = array_count_values(array_map(static fn (array $finding): string => $finding['severity'].':'.$finding['check'], json_decode(trim(Artisan::output()), true)));

    expect($checks)->toHaveKeys([
        'error:key_types', 'error:resources',
        'warning:payments', 'warning:urls', 'warning:routes', 'warning:notifications',
        'warning:timezones', 'warning:postponed', 'warning:counters',
    ]);

    expect(Artisan::call('events:doctor'))->toBe(1)
        ->and(Artisan::output())->toContain('[key_types]');
});

it('reports a broken lifecycle and missing tables', function (): void {
    config()->set('events.lifecycle', ScheduledFirstLifecycle::class);
    config()->set('events.tables.hosts', 'event_hosts_missing');
    config()->set('events.slugs.history', 'on');

    expect(Artisan::call('events:doctor', ['--json' => true]))->toBe(1);
    $checks = array_column(json_decode(trim(Artisan::output()), true), 'check');

    expect($checks)->toContain('lifecycle', 'tables');
});

it('refuses to install over an existing table', function (): void {
    expect(Artisan::call('events:install'))->toBe(1)
        ->and(Artisan::output())->toContain('events.tables.events');
});

test('laravel_event_fake_recipe', function (): void {
    // Faking only the events you assert keeps the lifecycle bridge and the cancellation effects.
    Illuminate\Support\Facades\Event::fake([EventPublished::class]);
    $event = Events::make('Recipe')->organizedBy(User::factory()->create())->startsAt('2027-02-12 20:00')->publish();

    Illuminate\Support\Facades\Event::assertDispatched(EventPublished::class);
    expect($event->fresh()?->sequence)->toBe(1);
});

test('lifecycle_fake_is_documented_as_incompatible', function (): void {
    $docs = (string) file_get_contents(__DIR__.'/../../../../docs/packages/events-for-laravel/technical/testing.md');

    expect($docs)->toContain('Lifecycles::fake()')
        ->toContain('Event::fake([')
        ->toContain('never');
})->skip(fn (): bool => ! is_file(__DIR__.'/../../../../docs/packages/events-for-laravel/technical/testing.md'), 'the workspace docs are not next to the package');
