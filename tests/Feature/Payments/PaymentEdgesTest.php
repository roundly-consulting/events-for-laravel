<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event as EventBus;
use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Events\RsvpCancelled;
use RoundlyConsulting\Events\Exceptions\RsvpClosedException;
use RoundlyConsulting\Events\Exceptions\RsvpNotFoundException;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
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

function supporterEvent(int $capacity = 10): Event
{
    $event = Scenarios::event(capacity: $capacity);
    Events::for($event)->tiers()->add('Supporter', price: Money::ofMajor('15', 'EUR'), key: 'supporter');

    return $event;
}

test('a_checkout_superseded_by_a_free_seat_is_not_found', function (): void {
    $event = supporterEvent();
    Events::for($event)->tiers()->add('Free', key: 'free');
    $user = User::factory()->create();

    $held = Events::for($event)->rsvp($user)->going(tier: 'supporter');
    $reference = (string) $held->payment_reference;
    $free = Events::for($event)->rsvp($user)->going(tier: 'free');

    expect($free->status)->toBe(RsvpStatus::Going)
        ->and($free->fresh()?->payment_reference)->toBeNull()
        ->and($free->fresh()?->amount)->toBeNull()
        ->and(fn () => Events::rsvps()->confirmPayment($reference))->toThrow(RsvpNotFoundException::class);
});

test('a_comp_seat_never_reports_was_paid', function (): void {
    $event = supporterEvent();
    $user = User::factory()->create();

    Events::for($event)->rsvp($user)->going(tier: 'supporter');   // never paid
    Scenarios::freeze('2027-01-01 13:00:00');                      // the hold lapses
    EventBus::fake([RsvpCancelled::class]);

    $comp = Events::for($event)->attendees()->add($user, tier: 'supporter');
    Events::for($event)->attendees()->remove($user);

    expect($comp->payment_reference)->toBeNull()->and($comp->amount)->toBeNull();
    EventBus::assertDispatched(RsvpCancelled::class, static fn (RsvpCancelled $cancelled): bool => $cancelled->wasPaid === false);
});

test('a_paid_seat_keeps_its_payment', function (): void {
    $event = supporterEvent();
    $user = User::factory()->create();
    $held = Events::for($event)->rsvp($user)->going(tier: 'supporter');

    $paid = Events::rsvps()->confirmPayment((string) $held->payment_reference);
    EventBus::fake([RsvpCancelled::class]);
    Events::for($event)->rsvp($user)->notGoing();

    expect($paid->payment_reference)->toBe($held->payment_reference)->and($paid->amount?->toDecimal())->toBe('15.00');
    EventBus::assertDispatched(RsvpCancelled::class, static fn (RsvpCancelled $cancelled): bool => $cancelled->wasPaid);
});

test('holds_end_at_the_rescheduled_start', function (): void {
    $event = supporterEvent();
    $held = Events::for($event)->rsvp(User::factory()->create())->going(tier: 'supporter');
    expect($held->held_until?->format('Y-m-d H:i'))->toBe('2027-01-01 12:30');

    // Moved up to 13:10 Bratislava (12:10 UTC); the hold would still run until 12:30.
    Events::for($event)->reschedule('2027-01-01 13:10');
    expect($held->fresh()?->held_until?->format('Y-m-d H:i'))->toBe('2027-01-01 12:10');

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-01-01 12:15:00', 'UTC'));

    expect(fn () => Events::rsvps()->confirmPayment((string) $held->payment_reference))->toThrow(RsvpClosedException::class)
        ->and($held->fresh()?->status)->not->toBe(RsvpStatus::Going);
});

test('a_draft_test_hold_ends_at_the_new_start', function (): void {
    $draft = Scenarios::event(capacity: 10, publish: false);
    Events::for($draft)->tiers()->add('Supporter', price: Money::ofMajor('15', 'EUR'), key: 'supporter');
    $held = Events::for($draft)->rsvp($draft->organizer)->going(tier: 'supporter');

    Events::for($draft)->reschedule('2027-01-01 13:10');
    expect($held->fresh()?->held_until?->format('H:i'))->toBe('12:10');

    Events::for($draft)->update(new UpdateEventData(startsAt: '2027-01-01 13:05'));
    expect($held->fresh()?->held_until?->format('H:i'))->toBe('12:05');
});

test('a_hold_expired_by_a_webhook_promotes_the_waitlist', function (): void {
    $event = supporterEvent(capacity: 2);
    $lapsing = User::factory()->create();
    $payer = User::factory()->create();
    $waiting = User::factory()->create();

    Events::for($event)->rsvp($lapsing)->going(tier: 'supporter');           // held until 12:30
    Scenarios::freeze('2027-01-01 12:20:00');
    $paying = Events::for($event)->rsvp($payer)->going(tier: 'supporter');   // held until 12:50
    expect(Events::for($event)->rsvp($waiting)->going(tier: 'supporter')->status)->toBe(RsvpStatus::Waitlisted);

    Scenarios::freeze('2027-01-01 12:40:00');
    Events::rsvps()->confirmPayment((string) $paying->payment_reference);

    expect($lapsing->rsvpFor($event)?->status)->toBe(RsvpStatus::Expired)
        ->and($waiting->rsvpFor($event)?->status)->toBe(RsvpStatus::Held)
        ->and($event->fresh()?->seats_taken)->toBe(2);
});

test('a_hold_expired_by_a_door_confirmation_promotes_the_waitlist', function (): void {
    $event = supporterEvent(capacity: 2);
    $lapsing = User::factory()->create();
    $payer = User::factory()->create();
    $waiting = User::factory()->create();

    Events::for($event)->rsvp($lapsing)->going(tier: 'supporter');
    Scenarios::freeze('2027-01-01 12:20:00');
    $paying = Events::for($event)->rsvp($payer)->going(tier: 'supporter');
    Events::for($event)->rsvp($waiting)->going(tier: 'supporter');

    Scenarios::freeze('2027-01-01 12:40:00');
    Events::rsvps()->confirm($paying);

    expect($waiting->rsvpFor($event)?->status)->toBe(RsvpStatus::Held);
});

test('a_repeated_door_confirmation_still_promotes_after_a_lapsed_hold', function (): void {
    $event = supporterEvent(capacity: 2);
    $payer = User::factory()->create();
    $lapsing = User::factory()->create();
    $waiting = User::factory()->create();

    $paid = Events::rsvps()->confirm(Events::for($event)->rsvp($payer)->going(tier: 'supporter'));
    Events::for($event)->rsvp($lapsing)->going(tier: 'supporter');            // held until 12:30
    Events::for($event)->rsvp($waiting)->going(tier: 'supporter');

    Scenarios::freeze('2027-01-01 12:40:00');
    Events::rsvps()->confirm($paid);

    expect($lapsing->rsvpFor($event)?->status)->toBe(RsvpStatus::Expired)
        ->and($waiting->rsvpFor($event)?->status)->toBe(RsvpStatus::Held);
});

test('rsvps_by_actor_request_payment_checks_the_policy', function (): void {
    $event = supporterEvent();
    $attendee = User::factory()->create();
    $held = Events::for($event)->rsvp($attendee)->going(tier: 'supporter');

    expect(fn () => Events::rsvps()->by(User::factory()->create())->requestPayment($held))->toThrow(AuthorizationException::class)
        ->and(Events::rsvps()->by($attendee)->requestPayment($held)?->reference)->toBe($held->payment_reference)
        ->and(Events::rsvps()->by($event->organizer)->requestPayment($held)?->reference)->toBe($held->payment_reference);
});
