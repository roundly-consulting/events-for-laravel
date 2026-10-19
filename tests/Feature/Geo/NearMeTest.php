<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Events\Exceptions\InvalidEventException;
use RoundlyConsulting\Events\Exceptions\InvalidVenueException;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Support\DistanceExpression;
use RoundlyConsulting\Events\Tests\Support\Scenarios;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;

beforeEach(fn () => Scenarios::freeze());
afterEach(fn () => Scenarios::thaw());

/**
 * The distance SQL evaluated for one stored venue.
 */
function sqlDistanceKm(Venue $venue, Coordinates $point): float
{
    $distance = DistanceExpression::for(DB::connection(), 'v.latitude', 'v.longitude', $point);
    $row = DB::table($venue->getTable().' as v')
        ->where('v.id', $venue->getKey())
        ->selectRaw($distance->sql.' as km', $distance->bindings)
        ->first();

    return (float) $row->km;
}

function venueAt(float $latitude, float $longitude): Venue
{
    return Venue::factory()->create(['latitude' => $latitude, 'longitude' => $longitude]);
}

test('distance_matches_php_within_one_metre', function (): void {
    mt_srand(20261019);
    $pairs = [
        [[48.1439, 17.1097], [48.1439, 17.1097]],      // the same point
        [[89.9999, 0.0], [-89.9999, 180.0]],           // pole to pole
        [[0.0, 179.9999], [0.0, -179.9999]],           // across the antimeridian
        [[-33.8688, 151.2093], [51.5074, -0.1278]],
    ];

    while (count($pairs) < 50) {
        $pairs[] = [
            [mt_rand(-900_000, 900_000) / 10_000, mt_rand(-1_800_000, 1_800_000) / 10_000],
            [mt_rand(-900_000, 900_000) / 10_000, mt_rand(-1_800_000, 1_800_000) / 10_000],
        ];
    }

    foreach ($pairs as [[$lat, $lng], [$pointLat, $pointLng]]) {
        $venue = venueAt($lat, $lng);
        $stored = new Coordinates((float) $venue->fresh()?->latitude, (float) $venue->fresh()?->longitude);
        $point = new Coordinates($pointLat, $pointLng);

        expect(abs(sqlDistanceKm($venue, $point) - $stored->distanceTo($point) / 1000))->toBeLessThan(0.001);
    }
});

test('distance_is_clamped_for_antipodal_points', function (): void {
    $venue = venueAt(0.0, 0.0);

    expect(sqlDistanceKm($venue, new Coordinates(0.0, 180.0)))->toEqualWithDelta(M_PI * DistanceExpression::EARTH_RADIUS_KM, 0.001);
});

test('distance_sort_puts_online_events_last', function (): void {
    $here = new Coordinates(48.1439, 17.1097);
    $near = Event::factory()->published()->at(venueAt(48.15, 17.11))->create();
    $far = Event::factory()->published()->at(venueAt(48.72, 21.26))->create();
    $online = Event::factory()->published()->online()->create();

    $ids = static fn (string $direction): array => Event::query()->orderByDistance($here, $direction)->pluck('id')->all();

    expect($ids('asc'))->toBe([$near->getKey(), $far->getKey(), $online->getKey()])
        ->and($ids('DESC'))->toBe([$far->getKey(), $near->getKey(), $online->getKey()])
        ->and(fn () => Event::query()->orderByDistance($here, 'sideways'))->toThrow(InvalidEventException::class);
});

test('with_distance_adds_a_column', function (): void {
    $here = new Coordinates(48.1439, 17.1097);
    $venue = venueAt(48.72, 21.26);
    $event = Event::factory()->published()->at($venue)->create();
    $online = Event::factory()->published()->online()->create();

    $rows = Event::query()->withDistance($here)->orderBy('id')->get()->keyBy('id');
    $expected = (new Coordinates(48.72, 21.26))->distanceTo($here) / 1000;

    expect((float) $rows[$event->getKey()]->distance_km)->toEqualWithDelta($expected, 0.001)
        ->and($rows[$online->getKey()]->distance_km)->toBeNull()
        // Every event column is still there.
        ->and($rows[$event->getKey()]->uuid)->toBe($event->uuid);

    // An existing select is kept.
    $narrow = Event::query()->select('id')->withDistance($here)->whereKey($event->getKey())->first();
    expect(array_keys($narrow?->getAttributes() ?? []))->toBe(['id', 'distance_km']);
});

test('distance_queries_paginate_on_every_engine', function (): void {
    $here = new Coordinates(48.1439, 17.1097);
    $events = collect([[48.15, 17.11], [48.20, 17.20], [48.30, 17.40], [47.90, 17.00]])
        ->map(fn (array $point): Event => Event::factory()->published()->at(venueAt(...$point))->create());
    Event::factory()->published()->at(venueAt(49.5, 19.0))->create();   // beyond 25 km
    Event::factory()->published()->online()->create();

    $nearest = $events->sortBy(static fn (Event $event): float => (new Coordinates((float) $event->venue?->latitude, (float) $event->venue?->longitude))->distanceTo($here))
        ->map->getKey()->values()->all();

    $page = Events::browse()->near($here, km: 50)->withDistance($here)->orderByDistance($here)->paginate(2);
    $second = Events::browse()->near($here, km: 50)->withDistance($here)->orderByDistance($here)->paginate(2, page: 2);

    expect($page->total())->toBe(4)
        ->and([...$page->pluck('id')->all(), ...$second->pluck('id')->all()])->toBe($nearest)
        ->and($page->first()?->distance_km)->not->toBeNull();
});

test('scopes_accept_named_arguments', function (): void {
    $here = new Coordinates(48.1439, 17.1097);
    $event = Event::factory()->published()->at(venueAt(48.15, 17.11))->create();

    expect(Event::query()->near($here, km: 5)->pluck('id')->all())->toBe([$event->getKey()])
        ->and(Event::query()->near(point: $here, km: 0.1)->count())->toBe(0)
        ->and(Event::query()->orderByDistance($here, direction: 'desc')->count())->toBe(1)
        ->and(Event::query()->withDistance(point: $here)->first()?->distance_km)->not->toBeNull()
        ->and(Event::query()->labelled('live', all: true)->count())->toBe(0);
});

test('near_uses_the_venue_columns_even_when_events_has_latitude', function (): void {
    // A host that added its own coordinates to the events table: the bounding box's unqualified
    // columns still resolve to the venue table, the innermost FROM of the EXISTS subquery.
    $table = (new Event)->getTable();
    Schema::table($table, static function (Blueprint $blueprint): void {
        $blueprint->decimal('latitude', 10, 7)->nullable();
        $blueprint->decimal('longitude', 10, 7)->nullable();
    });

    $here = new Coordinates(48.1439, 17.1097);
    $event = Event::factory()->published()->at(venueAt(48.15, 17.11))->create();
    expect(DB::table($table)->where('id', $event->getKey())->update(['latitude' => -45.0, 'longitude' => -120.0]))->toBe(1);

    expect(Event::query()->near($here, km: 25)->pluck('id')->all())->toBe([$event->getKey()]);
})->skip(fn (): bool => DB::connection()->getDriverName() === 'mysql', 'MySQL DDL would commit the test transaction');

it('refuses a radius outside the limit and defaults it', function (): void {
    $here = new Coordinates(48.1439, 17.1097);
    $event = Event::factory()->published()->at(venueAt(48.30, 17.30))->create();

    expect(fn () => Event::query()->near($here, km: 501)->get())->toThrow(InvalidVenueException::class)
        ->and(fn () => Event::query()->near($here, km: 0)->get())->toThrow(InvalidVenueException::class)
        // The default radius (25 km) reaches the event 22 km away.
        ->and(Event::query()->near($here)->pluck('id')->all())->toBe([$event->getKey()]);

    config()->set('events.geo.default_radius_km', 10);
    expect(Event::query()->near($here)->count())->toBe(0);
});
