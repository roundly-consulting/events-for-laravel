<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\Enums\EventOperation;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\AdmissionTier;
use RoundlyConsulting\Events\Testing\EventsFake;
use RoundlyConsulting\Events\Testing\RecordedEventOperation;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Fixtures\Payments\FakePaymentCollector;
use RoundlyConsulting\Events\Tests\Support\Scenarios;
use RoundlyConsulting\Money\Money;

beforeEach(fn () => Scenarios::freeze());
afterEach(fn () => Scenarios::thaw());

it('records answers through the handle and the model trait', function (): void {
    $fake = Events::fake();
    $event = Scenarios::event(capacity: 1);
    $member = User::factory()->create();
    $late = User::factory()->create();

    // Through the trait: it delegates to the manager, so the fake sees it.
    $member->rsvp($event)->going();
    Events::for($event)->rsvp($late)->going();

    $fake->assertRsvped($event, $member, RsvpStatus::Going);
    $fake->assertRsvped($event, $late, RsvpStatus::Waitlisted);
    $fake->assertRsvped();
    Events::assertRsvped($event, $member);

    expect(fn () => $fake->assertRsvped($event, $member, RsvpStatus::Interested))->toThrow(ExpectationFailedException::class, 'none matched')
        ->and(fn () => $fake->assertNothingRsvped())->toThrow(ExpectationFailedException::class, 'Expected no [rsvp] event operation');
});

it('passes and fails the remaining assertions', function (): void {
    FakePaymentCollector::reset();
    config()->set('events.payments.collector', FakePaymentCollector::class);
    $fake = Events::fake();

    // Nothing yet.
    $fake->assertNothingRsvped();
    $fake->assertNothingPromoted();
    $fake->assertNoPaymentConfirmed();
    $fake->assertNoTierCreated();
    $fake->assertNothingSwept();

    expect(fn () => $fake->assertPromoted())->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertPaymentConfirmed())->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertTierCreated())->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertSwept())->toThrow(ExpectationFailedException::class);

    $event = Scenarios::event(capacity: 1);
    Events::for($event)->tiers()->add('Paid', price: Money::ofMajor('5', 'EUR'), key: 'paid');
    $held = Events::for($event)->rsvp(User::factory()->create())->going();
    $waiting = User::factory()->create();
    Events::for($event)->rsvp($waiting)->going();
    Events::rsvps()->confirmPayment((string) $held->payment_reference);
    Events::for($event)->update(new UpdateEventData(capacity: 1, waitlistAutoPromote: false));
    Events::for($event)->update(new UpdateEventData(capacity: 2));
    Events::for($event)->waitlist()->promote();
    Events::sweep();

    $fake->assertTierCreated(static fn (AdmissionTier $tier): bool => $tier->key === 'paid');
    $fake->assertPaymentConfirmed((string) $held->payment_reference);
    $fake->assertPaymentConfirmed();
    $fake->assertPromoted($event);
    $fake->assertSwept();

    expect(fn () => $fake->assertNoTierCreated())->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertNoPaymentConfirmed())->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertNothingPromoted())->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertNothingSwept())->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertPaymentConfirmed('other'))->toThrow(ExpectationFailedException::class, 'none matched')
        ->and($fake)->toBeInstanceOf(EventsFake::class)
        ->and($waiting->rsvpFor($event)?->status)->toBe(RsvpStatus::Held);
});

it('reads the managers\' side of the list', function (): void {
    $event = Scenarios::event(capacity: 10, maxGuests: 3);
    $a = User::factory()->create();
    $b = User::factory()->create();
    $organizer = $event->organizer;

    Events::for($event)->rsvp($a)->going();
    Events::for($event)->rsvp($b)->interested();
    $grown = Events::for($event)->by($organizer)->attendees()->changeGuests($a, 2);

    expect($grown->partySize())->toBe(3)
        ->and(Events::for($event)->attendees()->going()->pluck('attendee_id')->all())->toBe([$a->getKey()])
        ->and(Events::for($event)->attendees()->interested()->pluck('attendee_id')->all())->toBe([$b->getKey()])
        ->and(Events::for($event)->attendees()->find($b)?->status)->toBe(RsvpStatus::Interested)
        ->and(Events::for($event)->attendees()->changeGuests($a, 2)->partySize())->toBe(3)
        ->and($b->interestingEvents()->pluck('events.id')->all())->toBe([$event->getKey()]);

    $counts = Events::for($event)->counts();
    expect([$counts->going, $counts->interested, $counts->waitlisted, $counts->seatsLeft])->toBe([3, 1, 0, 7]);

    // Answering for someone else needs a manager; answering for oneself needs `rsvp`.
    expect(fn () => Events::for($event)->rsvp($b)->by($a)->going())->toThrow(AuthorizationException::class)
        ->and(Events::for($event)->rsvp($b)->by($organizer)->going()->status)->toBe(RsvpStatus::Going)
        ->and(Events::for($event)->rsvp($a)->by($a)->interested()->status)->toBe(RsvpStatus::Interested);

    $draft = Scenarios::event(publish: false);
    expect(fn () => Events::for($draft)->rsvp($a)->by($a)->going())->toThrow(AuthorizationException::class);
});

test('a_paid_rsvp_records_its_payment_request', function (): void {
    FakePaymentCollector::reset();
    config()->set('events.payments.collector', FakePaymentCollector::class);
    $event = Scenarios::event(capacity: 5);
    Events::for($event)->tiers()->add('Paid', price: Money::ofMajor('15', 'EUR'), key: 'paid');
    $fake = Events::fake();
    $attendee = User::factory()->create();

    // The attendee's own answer asks for the checkout after commit — through the manager, with
    // no actor (the attendee needs no ability for their own checkout), so the fake sees it.
    $rsvp = Events::for($event)->by($attendee)->rsvp($attendee)->going(tier: 'paid');

    expect(FakePaymentCollector::$calls)->toHaveCount(1)
        ->and($rsvp->fresh()?->payment_reference)->toBe('pay_'.$rsvp->getKey().'_1');
    $fake->assertPaymentRequested($event);
    $fake->assertPerformed(EventOperation::RequestPayment, static fn (RecordedEventOperation $recorded): bool => $recorded->model('actor') === null
        && $recorded->model('rsvp')?->is($rsvp) === true);
    expect(fn () => $fake->assertNoPaymentRequested())->toThrow(ExpectationFailedException::class);
});
