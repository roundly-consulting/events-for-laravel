<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event as EventBus;
use RoundlyConsulting\Events\DataTransferObjects\AddressData;
use RoundlyConsulting\Events\DataTransferObjects\UpdateVenueData;
use RoundlyConsulting\Events\Events\VenueDeleted;
use RoundlyConsulting\Events\Events\VenueGeocoded;
use RoundlyConsulting\Events\Events\VenueGeocodingFailed;
use RoundlyConsulting\Events\Events\VenueRestored;
use RoundlyConsulting\Events\Events\VenueUpdated;
use RoundlyConsulting\Events\EventsManager;
use RoundlyConsulting\Events\Exceptions\InvalidEventScheduleException;
use RoundlyConsulting\Events\Exceptions\InvalidEventsConfigurationException;
use RoundlyConsulting\Events\Exceptions\InvalidVenueException;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Jobs\GeocodeVenueJob;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\Enum\GeolocationType;
use RoundlyConsulting\Geolocation\Facades\Geolocation;

function location(float $lat, float $lng, GeolocationType $type = GeolocationType::Geolocation): Location
{
    return new Location('Somewhere', 'Street', 'Bratislava', 'SK', $lat, $lng, $type);
}

test('venue_coordinates_are_validated', function (): void {
    $venue = Events::venues()->make(['en' => 'Blue Note', 'sk' => 'Blue Note'])
        ->address(street: 'Hlavná 1', city: 'Bratislava', postalCode: '811 01', country: 'sk')
        ->coordinates(48.1439, 17.1097)
        ->timezone('Europe/Bratislava')
        ->create();

    expect($venue->coordinates)->toBeInstanceOf(Coordinates::class)
        ->and($venue->coordinates?->latitude)->toBe(48.1439)
        ->and($venue->country_code)->toBe('SK')
        ->and($venue->formattedAddress())->toBe('Hlavná 1, 811 01 Bratislava, SK')
        ->and($venue->slug)->toBe('blue-note');

    expect(fn () => Events::venues()->make('X')->coordinates(48.0, 181.0))->toThrow(InvalidVenueException::class, 'out of range')
        ->and(fn () => Events::venues()->make('X')->coordinates(-91.0, 0.0))->toThrow(InvalidVenueException::class)
        ->and(fn () => Events::venues()->make('X')->coordinates(new Coordinates(1.0, 2.0))->address(country: 'Slovakia')->create())
        ->toThrow(InvalidVenueException::class, 'The country "SLOVAKIA" is not a two-letter ISO code.');
});

test('venue_rules_need_a_name_and_a_valid_timezone', function (): void {
    expect(fn () => Events::venues()->make('')->create())->toThrow(InvalidVenueException::class, 'The venue needs a name.')
        ->and(fn () => Events::venues()->make('X')->timezone('Mars/Base'))->toThrow(InvalidEventScheduleException::class)
        ->and(fn () => Events::venues()->make('X')->capacity(-1))->toThrow(InvalidVenueException::class);

    // Without one, the timezone is events.venues.default_timezone, else the app's.
    expect(Events::venues()->make('A')->create()->timezone)->toBe('UTC');
    config()->set('events.venues.default_timezone', 'Europe/Vienna');
    expect(Events::venues()->make('B')->create()->timezone)->toBe('Europe/Vienna');
    config()->set('events.venues.default_timezone', 'Nowhere/Land');
    expect(fn () => Events::venues()->make('C')->create())->toThrow(InvalidEventsConfigurationException::class, 'events.venues.default_timezone');
});

test('venue_rules_refuse_deleting_a_venue_in_use', function (): void {
    EventBus::fake([VenueDeleted::class, VenueRestored::class]);
    $venue = Venue::factory()->create();
    $user = User::factory()->create();

    $draft = Event::factory()->at($venue)->create();
    expect(fn () => Events::venues()->delete($venue))->toThrow(InvalidVenueException::class, 'Events that are still on use this venue.');

    $draft->delete();
    // A postponed event whose original date passed is still alive.
    $postponed = Event::factory()->at($venue)->postponed()->create(['starts_at' => now()->subDays(10), 'ends_at' => now()->subDays(10)->addHours(2)]);
    expect(fn () => Events::venues()->delete($venue))->toThrow(InvalidVenueException::class);

    $postponed->delete();
    Event::factory()->at($venue)->completed()->create();
    Event::factory()->at($venue)->cancelled()->create();
    Events::venues()->by($user)->delete($venue);

    expect($venue->fresh()?->trashed())->toBeTrue();
    EventBus::assertDispatched(VenueDeleted::class);

    Events::venues()->restore($venue);
    expect($venue->fresh()?->trashed())->toBeFalse();
    EventBus::assertDispatched(VenueRestored::class);
});

test('past_events_keep_a_trashed_venue', function (): void {
    $venue = Venue::factory()->create(['name' => ['en' => 'Old Hall']]);
    $past = Event::factory()->at($venue)->completed()->create();

    Events::venues()->delete($venue);

    expect($past->fresh()?->venue?->name)->toBe('Old Hall')
        ->and($past->fresh()?->venue?->trashed())->toBeTrue();
});

it('updates only the fields given', function (): void {
    EventBus::fake([VenueUpdated::class]);
    $venue = Venue::factory()->create(['timezone' => 'Europe/Bratislava']);
    $event = Event::factory()->at($venue)->create();

    Events::venues()->update($venue, new UpdateVenueData(
        name: ['en' => 'New Hall'],
        address: new AddressData(street: 'Main 2', city: 'Košice', country: 'sk'),
        coordinates: null,
        timezone: 'Europe/Prague',
        capacity: 300,
        meta: ['floor' => 2],
    ));

    $fresh = $venue->fresh();
    expect($fresh?->name)->toBe('New Hall')
        ->and($fresh?->city)->toBe('Košice')
        ->and($fresh?->coordinates)->toBeNull()
        ->and($fresh?->capacity)->toBe(300)
        ->and($event->fresh()?->timezone)->toBe('Europe/Bratislava');

    EventBus::assertDispatched(VenueUpdated::class, static fn (VenueUpdated $updated): bool => in_array('timezone', $updated->changed, true));

    EventBus::fake([VenueUpdated::class]);
    Events::venues()->update($venue, new UpdateVenueData(capacity: 300));
    EventBus::assertNotDispatched(VenueUpdated::class);
});

test('geocoding_fills_missing_coordinates_only', function (): void {
    EventBus::fake([VenueGeocoded::class]);
    Geolocation::fake()->seed('Námestie slobody, Bratislava, SK', location(48.1530, 17.1130));

    $park = Events::venues()->make(['en' => 'City Park', 'sk' => 'Mestský park'])
        ->address(street: 'Námestie slobody', city: 'Bratislava', country: 'SK')
        ->timezone('Europe/Bratislava')
        ->geocode()
        ->create();

    expect($park->coordinates?->latitude)->toBe(48.153)
        ->and($park->geocoded_at)->not->toBeNull()
        ->and($park->timezone)->toBe('Europe/Bratislava');
    EventBus::assertDispatched(VenueGeocoded::class);

    // Existing coordinates stay unless forced.
    $known = Venue::factory()->at(1.0, 2.0)->create(['street' => 'Námestie slobody', 'city' => 'Bratislava', 'postal_code' => null, 'country_code' => 'SK']);
    Events::venues()->geocode($known);
    expect($known->fresh()?->coordinates?->latitude)->toBe(1.0);

    Events::venues()->geocode($known, force: true);
    expect($known->fresh()?->coordinates?->latitude)->toBe(48.153);
});

test('geocoding_failure_keeps_the_venue', function (): void {
    EventBus::fake([VenueGeocodingFailed::class]);
    Geolocation::fake();

    $venue = Venue::factory()->withoutCoordinates()->create();
    Events::venues()->geocode($venue);

    expect($venue->fresh()?->coordinates)->toBeNull()
        ->and($venue->fresh()?->geocoded_at)->toBeNull();
    EventBus::assertDispatched(VenueGeocodingFailed::class);

    // A venue without any address is not looked up at all.
    $blank = Venue::factory()->withoutCoordinates()->create(['street' => null, 'city' => null, 'postal_code' => null, 'country_code' => null]);
    Events::venues()->geocode($blank);
    expect($blank->fresh()?->coordinates)->toBeNull();
});

test('geocoding_never_stores_the_default_location', function (): void {
    EventBus::fake([VenueGeocodingFailed::class]);
    // The pipeline's trailing default provider answers with the host's fallback — not the address.
    Geolocation::fake()->seed('Unknown 1, Nowhere, SK', location(50.0, 14.0, GeolocationType::Default));

    $venue = Venue::factory()->withoutCoordinates()->create(['street' => 'Unknown 1', 'city' => 'Nowhere', 'postal_code' => null, 'country_code' => 'SK']);
    Events::venues()->geocode($venue);

    expect($venue->fresh()?->coordinates)->toBeNull();
    EventBus::assertDispatched(VenueGeocodingFailed::class);
});

it('queues geocoding for new venues when configured', function (): void {
    Bus::fake([GeocodeVenueJob::class]);
    config()->set('events.venues.geocode_on_create', 'on');

    $venue = Events::venues()->make('Queued')->address(city: 'Bratislava')->create();
    Events::venues()->make('Located')->coordinates(48.0, 17.0)->create();

    Bus::assertDispatchedTimes(GeocodeVenueJob::class, 1);
    Bus::assertDispatched(GeocodeVenueJob::class, static fn (GeocodeVenueJob $job): bool => $job->venueId === $venue->getKey());
});

it('runs the geocoding job through the manager', function (): void {
    Geolocation::fake()->seed('Bratislava', location(48.1, 17.1));
    $venue = Venue::factory()->withoutCoordinates()->create(['street' => null, 'postal_code' => null, 'country_code' => null, 'city' => 'Bratislava']);

    (new GeocodeVenueJob($venue->getKey()))->handle(app(EventsManager::class));
    (new GeocodeVenueJob(999_999))->handle(app(EventsManager::class));

    expect($venue->fresh()?->coordinates?->latitude)->toBe(48.1);
});

it('finds venues by owner and distance', function (): void {
    $team = Team::factory()->create();
    $owned = Venue::factory()->ownedBy($team)->at(48.1486, 17.1077)->create();
    $near = Venue::factory()->at(48.20, 17.20)->create();
    Venue::factory()->at(50.0755, 14.4378)->create(); // Prague
    Venue::factory()->withoutCoordinates()->create();

    $here = new Coordinates(48.1486, 17.1077);

    expect(Events::venues()->ownedBy($team)->pluck('id')->all())->toBe([$owned->getKey()])
        ->and(Events::venues()->near($here, 25)->orderBy('id')->pluck('id')->all())->toBe([$owned->getKey(), $near->getKey()])
        ->and(Events::venues()->near($here, 5)->pluck('id')->all())->toBe([$owned->getKey()])
        ->and(Events::venues()->find($near->getKey())?->is($near))->toBeTrue()
        ->and(fn () => Events::venues()->near($here, 0)->get())->toThrow(InvalidVenueException::class)
        ->and(fn () => Events::venues()->near($here, 501)->get())->toThrow(InvalidVenueException::class, 'at most 500 km');
});
