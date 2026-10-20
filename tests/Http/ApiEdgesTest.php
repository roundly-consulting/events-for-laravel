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
