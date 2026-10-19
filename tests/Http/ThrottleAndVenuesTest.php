<?php

declare(strict_types=1);

use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\Scenarios;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\GeolocationManager;

beforeEach(fn () => Scenarios::freeze());
afterEach(fn () => Scenarios::thaw());

test('endpoints_are_throttled', function (): void {
    config()->set('events.routes.rate_limits.rsvp', 2);
    config()->set('events.routes.rate_limits.check_in', 2);
    config()->set('events.routes.rate_limits.feeds', 2);
    config()->set('events.routes.rate_limits.api', 50);
    $organizer = User::factory()->create();
    $user = User::factory()->create();
    $event = Scenarios::event(organizer: $organizer);

    $statuses = static fn (Closure $call, int $times): array => array_map(static fn (): int => $call()->status(), range(1, $times));

    expect($statuses(fn () => $this->actingAs($user)->putJson("/api/events/{$event->uuid}/rsvp", ['response' => 'interested']), 3))->toBe([200, 200, 429])
        ->and($statuses(fn () => $this->actingAs($organizer)->postJson("/api/events/{$event->uuid}/check-ins", ['code' => 'x']), 3))->toBe([404, 404, 429])
        ->and($statuses(fn () => $this->get('/api/events/feed.ics'), 3))->toBe([200, 200, 429]);

    // The API as a whole.
    config()->set('events.routes.rate_limits.api', 1);
    $this->getJson('/api/events')->assertStatus(429);
});

it('lists, creates, changes, geocodes and deletes venues', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user);
    Venue::factory()->create(['latitude' => 48.15, 'longitude' => 17.11]);
    Venue::factory()->create(['latitude' => 48.72, 'longitude' => 21.26]);

    $created = $this->actingAs($user)->postJson('/api/event-venues', [
        'name' => 'Blue Note', 'street' => 'Hlavná 1', 'city' => 'Bratislava', 'country' => 'SK',
        'coordinates' => ['latitude' => 48.1439, 'longitude' => 17.1097], 'timezone' => 'Europe/Bratislava',
        'owner' => ['type' => 'team', 'id' => $team->getKey()],
    ])->assertCreated()->assertJsonPath('data.owner', ['id' => $team->getKey(), 'type' => 'Team'])->json('data');

    app('auth')->forgetGuards();
    $this->getJson('/api/event-venues')->assertOk()->assertJsonPath('total', 3);
    $this->getJson('/api/event-venues?near=91,17')->assertStatus(422);
    $this->getJson('/api/event-venues?near=48.1439,17.1097&radius=10')->assertOk()->assertJsonPath('total', 2);
    $this->getJson('/api/event-venues?mine=1')->assertOk()->assertJsonPath('total', 0);
    $this->actingAs($user)->getJson('/api/event-venues?mine=1')->assertOk()->assertJsonPath('total', 0);
    $this->actingAs($user)->postJson('/api/event-venues', ['name' => 'My studio', 'owner' => ['type' => 'user', 'id' => $user->getKey()]])->assertCreated();
    $this->actingAs($user)->getJson('/api/event-venues?mine=1')->assertOk()->assertJsonPath('total', 1);
    $this->getJson("/api/event-venues/{$created['id']}")->assertOk()->assertJsonPath('data.address.city', 'Bratislava');

    $this->actingAs($user)->patchJson("/api/event-venues/{$created['id']}", ['capacity' => 300, 'city' => 'Košice'])->assertOk()
        ->assertJsonPath('data.capacity', 300)->assertJsonPath('data.address.city', 'Košice');
    $this->actingAs(User::factory()->create())->patchJson("/api/event-venues/{$created['id']}", ['capacity' => 1])->assertForbidden();

    app()->instance(GeolocationManager::class, new class(app()) extends GeolocationManager
    {
        public function locateAddress(string $address): ?Location
        {
            return null;
        }
    });
    $this->actingAs($user)->postJson("/api/event-venues/{$created['id']}/geocode")->assertOk();

    $this->actingAs($user)->deleteJson("/api/event-venues/{$created['id']}")->assertNoContent();
    $this->getJson("/api/event-venues/{$created['id']}")->assertNotFound()->assertJsonPath('code', 'venue_not_found');
});

it('serves calendar files over the api', function (): void {
    $event = Scenarios::event();

    $single = $this->get("/api/events/{$event->uuid}/calendar.ics");
    $single->assertOk();
    expect($single->headers->get('Content-Type'))->toBe('text/calendar; charset=utf-8')
        ->and($single->getContent())->toContain('UID:'.$event->uuid);

    Events::categories()->make('Music')->findOrCreate();
    Events::for($event)->categorize('music');
    $feed = $this->get('/api/events/feed.ics?category=music&organizer=user:'.$event->organizer_id);
    expect($feed->getContent())->toContain($event->uuid);
});
