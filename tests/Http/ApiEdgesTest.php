<?php

declare(strict_types=1);

use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\DataTransferObjects\UpdateTierData;
use RoundlyConsulting\Events\Enums\GuestList;
use RoundlyConsulting\Events\Exceptions\InvalidEventScheduleException;
use RoundlyConsulting\Events\Exceptions\InvalidTierException;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Fixtures\Payments\FakePaymentCollector;
use RoundlyConsulting\Events\Tests\Support\Scenarios;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\Enum\GeolocationType;
use RoundlyConsulting\Geolocation\Facades\Geolocation;
use RoundlyConsulting\Money\Money;

beforeEach(function (): void {
    Scenarios::freeze();
    FakePaymentCollector::reset();
    config()->set('events.payments.collector', FakePaymentCollector::class);
    $this->organizer = User::factory()->create();
});

afterEach(fn () => Scenarios::thaw());

test('a_paid_party_grows_by_hand_over_the_api_too', function (): void {
    $event = Scenarios::event(capacity: 10, organizer: $this->organizer, maxGuests: 3);
    Events::for($event)->tiers()->add('Supporter', price: Money::ofMajor('15', 'EUR'), key: 'supporter');
    $user = User::factory()->create();
    $uri = "/api/events/{$event->uuid}/rsvp";

    $this->actingAs($user)->putJson($uri, ['response' => 'going', 'tier' => 'supporter'])->assertOk();
    Events::rsvps()->confirmPayment((string) Events::for($event)->attendees()->find($user)?->payment_reference);

    $this->actingAs($user)->putJson($uri, ['response' => 'going', 'guests' => 3])
        ->assertStatus(409)
        ->assertJsonPath('code', 'invalid_rsvp_transition');

    expect($event->fresh()?->seats_taken)->toBe(1);
});

test('guest_list_filters_are_managers_only', function (): void {
    $event = Scenarios::event(capacity: 10, organizer: $this->organizer);
    Events::for($event)->update(new UpdateEventData(guestList: GuestList::Public));
    Events::for($event)->tiers()->add('General', key: 'general');
    Events::for($event)->tiers()->add('Press', hidden: true, key: 'press');
    $journalist = User::factory()->create();
    Events::for($event)->attendees()->add($journalist, tier: 'press');
    Events::for($event)->attendees()->checkIn($journalist);
    Events::for($event)->rsvp(User::factory()->create())->going(tier: 'general');
    $uri = "/api/events/{$event->uuid}/attendees";
    $outsider = User::factory()->create();

    // Outsiders always get the whole public list: the filters are ignored for them.
    foreach (['?tier=press', '?tier=general', '?checked_in=1', '?checked_in=0', '?tier=nope'] as $filter) {
        $this->actingAs($outsider)->getJson($uri.$filter)->assertOk()->assertJsonCount(2, 'data');
    }

    // The organizer still narrows the list.
    $this->actingAs($this->organizer)->getJson($uri.'?tier=press')->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($this->organizer)->getJson($uri.'?checked_in=1')->assertOk()->assertJsonCount(1, 'data');
});

test('show_counts_off_hides_tier_seats_left', function (): void {
    $event = Scenarios::event(capacity: 100, organizer: $this->organizer);
    Events::for($event)->update(new UpdateEventData(showCounts: false));
    Events::for($event)->tiers()->add('General', capacity: 100, key: 'general');
    foreach (range(1, 3) as $ignored) {
        Events::for($event)->rsvp(User::factory()->create())->going(tier: 'general');
    }

    $stranger = $this->actingAs(User::factory()->create())->getJson("/api/events/{$event->uuid}")->assertOk();
    expect($stranger->json('data.counts'))->toBeNull()
        ->and($stranger->json('data.tiers.0.seats_left'))->toBeNull()
        ->and($stranger->json('data.tiers.0.capacity'))->toBe(100);

    $tiers = $this->getJson("/api/events/{$event->uuid}/tiers")->assertOk();
    expect($tiers->json('data.0.seats_left'))->toBeNull();

    $managed = $this->actingAs($this->organizer)->getJson("/api/events/{$event->uuid}")->assertOk();
    expect($managed->json('data.tiers.0.seats_left'))->toBe(97);

    Events::for($event)->update(new UpdateEventData(showCounts: true));
    expect($this->actingAs(User::factory()->create())->getJson("/api/events/{$event->uuid}")->json('data.tiers.0.seats_left'))->toBe(97);
});

test('popularity_never_ranks_hidden_counts', function (): void {
    $quiet = Scenarios::event(capacity: 100, organizer: $this->organizer);
    Events::for($quiet)->update(new UpdateEventData(title: 'Quiet', showCounts: false));
    $loud = Scenarios::event(capacity: 100);
    Events::for($loud)->update(new UpdateEventData(title: 'Loud'));
    foreach (range(1, 3) as $ignored) {
        Events::for($quiet)->rsvp(User::factory()->create())->going();
    }
    Events::for($loud)->rsvp(User::factory()->create())->going();

    // Ranked by what everyone may see: the hidden count sorts as nothing.
    $order = collect($this->getJson('/api/events?sort=-popularity')->assertOk()->json('data'))->pluck('title')->all();
    expect($order)->toBe(['Loud', 'Quiet']);

    // The organizer's own list ranks by the real numbers.
    $mine = collect($this->actingAs($this->organizer)->getJson('/api/events?filter[mine]=organizing&sort=-popularity')->assertOk()->json('data'))->pluck('title')->all();
    expect($mine[0])->toBe('Quiet');
});

test('an_impossible_date_filter_is_refused', function (string $date): void {
    $this->getJson('/api/events?filter[date]='.$date)->assertStatus(422);
})->with(['2026-13-45', '2027-02-30', '2027-2-3']);

test('an_impossible_local_date_scope_is_refused', function (): void {
    expect(fn () => Event::query()->onLocalDate('2027-02-30')->get())->toThrow(InvalidEventScheduleException::class)
        ->and(Event::query()->onLocalDate('2027-02-10')->count())->toBe(0);
});

test('organizer_filters_are_no_existence_oracle', function (): void {
    Scenarios::event(organizer: $this->organizer);
    $missing = 987654321;

    $known = $this->get("/api/events/feed.ics?organizer=user:{$this->organizer->getKey()}");
    $unknown = $this->get("/api/events/feed.ics?organizer=user:{$missing}");
    expect($unknown->status())->toBe($known->status())->toBe(200)
        ->and($unknown->getContent())->not->toContain('BEGIN:VEVENT');

    $this->getJson("/api/events?filter[organizer]=user:{$missing}")->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/events?filter[organizer]=user:{$this->organizer->getKey()}")->assertOk()->assertJsonCount(1, 'data');

    // A malformed reference or an unknown alias is still refused, whatever exists.
    $this->get('/api/events/feed.ics?organizer=user:abc')->assertStatus(422);
    $this->getJson('/api/events?filter[organizer]=robot:1')->assertStatus(422);
});

test('a_partial_address_patch_keeps_the_other_fields', function (): void {
    $venue = Events::venues()->make('Blue Note')->ownedBy($this->organizer)
        ->address(street: 'Hlavná 1', city: 'Bratislava', postalCode: '811 01', country: 'SK')
        ->timezone('Europe/Bratislava')->create();

    $this->actingAs($this->organizer)->patchJson("/api/event-venues/{$venue->getKey()}", ['city' => 'Košice'])->assertOk();
    $fresh = $venue->fresh();
    expect([$fresh?->street, $fresh?->city, $fresh?->postal_code, $fresh?->country_code])->toBe(['Hlavná 1', 'Košice', '811 01', 'SK']);

    // A field sent as null clears just that field.
    $this->actingAs($this->organizer)->patchJson("/api/event-venues/{$venue->getKey()}", ['street' => null])->assertOk();
    expect([$venue->fresh()?->street, $venue->fresh()?->city])->toBe([null, 'Košice']);
});

test('a_tier_position_patch_is_applied', function (): void {
    $event = Scenarios::event(organizer: $this->organizer);
    $tier = Events::for($event)->tiers()->add('General', key: 'general');

    $this->actingAs($this->organizer)->patchJson("/api/events/{$event->uuid}/tiers/general", ['position' => 7])->assertOk()
        ->assertJsonPath('data.position', 7);
    expect($tier->fresh()?->position)->toBe(7)
        ->and(fn () => Events::for($event)->tiers()->update('general', new UpdateTierData(position: -1)))->toThrow(InvalidTierException::class);
});

test('a_venue_patch_never_ignores_owner_shared_or_geocode', function (): void {
    $team = Team::factory()->create();
    $team->members()->attach($this->organizer);
    $venue = Events::venues()->make('Blue Note')->ownedBy($this->organizer)->timezone('Europe/Bratislava')
        ->address(street: 'Hlavná 1', city: 'Bratislava', country: 'SK')->create();
    $uri = "/api/event-venues/{$venue->getKey()}";

    $this->actingAs($this->organizer)->patchJson($uri, ['owner' => ['type' => 'team', 'id' => $team->getKey()]])->assertStatus(422);
    $this->actingAs($this->organizer)->patchJson($uri, ['shared' => true])->assertStatus(422);
    $this->actingAs($this->organizer)->patchJson($uri, ['geocode' => true, 'coordinates' => ['lat' => 1, 'lng' => 2]])->assertStatus(422);
    expect($venue->fresh()?->owner_id)->toBe($this->organizer->getKey());

    // geocode: true looks the changed address up, as the geocode route does.
    Geolocation::fake()->seed('Hlavná 1, Košice, SK', new Location('Somewhere', 'Hlavná 1', 'Košice', 'SK', 48.7, 21.2, GeolocationType::Geolocation));
    $this->actingAs($this->organizer)->patchJson($uri, ['city' => 'Košice', 'geocode' => true])->assertOk();

    expect($venue->fresh()?->city)->toBe('Košice')->and($venue->fresh()?->coordinates?->latitude)->toBe(48.7);
});

test('a_price_too_precise_for_its_currency_is_a_422', function (string $price, string $currency): void {
    $event = Scenarios::event(organizer: $this->organizer);
    $tier = Events::for($event)->tiers()->add('Standing', key: 'standing');
    $uri = "/api/events/{$event->uuid}/tiers";

    // More decimals than the currency has: the package's 422, never a 500.
    $this->actingAs($this->organizer)->postJson($uri, ['name' => 'Paid', 'price' => $price, 'currency' => $currency])
        ->assertStatus(422)->assertJsonPath('code', 'invalid_tier');
    $this->actingAs($this->organizer)->patchJson("{$uri}/{$tier->key}", ['price' => $price, 'currency' => $currency])
        ->assertStatus(422)->assertJsonPath('code', 'invalid_tier');
    $this->actingAs($this->organizer)->postJson('/api/events', [
        'title' => 'Gig', 'starts_at' => '2027-02-12 20:00',
        'tiers' => [['name' => 'Paid', 'price' => $price, 'currency' => $currency]],
    ])->assertStatus(422)->assertJsonPath('code', 'invalid_tier');

    expect(Event::query()->count())->toBe(1)
        ->and($tier->fresh()?->price)->toBeNull();
})->with([
    'three decimals in EUR' => ['15.999', 'EUR'],
    'any decimal in JPY' => ['15.5', 'JPY'],
]);

test('an_all_digit_tier_key_is_addressable_or_refused', function (): void {
    $event = Scenarios::event(organizer: $this->organizer);
    $floor = Events::for($event)->tiers()->add('Floor', key: 'floor');
    // A key that reads like another ticket type's id, and one derived from an all-digit name.
    $balcony = Events::for($event)->tiers()->add('Balcony', key: (string) $floor->getKey());
    $year = Events::for($event)->tiers()->add('2027');
    $uri = "/api/events/{$event->uuid}/tiers";

    expect($year->key)->toBe('2027');

    // A segment one of the event's ticket types has as its key addresses that ticket type.
    $this->actingAs($this->organizer)->patchJson("{$uri}/{$floor->getKey()}", ['capacity' => 9])->assertOk()
        ->assertJsonPath('data.key', $balcony->key);
    $this->actingAs($this->organizer)->patchJson("{$uri}/2027", ['capacity' => 5])->assertOk()->assertJsonPath('data.key', '2027');

    expect($balcony->fresh()?->capacity)->toBe(9)
        ->and($floor->fresh()?->capacity)->toBeNull()
        ->and($year->fresh()?->capacity)->toBe(5);

    // Any other number is still an id; every ticket type is reachable by its key.
    $this->actingAs($this->organizer)->patchJson("{$uri}/{$year->getKey()}", ['capacity' => 6])->assertOk()->assertJsonPath('data.key', '2027');
    $this->actingAs($this->organizer)->patchJson("{$uri}/floor", ['capacity' => 7])->assertOk()->assertJsonPath('data.key', 'floor');
    $this->actingAs($this->organizer)->deleteJson("{$uri}/".($year->getKey() + 1000))->assertNotFound();
});
