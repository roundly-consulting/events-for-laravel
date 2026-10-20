<?php

declare(strict_types=1);

use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\DataTransferObjects\UpdateTierData;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Exceptions\InvalidRsvpTransitionException;
use RoundlyConsulting\Events\Exceptions\TierNotFoundException;
use RoundlyConsulting\Events\Exceptions\TierNotOnSaleException;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Fixtures\Payments\FakePaymentCollector;
use RoundlyConsulting\Events\Tests\Support\Scenarios;
use RoundlyConsulting\Money\Money;

beforeEach(function (): void {
    Scenarios::freeze();
    FakePaymentCollector::reset();
    config()->set('events.payments.collector', FakePaymentCollector::class);
});

afterEach(fn () => Scenarios::thaw());

test('a_paid_going_party_changes_only_by_hand', function (): void {
    $event = Scenarios::event(capacity: 10, maxGuests: 3);
    Events::for($event)->tiers()->add('Supporter', price: Money::ofMajor('15', 'EUR'), key: 'supporter');
    $user = User::factory()->create();

    $held = Events::for($event)->rsvp($user)->going(tier: 'supporter', guests: 1);
    Events::rsvps()->confirmPayment((string) $held->payment_reference);

    // More people for the price of two, or fewer with nothing to refund: both by hand.
    expect(fn () => Events::for($event)->rsvp($user)->by($user)->going(guests: 3))->toThrow(InvalidRsvpTransitionException::class)
        ->and(fn () => Events::for($event)->rsvp($user)->by($user)->going(guests: 0))->toThrow(InvalidRsvpTransitionException::class);

    $fresh = $held->fresh();
    expect($fresh?->status)->toBe(RsvpStatus::Going)
        ->and($fresh?->partySize())->toBe(2)
        ->and($event->fresh()?->seats_taken)->toBe(2);

    // The same answer again is still the no-op, and the organizer changes the party by hand.
    expect(Events::for($event)->rsvp($user)->by($user)->going(guests: 1)->partySize())->toBe(2)
        ->and(Events::for($event)->attendees()->changeGuests($user, 3)->partySize())->toBe(4);
});

test('a_comp_seat_on_a_paid_ticket_type_grows_only_by_hand', function (): void {
    $event = Scenarios::event(capacity: 10, maxGuests: 3);
    Events::for($event)->tiers()->add('Supporter', price: Money::ofMajor('15', 'EUR'), key: 'supporter');
    $user = User::factory()->create();
    Events::for($event)->attendees()->add($user, tier: 'supporter');

    expect(fn () => Events::for($event)->rsvp($user)->by($user)->going(guests: 2))->toThrow(InvalidRsvpTransitionException::class);
});

test('a_held_paid_party_change_is_a_new_hold', function (): void {
    $event = Scenarios::event(capacity: 10, maxGuests: 3);
    Events::for($event)->tiers()->add('Supporter', price: Money::ofMajor('15', 'EUR'), key: 'supporter');
    $user = User::factory()->create();

    $held = Events::for($event)->rsvp($user)->going(tier: 'supporter');
    $bigger = Events::for($event)->rsvp($user)->by($user)->going(guests: 2);

    expect($bigger->status)->toBe(RsvpStatus::Held)
        ->and($bigger->amount?->toDecimal())->toBe('45.00')
        ->and($bigger->payment_reference)->not->toBe($held->payment_reference);
});

test('an_attendee_keeps_their_own_ticket_type_after_its_sale_ended', function (): void {
    $event = Scenarios::event(capacity: 50, maxGuests: 3);
    Events::for($event)->tiers()->add('Early bird', key: 'early');
    $user = User::factory()->create();

    Events::for($event)->rsvp($user)->by($user)->going(tier: 'early', guests: 2);
    Events::for($event)->tiers()->update('early', new UpdateTierData(saleEndsAt: '2027-01-01 12:30'));
    Scenarios::freeze('2027-01-02 12:00:00');

    $fewer = Events::for($event)->rsvp($user)->by($user)->going(guests: 1);
    $named = Events::for($event)->rsvp($user)->by($user)->going(tier: 'early', guests: 0);

    expect($fewer->status)->toBe(RsvpStatus::Going)
        ->and($named->guests)->toBe(0)
        ->and($event->fresh()?->seats_taken)->toBe(1);

    // Someone else still cannot pick the closed sale.
    expect(fn () => Events::for($event)->rsvp(User::factory()->create())->going(tier: 'early'))->toThrow(TierNotOnSaleException::class);
});

test('a_hidden_ticket_type_holder_changes_their_party', function (): void {
    $event = Scenarios::event(capacity: 50, maxGuests: 3);
    Events::for($event)->tiers()->add('Public', key: 'public');
    Events::for($event)->tiers()->add('Crew', hidden: true, key: 'crew');
    $user = User::factory()->create();
    Events::for($event)->attendees()->add($user, tier: 'crew', guests: 1);

    $again = Events::for($event)->rsvp($user)->by($user)->going(guests: 1);
    $fewer = Events::for($event)->rsvp($user)->by($user)->going(guests: 0);

    expect($again->guests)->toBe(1)
        ->and($fewer->status)->toBe(RsvpStatus::Going)
        ->and($fewer->guests)->toBe(0)
        ->and($fewer->tier?->key)->toBe('crew');

    // The hidden ticket type stays unknown to everyone who does not hold it.
    expect(fn () => Events::for($event)->rsvp(User::factory()->create())->going(tier: 'crew'))->toThrow(TierNotFoundException::class);
});

test('a_waitlisted_repeat_after_the_sale_keeps_the_place', function (): void {
    $event = Scenarios::event(capacity: 1);
    Events::for($event)->tiers()->add('Early bird', key: 'early');
    Events::for($event)->rsvp(User::factory()->create())->going(tier: 'early');
    $user = User::factory()->create();
    $waiting = Events::for($event)->rsvp($user)->by($user)->going(tier: 'early');
    expect($waiting->status)->toBe(RsvpStatus::Waitlisted);

    Events::for($event)->tiers()->update('early', new UpdateTierData(saleEndsAt: '2027-01-01 12:30'));
    Scenarios::freeze('2027-01-02 12:00:00');

    $again = Events::for($event)->rsvp($user)->by($user)->going();

    expect($again->status)->toBe(RsvpStatus::Waitlisted)
        ->and($again->waitlistPosition())->toBe(1)
        ->and($again->waitlisted_at?->toIso8601String())->toBe($waiting->waitlisted_at?->toIso8601String());
});

test('a_checked_in_party_cannot_shrink_below_who_came_in', function (): void {
    $event = Scenarios::event(capacity: 3, maxGuests: 2);
    $user = User::factory()->create();
    $waiting = User::factory()->create();
    $rsvp = Events::for($event)->rsvp($user)->going(guests: 2);
    expect(Events::for($event)->rsvp($waiting)->going()->status)->toBe(RsvpStatus::Waitlisted);

    Scenarios::minutesBeforeStart($event, 90);
    Events::for($event)->by($event->organizer)->checkIn((string) $rsvp->fresh()?->checkInCode(), guests: 1);

    expect(fn () => Events::for($event)->rsvp($user)->by($user)->going(guests: 0))->toThrow(InvalidRsvpTransitionException::class);

    // Down to the people already inside is fine; the freed seat goes to the waitlist.
    $fewer = Events::for($event)->rsvp($user)->by($user)->going(guests: 1);

    expect($fewer->guests)->toBe(1)
        ->and($fewer->checked_in_guests)->toBe(1)
        ->and($waiting->rsvpFor($event)?->status)->toBe(RsvpStatus::Going);
});

test('a_repeated_going_answer_saves_a_changed_note', function (): void {
    $event = Scenarios::event(capacity: 1);
    $attendee = User::factory()->create();
    $waiting = User::factory()->create();

    Events::for($event)->rsvp($attendee)->going(note: 'Vegan');
    $rsvp = Events::for($event)->rsvp($attendee)->going(note: 'Vegan, no nuts');
    Events::for($event)->rsvp($waiting)->going(note: 'Wheelchair');
    $queued = Events::for($event)->rsvp($waiting)->going(note: 'Wheelchair, two seats');

    expect($rsvp->fresh()?->note)->toBe('Vegan, no nuts')
        ->and($queued->status)->toBe(RsvpStatus::Waitlisted)
        ->and($queued->fresh()?->note)->toBe('Wheelchair, two seats');
});
