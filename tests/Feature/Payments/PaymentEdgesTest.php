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
