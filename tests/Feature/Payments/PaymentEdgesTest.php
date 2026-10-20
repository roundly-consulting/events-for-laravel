<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event as EventBus;
use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Events\RsvpCancelled;
use RoundlyConsulting\Events\Events\RsvpChanged;
use RoundlyConsulting\Events\Exceptions\RsvpClosedException;
use RoundlyConsulting\Events\Exceptions\RsvpNotFoundException;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Notifications\WaitlistPromotedNotification;
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

test('force_deleting_an_attendee_reports_a_paid_seat', function (): void {
    $event = supporterEvent(capacity: 1);
    $payer = User::factory()->create();
    $waiting = User::factory()->create();
    $interested = User::factory()->create();
    $held = Events::for($event)->rsvp($payer)->going(tier: 'supporter');
    $reference = (string) $held->fresh()?->payment_reference;
    Events::rsvps()->confirmPayment($reference);
    Events::for($event)->rsvp($waiting)->going(tier: 'supporter');
    Events::for($event)->rsvp($interested)->interested();
    EventBus::fake([RsvpCancelled::class, RsvpChanged::class]);

    // The account is gone for good: its paid seat goes to the waitlist, and the host hears about
    // it the way it hears about a removal — the refund trigger, with the payment reference.
    $payer->forceDelete();

    EventBus::assertDispatchedTimes(RsvpCancelled::class, 1);
    EventBus::assertDispatched(RsvpCancelled::class, static fn (RsvpCancelled $cancelled): bool => $cancelled->wasPaid
        && $cancelled->rsvp->payment_reference === $reference);
    EventBus::assertDispatched(RsvpChanged::class, static fn (RsvpChanged $changed): bool => $changed->from === RsvpStatus::Going
        && $changed->rsvp->status === RsvpStatus::NotGoing
        && (string) $changed->rsvp->attendee_id === (string) $payer->getKey());
    expect($waiting->rsvpFor($event)?->status)->toBe(RsvpStatus::Held);

    // An RSVP that held no seat leaves without a cancellation.
    $interested->forceDelete();
    EventBus::assertDispatchedTimes(RsvpCancelled::class, 1);
    EventBus::assertDispatched(RsvpChanged::class, static fn (RsvpChanged $changed): bool => $changed->from === RsvpStatus::Interested);
});

test('a_door_confirmation_without_a_reference_drops_the_online_checkout', function (): void {
    $event = supporterEvent();
    $door = User::factory()->create();
    $held = Events::for($event)->rsvp($door)->going(tier: 'supporter');
    $checkout = (string) $held->fresh()?->payment_reference;

    // Paid another way (cash at the door): the hold's online checkout no longer belongs to it.
    $going = Events::for($event)->attendees()->confirm($door);

    expect($going->status)->toBe(RsvpStatus::Going)
        ->and($going->fresh()?->payment_reference)->toBeNull()
        ->and($going->fresh()?->payment_url)->toBeNull()
        ->and($going->fresh()?->amount)->toBeNull()
        // A later online payment of that checkout is not found: the host refunds it.
        ->and(fn () => Events::rsvps()->confirmPayment($checkout))->toThrow(RsvpNotFoundException::class);

    // Leaving reports no payment the package knows of.
    EventBus::fake([RsvpCancelled::class]);
    Events::for($event)->attendees()->remove($door);
    EventBus::assertDispatched(RsvpCancelled::class, static fn (RsvpCancelled $cancelled): bool => ! $cancelled->wasPaid);

    // With the reference the manager knows, the seat counts as paid.
    $card = User::factory()->create();
    Events::for($event)->rsvp($card)->going(tier: 'supporter');
    $confirmed = Events::for($event)->attendees()->confirm($card, 'terminal-42');

    expect($confirmed->fresh()?->payment_reference)->toBe('terminal-42')
        ->and($confirmed->fresh()?->amount?->toDecimal())->toBe('15.00');
    Events::for($event)->attendees()->remove($card);
    EventBus::assertDispatched(RsvpCancelled::class, static fn (RsvpCancelled $cancelled): bool => $cancelled->wasPaid);
});

test('a_manual_promotion_after_the_start_creates_no_dead_hold', function (): void {
    Notification::fake();
    $event = supporterEvent(capacity: 1);
    Events::for($event)->tiers()->add('Free', key: 'free');
    $payer = User::factory()->create();
    $waiting = User::factory()->create();
    $free = User::factory()->create();
    $held = Events::for($event)->rsvp($payer)->going(tier: 'supporter');
    Events::rsvps()->confirmPayment((string) $held->fresh()?->payment_reference);
    Events::for($event)->rsvp($waiting)->going(tier: 'supporter');
    Events::for($event)->rsvp($free)->going(tier: 'free');

    // Ten minutes in, a seat frees up and the organizer promotes by hand.
    Scenarios::freeze($event->starts_at->addMinutes(10)->format('Y-m-d H:i:s'));
    Events::for($event)->attendees()->remove($payer);
    $calls = count(FakePaymentCollector::$calls);
    $promoted = Events::for($event)->waitlist()->promote();

    // A paid place cannot be held past the start: it keeps its place, no checkout, no pay link.
    expect($waiting->rsvpFor($event)?->status)->toBe(RsvpStatus::Waitlisted)
        ->and(FakePaymentCollector::$calls)->toHaveCount($calls);
    Notification::assertNotSentTo($waiting, WaitlistPromotedNotification::class);

    // A free one still moves up.
    expect($promoted->map(static fn (Rsvp $rsvp): mixed => $rsvp->attendee_id)->all())->toBe([$free->getKey()])
        ->and($free->rsvpFor($event)?->status)->toBe(RsvpStatus::Going);
});
