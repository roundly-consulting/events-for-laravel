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
