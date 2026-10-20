<?php

declare(strict_types=1);

use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Fixtures\Payments\FakePaymentCollector;
use RoundlyConsulting\Events\Tests\Support\Scenarios;
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
