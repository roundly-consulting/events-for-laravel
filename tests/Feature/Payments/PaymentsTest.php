<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventBus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use RoundlyConsulting\Events\Contracts\CollectsAdmissionPayment;
use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Events\AttendeeConfirmed;
use RoundlyConsulting\Events\Events\RsvpCancelled;
use RoundlyConsulting\Events\Events\RsvpHeld;
use RoundlyConsulting\Events\Events\RsvpHoldExpired;
use RoundlyConsulting\Events\Events\RsvpPaymentRequestDiscarded;
use RoundlyConsulting\Events\Events\RsvpPaymentRequestFailed;
use RoundlyConsulting\Events\Exceptions\InvalidRsvpTransitionException;
use RoundlyConsulting\Events\Exceptions\RsvpClosedException;
use RoundlyConsulting\Events\Exceptions\RsvpHoldExpiredException;
use RoundlyConsulting\Events\Exceptions\RsvpNotFoundException;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Jobs\RequestAdmissionPaymentJob;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Notifications\RsvpConfirmedNotification;
use RoundlyConsulting\Events\Notifications\WaitlistPromotedNotification;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Fixtures\Payments\FakePaymentCollector;
use RoundlyConsulting\Events\Tests\Fixtures\Payments\SlowCollector;
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

    Bus::assertDispatched(RequestAdmissionPaymentJob::class, static fn (RequestAdmissionPaymentJob $job): bool => $job->rsvpId === $rsvp?->getKey() && $job->promotion);

    app()->call([new RequestAdmissionPaymentJob((int) $rsvp?->getKey(), promotion: true), 'handle']);
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

/**
 * A full paid event with one attendee waiting: freeing the seat promotes them to a hold.
 *
 * @return array{0: Event, 1: User, 2: User}
 */
function paidWaitlist(): array
{
    $event = paidEvent(capacity: 1);
    $first = User::factory()->create();
    $waiting = User::factory()->create();
    Events::for($event)->attendees()->add($first, tier: 'supporter');
    Events::for($event)->rsvp($waiting)->going(tier: 'supporter');

    return [$event, $first, $waiting];
}

/**
 * @return list<string|null> the action URL of every waitlist_promoted mail `$user` got
 */
function promotedLinks(User $user): array
{
    return Notification::sent($user, WaitlistPromotedNotification::class)
        ->map(static fn (WaitlistPromotedNotification $notice): ?string => $notice->toMail($user)->actionUrl)
        ->values()->all();
}

test('a_promoted_hold_is_told_with_its_payment_link', function (): void {
    Notification::fake();
    config()->set('events.urls.event', 'https://example.com/events/{uuid}');
    [$event, $first, $waiting] = paidWaitlist();

    Events::for($event)->attendees()->remove($first);

    $rsvp = $waiting->rsvpFor($event);
    expect($rsvp?->status)->toBe(RsvpStatus::Held)
        ->and($rsvp?->payment_url)->toStartWith('https://pay.example.com/')
        ->and(promotedLinks($waiting))->toBe([$rsvp?->payment_url]);
});

test('a_promoted_hold_is_told_without_a_link_when_the_collector_fails', function (): void {
    Notification::fake();
    config()->set('events.urls.event', 'https://example.com/events/{uuid}');
    [$event, $first, $waiting] = paidWaitlist();
    FakePaymentCollector::$fails = true;

    Events::for($event)->attendees()->remove($first);

    expect($waiting->rsvpFor($event)?->status)->toBe(RsvpStatus::Held)
        ->and($waiting->rsvpFor($event)?->payment_url)->toBeNull()
        ->and(promotedLinks($waiting))->toBe(['https://example.com/events/'.$event->uuid]);
});

test('a_free_promotion_is_told_at_once', function (): void {
    Notification::fake();
    $event = Scenarios::event(capacity: 1);
    $first = User::factory()->create();
    $waiting = User::factory()->create();
    Events::for($event)->rsvp($first)->going();
    Events::for($event)->rsvp($waiting)->going();

    Events::for($event)->rsvp($first)->notGoing();

    expect($waiting->rsvpFor($event)?->status)->toBe(RsvpStatus::Going);
    Notification::assertSentToTimes($waiting, WaitlistPromotedNotification::class, 1);
});

test('a_hold_released_before_the_job_runs_is_not_told', function (): void {
    Notification::fake();
    Bus::fake([RequestAdmissionPaymentJob::class]);
    [$event, $first, $waiting] = paidWaitlist();

    Events::for($event)->attendees()->remove($first);
    $rsvp = $waiting->rsvpFor($event);
    Events::rsvps()->release($rsvp);

    $job = Bus::dispatched(RequestAdmissionPaymentJob::class)->sole();
    expect($job->promotion)->toBeTrue();
    app()->call([$job, 'handle']);

    expect(FakePaymentCollector::$calls)->toHaveCount(0);
    Notification::assertNotSentTo($waiting, WaitlistPromotedNotification::class);
});

test('each_promotion_is_told_once', function (): void {
    Notification::fake();
    Bus::fake([RequestAdmissionPaymentJob::class]);
    [$event, $first, $waiting] = paidWaitlist();

    Events::for($event)->attendees()->remove($first);
    $rsvp = $waiting->rsvpFor($event);
    // The host asked for the checkout itself before the job ran: the job still tells, once.
    $request = Events::rsvps()->requestPayment($rsvp);
    $job = Bus::dispatched(RequestAdmissionPaymentJob::class)->sole();
    app()->call([$job, 'handle']);

    expect(FakePaymentCollector::$calls)->toHaveCount(1)
        ->and(promotedLinks($waiting))->toBe([$request?->url]);

    // A hold that is not a promotion (a party change) never tells "promoted".
    app()->call([new RequestAdmissionPaymentJob((int) $rsvp?->getKey()), 'handle']);
    Notification::assertSentToTimes($waiting, WaitlistPromotedNotification::class, 1);
});

test('a_checkout_for_a_hold_that_changed_meanwhile_is_discarded', function (): void {
    SlowCollector::reset();
    app()->bind(CollectsAdmissionPayment::class, SlowCollector::class);
    EventBus::fake([RsvpHeld::class, RsvpPaymentRequestDiscarded::class]);
    $event = paidEvent(capacity: 5);
    $user = User::factory()->create();

    // The attendee declines while the provider call is in flight.
    SlowCollector::$during = static fn () => Events::for($event)->rsvp($user)->notGoing();
    $rsvp = Events::for($event)->rsvp($user)->going(tier: 'supporter');

    expect($rsvp->fresh()?->status)->toBe(RsvpStatus::NotGoing)
        ->and($rsvp->fresh()?->payment_reference)->toBeNull();
    EventBus::assertNotDispatched(RsvpHeld::class);
    EventBus::assertDispatchedTimes(RsvpPaymentRequestDiscarded::class, 1);
    EventBus::assertDispatched(RsvpPaymentRequestDiscarded::class, static fn (RsvpPaymentRequestDiscarded $discarded): bool => $discarded->paymentRequest->reference === 'cs_'.$rsvp->getKey()
        && $discarded->rsvp->is($rsvp));

    // Asked by hand: the hold is released mid-call, the checkout is reported and null returned.
    $other = User::factory()->create();
    SlowCollector::$returnsNull = true;
    $held = Events::for($event)->rsvp($other)->going(tier: 'supporter');
    SlowCollector::$returnsNull = false;
    SlowCollector::$during = static fn () => Events::rsvps()->release($held->fresh() ?? $held);

    expect(Events::rsvps()->requestPayment($held))->toBeNull()
        ->and($held->fresh()?->payment_reference)->toBeNull();
    EventBus::assertDispatchedTimes(RsvpPaymentRequestDiscarded::class, 2);
});

test('rsvp_held_is_only_fired_for_a_stored_checkout', function (): void {
    SlowCollector::reset();
    app()->bind(CollectsAdmissionPayment::class, SlowCollector::class);
    EventBus::fake([RsvpHeld::class, RsvpPaymentRequestDiscarded::class]);
    $event = paidEvent(capacity: 5);

    $stored = Events::for($event)->rsvp(User::factory()->create())->going(tier: 'supporter');
    EventBus::assertDispatched(RsvpHeld::class, static fn (RsvpHeld $held): bool => $held->paymentRequest?->reference === $stored->payment_reference);

    // No checkout at all ("collect another way") is still announced, with no request.
    SlowCollector::$returnsNull = true;
    Events::for($event)->rsvp(User::factory()->create())->going(tier: 'supporter');
    EventBus::assertDispatched(RsvpHeld::class, static fn (RsvpHeld $held): bool => $held->paymentRequest === null);
    EventBus::assertDispatchedTimes(RsvpHeld::class, 2);
    EventBus::assertNotDispatched(RsvpPaymentRequestDiscarded::class);
});

test('a_repeated_identical_reference_counts_as_stored', function (): void {
    SlowCollector::reset();
    app()->bind(CollectsAdmissionPayment::class, SlowCollector::class);
    EventBus::fake([RsvpHeld::class, RsvpPaymentRequestDiscarded::class]);
    $event = paidEvent(capacity: 5);
    SlowCollector::$returnsNull = true;
    $held = Events::for($event)->rsvp(User::factory()->create())->going(tier: 'supporter');
    SlowCollector::$returnsNull = false;

    // A second request for the same hold lands first and stores the provider's (same) checkout.
    SlowCollector::$during = static fn () => Events::rsvps()->requestPayment($held->fresh() ?? $held);
    $request = Events::rsvps()->requestPayment($held);

    expect($request?->reference)->toBe('cs_'.$held->getKey())
        ->and($held->fresh()?->payment_reference)->toBe('cs_'.$held->getKey());
    EventBus::assertNotDispatched(RsvpPaymentRequestDiscarded::class);
});

test('a_late_payment_after_the_start_is_refused_for_a_refund', function (): void {
    Notification::fake();
    $event = paidEvent();
    $rsvp = Events::for($event)->rsvp(User::factory()->create())->going(tier: 'supporter');
    $reference = (string) $rsvp->payment_reference;

    // The webhook arrives three days after the event ended (REPRO-9), and right at the start.
    foreach ([$event->ends_at->addDays(3), $event->starts_at] as $at) {
        Scenarios::freeze($at->format('Y-m-d H:i:s'));
        Events::sweep();

        expect(fn () => Events::rsvps()->confirmPayment($reference))->toThrow(RsvpClosedException::class);
        expect($rsvp->fresh()?->status)->toBe(RsvpStatus::Expired)
            ->and($rsvp->fresh()?->checkInCode())->toBeNull();
    }

    Notification::assertNotSentTo($rsvp->attendee, RsvpConfirmedNotification::class);
});

test('a_late_payment_on_a_completed_event_is_refused', function (): void {
    $event = paidEvent();
    $lapsed = Events::for($event)->rsvp(User::factory()->create())->going(tier: 'supporter');
    // A hold a host's own flow kept open past the start.
    $open = Events::for($event)->rsvp(User::factory()->create())->going(tier: 'supporter');
    Scenarios::freeze($event->starts_at->addMinutes(30)->format('Y-m-d H:i:s'));
    Events::for($event)->complete();
    Rsvp::query()->whereKey($open->getKey())->update(['held_until' => $event->ends_at->addDay()->format('Y-m-d H:i:s')]);

    expect(fn () => Events::rsvps()->confirmPayment((string) $lapsed->payment_reference))->toThrow(RsvpClosedException::class)
        ->and(fn () => Events::rsvps()->confirmPayment((string) $open->payment_reference))->toThrow(RsvpClosedException::class)
        ->and($open->fresh()?->status)->toBe(RsvpStatus::Held);
});

test('a_late_payment_before_the_start_still_takes_a_free_seat', function (): void {
    $event = paidEvent();
    $rsvp = Events::for($event)->rsvp(User::factory()->create())->going(tier: 'supporter');

    Scenarios::freeze($event->starts_at->subMinute()->format('Y-m-d H:i:s'));
    $late = Events::rsvps()->confirmPayment((string) $rsvp->payment_reference);

    expect($late->status)->toBe(RsvpStatus::Going)
        ->and($late->checkInCode())->not->toBeNull();

    // A repeat after the start stays an idempotent no-op on the going RSVP.
    Scenarios::freeze($event->ends_at->addDay()->format('Y-m-d H:i:s'));
    expect(Events::rsvps()->confirmPayment((string) $rsvp->payment_reference)->status)->toBe(RsvpStatus::Going);
});

test('a_manager_confirms_a_lapsed_hold_at_the_door_after_the_start', function (): void {
    $organizer = User::factory()->create();
    $event = Scenarios::event(capacity: 5, organizer: $organizer);
    Events::for($event)->tiers()->add('Supporter', price: Money::ofMajor('15', 'EUR'), key: 'supporter');
    $rsvp = Events::for($event)->rsvp(User::factory()->create())->going(tier: 'supporter');

    Scenarios::freeze($event->starts_at->addMinutes(20)->format('Y-m-d H:i:s'));
    $confirmed = Events::for($event)->by($organizer)->attendees()->confirm($rsvp, 'cash-7');

    expect($confirmed->status)->toBe(RsvpStatus::Going)
        ->and($confirmed->payment_reference)->toBe('cash-7');
});

test('a_manager_cannot_confirm_on_a_cancelled_event', function (): void {
    $event = paidEvent();
    $rsvp = Events::for($event)->rsvp(User::factory()->create())->going(tier: 'supporter');
    Events::for($event)->cancel();

    expect(fn () => Events::for($event)->attendees()->confirm($rsvp, 'cash-8'))->toThrow(RsvpClosedException::class)
        ->and(fn () => Events::rsvps()->confirm($rsvp->fresh() ?? $rsvp))->toThrow(RsvpClosedException::class)
        ->and($rsvp->fresh()?->status)->toBe(RsvpStatus::Expired);
});
