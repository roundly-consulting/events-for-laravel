<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventBus;
use Illuminate\Support\Facades\Route;
use RoundlyConsulting\Events\Contracts\CollectsAdmissionPayment;
use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Events\AttendeeConfirmed;
use RoundlyConsulting\Events\Events\RsvpCancelled;
use RoundlyConsulting\Events\Events\RsvpHeld;
use RoundlyConsulting\Events\Events\RsvpHoldExpired;
use RoundlyConsulting\Events\Events\RsvpPaymentRequestFailed;
use RoundlyConsulting\Events\EventsManager;
use RoundlyConsulting\Events\Exceptions\InvalidRsvpTransitionException;
use RoundlyConsulting\Events\Exceptions\RsvpClosedException;
use RoundlyConsulting\Events\Exceptions\RsvpHoldExpiredException;
use RoundlyConsulting\Events\Exceptions\RsvpNotFoundException;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Jobs\RequestAdmissionPaymentJob;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Fixtures\Payments\FakePaymentCollector;
use RoundlyConsulting\Events\Tests\Support\Scenarios;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

beforeEach(function (): void {
    Scenarios::freeze();
    FakePaymentCollector::reset();
    config()->set('events.payments.collector', FakePaymentCollector::class);
});

afterEach(fn () => Scenarios::thaw());

function paidEvent(int $capacity = 10, int $maxGuests = 2): Event
{
    $event = Scenarios::event(capacity: $capacity, maxGuests: $maxGuests);
    Events::for($event)->tiers()->add('Supporter', price: Money::ofMajor('15', 'EUR'), key: 'supporter');

    return $event;
}

test('payment_collector_is_called_after_commit', function (): void {
    EventBus::fake([RsvpHeld::class]);
    $event = paidEvent();

    $rsvp = Events::for($event)->rsvp(User::factory()->create())->going(tier: 'supporter', guests: 1);

    expect($rsvp->status)->toBe(RsvpStatus::Held)
        ->and($rsvp->isHeld())->toBeTrue()
        ->and($rsvp->amount?->toDecimal())->toBe('30.00')
        ->and($rsvp->payment_reference)->toStartWith('pay_')
        ->and($rsvp->payment_url)->toStartWith('https://pay.example.com/')
        ->and($rsvp->held_until?->toIso8601String())->toBe(Scenarios::now()->addMinutes(30)->toIso8601String())
        ->and($event->fresh()?->seats_taken)->toBe(2)
        ->and($event->fresh()?->going_count)->toBe(0)
        ->and(FakePaymentCollector::$calls)->toHaveCount(1)
        // After commit: no transaction was open when the collector ran.
        ->and(FakePaymentCollector::$calls[0]['level'])->toBe(0)
        ->and(FakePaymentCollector::$calls[0]['amount'])->toBe('30.00 EUR');
    EventBus::assertDispatched(RsvpHeld::class, static fn (RsvpHeld $held): bool => $held->paymentRequest?->reference === $rsvp->payment_reference);

    // Inside a host transaction "after commit" is after the host's commit.
    DB::transaction(function () use ($event, &$inner): void {
        $inner = Events::for($event)->rsvp(User::factory()->create())->going(tier: 'supporter');
        expect($inner->payment_url)->toBeNull()
            ->and(FakePaymentCollector::$calls)->toHaveCount(1);
    });
    expect(FakePaymentCollector::$calls)->toHaveCount(2)
        ->and(Rsvp::query()->find($inner->getKey())?->payment_reference)->not->toBeNull();

    // An earlier checkout expiry shortens the hold.
    FakePaymentCollector::$expiresAt = Scenarios::now()->addMinutes(10);
    $short = Events::for($event)->rsvp(User::factory()->create())->going(tier: 'supporter');
    expect($short->fresh()?->held_until?->toIso8601String())->toBe(Scenarios::now()->addMinutes(10)->toIso8601String());

    // Asking again for a hold with its request returns the same one.
    expect(Events::rsvps()->requestPayment($short)?->reference)->toBe($short->fresh()?->payment_reference)
        ->and(FakePaymentCollector::$calls)->toHaveCount(3);
});

test('collector_failure_keeps_the_hold', function (): void {
    EventBus::fake([RsvpPaymentRequestFailed::class]);
    $event = paidEvent();
    FakePaymentCollector::$fails = true;
    $user = User::factory()->create();

    expect(fn () => Events::for($event)->rsvp($user)->going(tier: 'supporter'))->toThrow(RuntimeException::class, 'Checkout unavailable');

    $rsvp = $user->rsvpFor($event);
    expect($rsvp?->status)->toBe(RsvpStatus::Held)
        ->and($rsvp?->payment_reference)->toBeNull()
        ->and($event->fresh()?->seats_taken)->toBe(1);
    EventBus::assertDispatched(RsvpPaymentRequestFailed::class);

    // It expires on its own.
    Scenarios::freeze(Scenarios::now()->addMinutes(31)->format('Y-m-d H:i:s'));
    $result = Events::sweep();
    expect($result->expiredHolds)->toBe(1)
        ->and($rsvp?->fresh()?->status)->toBe(RsvpStatus::Expired)
        ->and($event->fresh()?->seats_taken)->toBe(0);

    // Retry by hand once the collector is back.
    FakePaymentCollector::$fails = false;
    expect(fn () => Events::rsvps()->requestPayment($rsvp))->toThrow(InvalidRsvpTransitionException::class, 'not waiting for a payment');
});

test('promotions_request_payment_on_the_queue', function (): void {
    Bus::fake([RequestAdmissionPaymentJob::class]);
    $event = paidEvent(capacity: 1);
    $first = User::factory()->create();
    $waiting = User::factory()->create();

    Events::for($event)->attendees()->add($first, tier: 'supporter');
    Events::for($event)->rsvp($waiting)->going(tier: 'supporter');
    Events::for($event)->attendees()->remove($first);

    $rsvp = $waiting->rsvpFor($event);
    expect($rsvp?->status)->toBe(RsvpStatus::Held)
        // A promotion holds for payments.promotion_hold (never past the start).
        ->and($rsvp?->held_until?->toIso8601String())->toBe(Scenarios::now()->addHours(24)->toIso8601String())
        ->and(FakePaymentCollector::$calls)->toHaveCount(0);

    Bus::assertDispatched(RequestAdmissionPaymentJob::class, static fn (RequestAdmissionPaymentJob $job): bool => $job->rsvpId === $rsvp?->getKey());

    (new RequestAdmissionPaymentJob((int) $rsvp?->getKey()))->handle(app(EventsManager::class));
    expect($rsvp?->fresh()?->payment_reference)->not->toBeNull();

    // Near the start the hold ends at the start.
    Scenarios::freeze($event->starts_at->subHours(3)->format('Y-m-d H:i:s'));
    $late = paidEvent(capacity: 1);
    Events::for($late)->update(new UpdateEventData(capacity: 0));
    Events::for($late)->rsvp($waiting)->going(tier: 'supporter');
    Events::for($late)->waitlist()->promote();
});

test('a_failing_collector_never_fails_someone_elses_decline', function (): void {
    $event = paidEvent(capacity: 1);
    $first = User::factory()->create();
    $waiting = User::factory()->create();
    Events::for($event)->rsvp($first)->going(tier: 'supporter');
    Events::rsvps()->confirmPayment((string) $first->rsvpFor($event)?->payment_reference);
    Events::for($event)->rsvp($waiting)->going(tier: 'supporter');

    FakePaymentCollector::$fails = true;

    // The decline succeeds; the promoted hold's request failed on the (sync) queue, reported.
    expect(Events::for($event)->rsvp($first)->notGoing()->status)->toBe(RsvpStatus::NotGoing)
        ->and($waiting->rsvpFor($event)?->status)->toBe(RsvpStatus::Held)
        ->and($waiting->rsvpFor($event)?->payment_reference)->toBeNull();

    // The sweep too.
    Scenarios::freeze(Scenarios::now()->addHours(25)->format('Y-m-d H:i:s'));
    expect(Events::sweep()->expiredHolds)->toBe(1);
});

test('payment_confirmation_rules', function (): void {
    EventBus::fake([AttendeeConfirmed::class]);
    $event = paidEvent();
    $user = User::factory()->create();
    $rsvp = Events::for($event)->rsvp($user)->going(tier: 'supporter');

    $confirmed = Events::rsvps()->confirmPayment((string) $rsvp->payment_reference);

    expect($confirmed->status)->toBe(RsvpStatus::Going)
        ->and($confirmed->checkInCode())->not->toBeNull()
        ->and($event->fresh()?->going_count)->toBe(1)
        ->and($event->fresh()?->seats_taken)->toBe(1);
    EventBus::assertDispatched(AttendeeConfirmed::class, static fn (AttendeeConfirmed $c): bool => ! $c->late);

    // A postponed event still confirms (the seat existed); a cancelled one refuses.
    $postponed = paidEvent();
    $held = Events::for($postponed)->rsvp(User::factory()->create())->going(tier: 'supporter');
    Events::for($postponed)->postpone();
    expect(Events::rsvps()->confirmPayment((string) $held->payment_reference)->status)->toBe(RsvpStatus::Going);

    $cancelled = paidEvent();
    $doomed = Events::for($cancelled)->rsvp(User::factory()->create())->going(tier: 'supporter');
    $reference = (string) $doomed->payment_reference;
    Events::for($cancelled)->cancel();
    expect($doomed->fresh()?->status)->toBe(RsvpStatus::Expired)
        ->and(fn () => Events::rsvps()->confirmPayment($reference))->toThrow(RsvpClosedException::class);

    // A manager confirms by hand; a non-held RSVP refuses.
    $manual = Events::for($event)->rsvp(User::factory()->create())->going(tier: 'supporter');
    expect(Events::for($event)->attendees()->confirm($manual, 'cash-1')->status)->toBe(RsvpStatus::Going)
        ->and($manual->fresh()?->payment_reference)->toBe('cash-1')
        ->and(fn () => Events::for($event)->attendees()->confirm(User::factory()->create()))->toThrow(RsvpNotFoundException::class)
        ->and(Events::rsvps()->confirm($manual)->status)->toBe(RsvpStatus::Going);

    $interested = Events::for($event)->rsvp(User::factory()->create())->interested();
    expect(fn () => Events::rsvps()->confirm($interested))->toThrow(InvalidRsvpTransitionException::class);
});

test('confirm_is_idempotent', function (): void {
    $event = paidEvent();
    $rsvp = Events::for($event)->rsvp(User::factory()->create())->going(tier: 'supporter');
    $reference = (string) $rsvp->payment_reference;

    Events::rsvps()->confirmPayment($reference);
    $code = $rsvp->fresh()?->checkInCode();
    Events::rsvps()->confirmPayment($reference);

    expect($rsvp->fresh()?->checkInCode())->toBe($code)
        ->and($event->fresh()?->going_count)->toBe(1);
});

test('late_payment_confirmation_takes_a_free_seat', function (): void {
    EventBus::fake([AttendeeConfirmed::class, RsvpHoldExpired::class]);
    $event = paidEvent(capacity: 2);
    $rsvp = Events::for($event)->rsvp(User::factory()->create())->going(tier: 'supporter');

    Scenarios::freeze(Scenarios::now()->addHour()->format('Y-m-d H:i:s'));
    $late = Events::rsvps()->confirmPayment((string) $rsvp->payment_reference);

    expect($late->status)->toBe(RsvpStatus::Going)
        ->and($event->fresh()?->seats_taken)->toBe(1);
    EventBus::assertDispatched(RsvpHoldExpired::class);
    EventBus::assertDispatched(AttendeeConfirmed::class, static fn (AttendeeConfirmed $c): bool => $c->late);
});

test('late_payment_confirmation_without_a_seat_is_refunded', function (): void {
    $event = paidEvent(capacity: 1);
    $rsvp = Events::for($event)->rsvp(User::factory()->create())->going(tier: 'supporter');

    Scenarios::freeze(Scenarios::now()->addHour()->format('Y-m-d H:i:s'));
    // The seat went to someone else meanwhile.
    Events::for($event)->attendees()->add(User::factory()->create(), tier: 'supporter');

    expect(fn () => Events::rsvps()->confirmPayment((string) $rsvp->payment_reference))
        ->toThrow(RsvpHoldExpiredException::class, 'Refund the payment.');
});

test('a_declined_hold_is_never_revived_by_payment', function (): void {
    $event = paidEvent();
    $user = User::factory()->create();
    $rsvp = Events::for($event)->rsvp($user)->going(tier: 'supporter');
    $reference = (string) $rsvp->payment_reference;

    Events::for($event)->rsvp($user)->notGoing();

    expect(fn () => Events::rsvps()->confirmPayment($reference))->toThrow(RsvpHoldExpiredException::class)
        ->and($user->rsvpFor($event)?->status)->toBe(RsvpStatus::NotGoing);
});

test('superseded_references_are_not_found', function (): void {
    $event = paidEvent();
    $user = User::factory()->create();
    $first = Events::for($event)->rsvp($user)->going(tier: 'supporter');
    $old = (string) $first->payment_reference;

    // A party change re-prices and refreshes the hold with a new checkout.
    $second = Events::for($event)->rsvp($user)->going(tier: 'supporter', guests: 1);

    expect($second->fresh()?->payment_reference)->not->toBe($old)
        ->and($second->amount?->toDecimal())->toBe('30.00')
        ->and(fn () => Events::rsvps()->confirmPayment($old))->toThrow(RsvpNotFoundException::class, 'No RSVP holds this payment reference.')
        ->and(fn () => Events::rsvps()->confirmPayment('nope'))->toThrow(RsvpNotFoundException::class);
});

test('cancellation_event_flags_paid_rsvps', function (): void {
    EventBus::fake([RsvpCancelled::class]);
    $event = paidEvent();
    $payer = User::factory()->create();
    $rsvp = Events::for($event)->rsvp($payer)->going(tier: 'supporter');
    Events::rsvps()->confirmPayment((string) $rsvp->payment_reference);

    Events::for($event)->rsvp($payer)->notGoing();

    EventBus::assertDispatched(RsvpCancelled::class, static fn (RsvpCancelled $cancelled): bool => $cancelled->wasPaid);
});

test('payment_confirmation_is_server_side', function (): void {
    // No route confirms a payment: confirmation is a PHP call from your webhook.
    $routes = collect(Route::getRoutes()->getRoutes())->map(static fn ($route): string => $route->uri())->filter(static fn (string $uri): bool => str_contains($uri, 'payment') || str_contains($uri, 'confirm'));
    expect($routes->all())->toBe([]);

    // A reference confirms only its own RSVP.
    $event = paidEvent();
    $a = Events::for($event)->rsvp(User::factory()->create())->going(tier: 'supporter');
    $b = Events::for($event)->rsvp(User::factory()->create())->going(tier: 'supporter');

    Events::rsvps()->confirmPayment((string) $a->payment_reference);

    expect($a->fresh()?->status)->toBe(RsvpStatus::Going)
        ->and($b->fresh()?->status)->toBe(RsvpStatus::Held);
});

it('releases a hold whose payment failed', function (): void {
    EventBus::fake([RsvpHoldExpired::class]);
    $event = paidEvent(capacity: 1);
    $user = User::factory()->create();
    $rsvp = Events::for($event)->rsvp($user)->going(tier: 'supporter');

    Events::rsvps()->release($rsvp);

    expect($rsvp->fresh()?->status)->toBe(RsvpStatus::Expired)
        ->and($event->fresh()?->seats_taken)->toBe(0)
        ->and(fn () => Events::for($event)->attendees()->release($user))->toThrow(InvalidRsvpTransitionException::class);
    EventBus::assertDispatched(RsvpHoldExpired::class);
});

it('binds a host collector from config or the container', function (): void {
    expect(app(CollectsAdmissionPayment::class))->toBeInstanceOf(FakePaymentCollector::class);

    config()->set('events.payments.collector', 'Not\\A\\Class');
    expect(fn () => app(CollectsAdmissionPayment::class))->toThrow(InvalidConfigurationException::class, 'events.payments.collector');
});
