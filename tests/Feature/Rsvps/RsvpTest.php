<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event as EventBus;
use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Enums\Visibility;
use RoundlyConsulting\Events\Events\AttendeeConfirmed;
use RoundlyConsulting\Events\Events\AttendeePromoted;
use RoundlyConsulting\Events\Events\AttendeeWaitlisted;
use RoundlyConsulting\Events\Events\RsvpCancelled;
use RoundlyConsulting\Events\Events\RsvpChanged;
use RoundlyConsulting\Events\Exceptions\EventFullException;
use RoundlyConsulting\Events\Exceptions\InvalidGuestCountException;
use RoundlyConsulting\Events\Exceptions\InvalidRsvpTransitionException;
use RoundlyConsulting\Events\Exceptions\InvitationRequiredException;
use RoundlyConsulting\Events\Exceptions\RsvpClosedException;
use RoundlyConsulting\Events\Exceptions\TierNotFoundException;
use RoundlyConsulting\Events\Exceptions\TierNotOnSaleException;
use RoundlyConsulting\Events\Exceptions\TierRequiredException;
use RoundlyConsulting\Events\Exceptions\TierSoldOutException;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\EventInvitation;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Support\RandomCheckInCodes;
use RoundlyConsulting\Events\Tests\Fixtures\Models\Guest;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\RecordsLocks;
use RoundlyConsulting\Events\Tests\Support\Scenarios;
use RoundlyConsulting\Money\Money;

beforeEach(fn () => Scenarios::freeze());
afterEach(fn () => Scenarios::thaw());

it('takes a seat, issues a check-in code and counts it', function (): void {
    EventBus::fake([RsvpChanged::class, AttendeeConfirmed::class]);
    $event = Scenarios::event(capacity: 10, maxGuests: 2);
    $user = User::factory()->create();

    $rsvp = Events::for($event)->rsvp($user)->going(guests: 2, note: 'Vegetarian');

    expect($rsvp->status)->toBe(RsvpStatus::Going)
        ->and($rsvp->isGoing())->toBeTrue()
        ->and($rsvp->partySize())->toBe(3)
        ->and($rsvp->note)->toBe('Vegetarian')
        ->and($rsvp->checkInCode())->toStartWith(RandomCheckInCodes::PREFIX)
        ->and(strlen((string) $rsvp->checkInCode()))->toBe(36)
        ->and($event->fresh()?->seats_taken)->toBe(3)
        ->and($event->fresh()?->going_count)->toBe(3)
        ->and($user->isGoingTo($event))->toBeTrue()
        ->and($user->attendingEvents()->pluck('events.id')->all())->toBe([$event->getKey()])
        ->and(Events::for($event)->counts()->seatsLeft)->toBe(7);

    EventBus::assertDispatched(RsvpChanged::class, static fn (RsvpChanged $changed): bool => $changed->from === null);
    EventBus::assertDispatched(AttendeeConfirmed::class);

    // The same answer again changes nothing.
    $again = $user->rsvp($event)->going(guests: 2);
    expect($again->is($rsvp))->toBeTrue()
        ->and($event->fresh()?->seats_taken)->toBe(3)
        ->and(Rsvp::query()->count())->toBe(1);
});

test('rsvp_availability_by_phase', function (): void {
    $user = User::factory()->create();

    // A draft takes test answers from its managers only.
    $draft = Scenarios::event(publish: false);
    expect(fn () => Events::for($draft)->rsvp($user)->going())->toThrow(RsvpClosedException::class);
    $organizer = $draft->organizer;
    expect(Events::for($draft)->rsvp($organizer)->going()->status)->toBe(RsvpStatus::Going);

    // Postponed: interested and not going only.
    $postponed = Scenarios::event();
    Events::for($postponed)->postpone();
    expect(fn () => Events::for($postponed)->rsvp($user)->going())->toThrow(RsvpClosedException::class, 'This event takes no RSVPs now.')
        ->and(Events::for($postponed)->rsvp($user)->interested()->status)->toBe(RsvpStatus::Interested)
        ->and(Events::for($postponed)->rsvp($user)->notGoing()->status)->toBe(RsvpStatus::NotGoing);

    // Cancelled and completed take nothing.
    $cancelled = Scenarios::event();
    Events::for($cancelled)->cancel();
    expect(fn () => Events::for($cancelled)->rsvp($user)->interested())->toThrow(RsvpClosedException::class);

    // The RSVP window: before it opens, going waits; after it closes, going is refused.
    $windowed = Scenarios::event();
    Events::for($windowed)->edit()->rsvpWindow(opensAt: '2027-01-15 09:00', closesAt: '2027-02-09 18:00')->save();
    expect(fn () => Events::for($windowed)->rsvp($user)->going())->toThrow(RsvpClosedException::class, 'have not opened yet')
        ->and(Events::for($windowed)->rsvp($user)->interested()->status)->toBe(RsvpStatus::Interested);

    Scenarios::freeze('2027-01-20 10:00:00');
    expect(Events::for($windowed)->rsvp($user)->going()->status)->toBe(RsvpStatus::Going);

    Scenarios::freeze('2027-02-10 10:00:00');
    expect(fn () => Events::for($windowed)->rsvp(User::factory()->create())->going())->toThrow(RsvpClosedException::class)
        ->and(Events::for($windowed)->rsvp($user)->notGoing()->status)->toBe(RsvpStatus::NotGoing);
});

test('rsvps_freeze_at_the_start', function (): void {
    $event = Scenarios::event(capacity: 5);
    $user = User::factory()->create();
    Events::for($event)->rsvp($user)->going();

    Scenarios::freeze($event->starts_at->addMinute()->format('Y-m-d H:i:s'));

    expect(fn () => Events::for($event)->rsvp($user)->notGoing())->toThrow(RsvpClosedException::class, 'The event has started')
        ->and(fn () => Events::for($event)->rsvp(User::factory()->create())->interested())->toThrow(RsvpClosedException::class);

    // Managers' own verbs keep working: attendance is theirs to correct.
    expect(Events::for($event)->attendees()->remove($user)->find($user)?->status)->toBe(RsvpStatus::NotGoing);
});

test('guest_counts_stay_within_the_limits', function (): void {
    $event = Scenarios::event(capacity: 20, maxGuests: 1);
    $user = User::factory()->create();

    expect(fn () => Events::for($event)->rsvp($user)->going(guests: 2))->toThrow(InvalidGuestCountException::class, 'A party here is between 1 and 2 people.')
        ->and(fn () => Events::for($event)->rsvp($user)->going(guests: -1))->toThrow(InvalidGuestCountException::class);

    // A ticket type's own limit replaces the event's max_guests.
    Events::for($event)->tiers()->make('Family')->key('family')->perRsvp(min: 2, max: 4)->create();
    expect(Events::for($event)->rsvp($user)->going(tier: 'family', guests: 3)->partySize())->toBe(4)
        ->and(fn () => Events::for($event)->rsvp(User::factory()->create())->going(tier: 'family'))->toThrow(InvalidGuestCountException::class, 'between 2 and 4');
});

test('guest_counts_increase_needs_seats_and_a_decrease_promotes', function (): void {
    $event = Scenarios::event(capacity: 3, maxGuests: 2);
    $first = User::factory()->create();
    $waiting = User::factory()->create();

    Events::for($event)->rsvp($first)->going(guests: 1);
    Events::for($event)->rsvp(User::factory()->create())->going();
    expect(Events::for($event)->rsvp($waiting)->going()->status)->toBe(RsvpStatus::Waitlisted);

    // Going: a bigger party needs seats — no waitlist for part of a party.
    expect(fn () => Events::for($event)->rsvp($first)->going(guests: 2))->toThrow(EventFullException::class);

    // A smaller party frees a seat, and the waitlist moves up in the same transaction.
    Events::for($event)->rsvp($first)->going(guests: 0);

    expect($waiting->rsvpFor($event)?->status)->toBe(RsvpStatus::Going)
        ->and($event->fresh()?->seats_taken)->toBe(3)
        ->and($event->fresh()?->waitlist_count)->toBe(0);
});

test('private_rsvp_requires_invitation', function (): void {
    $event = Scenarios::event(capacity: 10);
    Events::for($event)->update(new UpdateEventData(visibility: Visibility::Private));
    $stranger = User::factory()->create();
    $invitee = User::factory()->create();
    $invitation = EventInvitation::factory()->for($event)->invitee($invitee)->create();

    expect(fn () => Events::for($event)->rsvp($stranger)->going())->toThrow(InvitationRequiredException::class, 'by invitation only')
        ->and(fn () => Events::for($event)->rsvp($stranger)->interested())->toThrow(InvitationRequiredException::class)
        // Leaving is always allowed.
        ->and(Events::for($event)->rsvp($stranger)->notGoing()->status)->toBe(RsvpStatus::NotGoing);

    // Going accepts the invitation.
    expect(Events::for($event)->rsvp($invitee)->going()->status)->toBe(RsvpStatus::Going)
        ->and($invitation->fresh()?->status->value)->toBe('accepted');

    // Managers need no invitation.
    expect(Events::for($event)->rsvp($event->organizer)->going()->status)->toBe(RsvpStatus::Going);
});

test('tier_resolution_rules', function (): void {
    $event = Scenarios::event(capacity: 50);
    $user = User::factory()->create();

    // Without ticket types: free admission, and naming one is not found.
    expect(fn () => Events::for($event)->rsvp($user)->going(tier: 'vip'))->toThrow(TierNotFoundException::class);

    $free = Events::for($event)->tiers()->add('Free entry', key: 'free');

    // Exactly one on sale: picked when omitted.
    expect(Events::for($event)->rsvp($user)->going()->tier_id)->toBe($free->getKey());

    $vip = Events::for($event)->tiers()->add('VIP', price: Money::ofMajor('40', 'EUR'));
    expect(fn () => Events::for($event)->rsvp(User::factory()->create())->going())->toThrow(TierRequiredException::class, 'Choose a ticket type.');

    // By model, id or key.
    expect(Events::for($event)->rsvp(User::factory()->create())->going(tier: $free)->tier_id)->toBe($free->getKey())
        ->and(Events::for($event)->rsvp(User::factory()->create())->going(tier: $free->getKey())->tier_id)->toBe($free->getKey())
        ->and(Events::for($event)->rsvp(User::factory()->create())->going(tier: 'vip')->tier_id)->toBe($vip->getKey());

    // Another event's ticket type is not found.
    $other = Events::for(Scenarios::event())->tiers()->add('Other', key: 'other');
    expect(fn () => Events::for($event)->rsvp(User::factory()->create())->going(tier: $other))->toThrow(TierNotFoundException::class);

    // Outside its sale window.
    $late = Events::for($event)->tiers()->make('Late')->key('late')->onSale(from: '2027-02-01 00:00')->create();
    expect(fn () => Events::for($event)->rsvp(User::factory()->create())->going(tier: $late))->toThrow(TierNotOnSaleException::class);
});

test('hidden_tiers_are_organizer_only', function (): void {
    $event = Scenarios::event(capacity: 10);
    Events::for($event)->tiers()->add('Speakers', hidden: true, key: 'speakers');
    Events::for($event)->tiers()->add('Free', key: 'free');
    $speaker = User::factory()->create();

    // Attendees never see it: unknown to them, and not listed.
    expect(fn () => Events::for($event)->rsvp($speaker)->going(tier: 'speakers'))->toThrow(TierNotFoundException::class)
        ->and(Events::for($event)->tiers()->available()->pluck('key')->all())->toBe(['free']);

    // Managers assign it.
    $rsvp = Events::for($event)->attendees()->add($speaker, tier: 'speakers');
    expect($rsvp->tier?->key)->toBe('speakers')
        ->and($rsvp->added_by_id)->toBeNull();

    $organizer = $event->organizer;
    $other = Events::for($event)->by($organizer)->attendees()->add(User::factory()->create(), tier: 'speakers');
    expect($other->added_by_id)->toBe($organizer->getKey());
});

test('newcomers_never_jump_a_fitting_waitlist', function (): void {
    $event = Scenarios::event(capacity: 2);
    Events::for($event)->update(new UpdateEventData(waitlistAutoPromote: false));
    $a = User::factory()->create();
    $waiting = User::factory()->create();

    Events::for($event)->rsvp($a)->going();
    Events::for($event)->rsvp(User::factory()->create())->going();
    Events::for($event)->rsvp($waiting)->going();

    // A seat frees up; automatic promotion is off, so it stays free while someone waits.
    Events::for($event)->rsvp($a)->notGoing();
    expect($event->fresh()?->seats_taken)->toBe(1);

    // A newcomer waits behind the fitting party instead of taking the seat.
    $newcomer = Events::for($event)->rsvp(User::factory()->create())->going();
    expect($newcomer->status)->toBe(RsvpStatus::Waitlisted)
        ->and($newcomer->waitlistPosition())->toBe(2);

    // A manager's promotion goes in queue order.
    $promoted = Events::for($event)->waitlist()->promote();
    expect($promoted->pluck('id')->all())->toBe([$waiting->rsvpFor($event)?->getKey()]);
});

test('waitlist_promotes_first_fit_in_order', function (): void {
    EventBus::fake([AttendeePromoted::class, AttendeeWaitlisted::class]);
    $event = Scenarios::event(capacity: 4, maxGuests: 3);
    $holder = User::factory()->create();
    Events::for($event)->rsvp($holder)->going(guests: 1);
    Events::for($event)->rsvp(User::factory()->create())->going(guests: 1);

    $big = User::factory()->create();
    $small = User::factory()->create();
    $last = User::factory()->create();
    Events::for($event)->rsvp($big)->going(guests: 3);   // party of 4: waits
    Events::for($event)->rsvp($small)->going();          // party of 1: waits
    Events::for($event)->rsvp($last)->going();           // party of 1: waits

    expect($big->rsvpFor($event)?->waitlistPosition())->toBe(1)
        ->and($small->rsvpFor($event)?->waitlistPosition())->toBe(2)
        ->and($event->fresh()?->waitlist_count)->toBe(6);
    EventBus::assertDispatchedTimes(AttendeeWaitlisted::class, 3);

    // Two seats free: the party of four does not fit and keeps its place; the next two do.
    Events::for($event)->rsvp($holder)->notGoing();

    expect($big->rsvpFor($event)?->status)->toBe(RsvpStatus::Waitlisted)
        ->and($big->rsvpFor($event)?->waitlistPosition())->toBe(1)
        ->and($small->rsvpFor($event)?->status)->toBe(RsvpStatus::Going)
        ->and($last->rsvpFor($event)?->status)->toBe(RsvpStatus::Going)
        ->and($event->fresh()?->seats_taken)->toBe(4)
        ->and($event->fresh()?->waitlist_count)->toBe(4);
    EventBus::assertDispatchedTimes(AttendeePromoted::class, 2);
});

test('promotion_respects_tier_capacity', function (): void {
    $event = Scenarios::event(capacity: 10);
    $tier = Events::for($event)->tiers()->make('Balcony')->key('balcony')->capacity(1)->create();
    Events::for($event)->tiers()->add('Floor', key: 'floor');
    $first = User::factory()->create();
    $waitsBalcony = User::factory()->create();

    Events::for($event)->rsvp($first)->going(tier: 'balcony');
    expect(Events::for($event)->rsvp($waitsBalcony)->going(tier: 'balcony')->status)->toBe(RsvpStatus::Waitlisted);

    // Floor seats free up: nobody on the balcony waitlist moves to them.
    $floor = User::factory()->create();
    Events::for($event)->rsvp($floor)->going(tier: 'floor');
    Events::for($event)->rsvp($floor)->notGoing();
    expect($waitsBalcony->rsvpFor($event)?->status)->toBe(RsvpStatus::Waitlisted);

    // A balcony seat frees up: the balcony waitlist moves.
    Events::for($event)->rsvp($first)->notGoing();
    expect($waitsBalcony->rsvpFor($event)?->status)->toBe(RsvpStatus::Going)
        ->and($tier->fresh()?->seats_taken)->toBe(1);

    // Without a waitlist a full ticket type is sold out, a full event full.
    $strict = Scenarios::event(capacity: 1, waitlist: false);
    Events::for($strict)->tiers()->make('Only')->key('only')->capacity(1)->create();
    Events::for($strict)->tiers()->add('Other', key: 'other');
    Events::for($strict)->rsvp(User::factory()->create())->going(tier: 'only');
    expect(fn () => Events::for($strict)->rsvp(User::factory()->create())->going(tier: 'other'))->toThrow(EventFullException::class);

    $soldOut = Scenarios::event(capacity: 5, waitlist: false);
    Events::for($soldOut)->tiers()->make('Only')->key('only')->capacity(1)->create();
    Events::for($soldOut)->rsvp(User::factory()->create())->going();
    expect(fn () => Events::for($soldOut)->rsvp(User::factory()->create())->going())->toThrow(TierSoldOutException::class, 'This ticket type is sold out.');
});

test('promotion_stops_at_the_cutoff', function (): void {
    $event = Scenarios::event(capacity: 1);
    $first = User::factory()->create();
    $waiting = User::factory()->create();
    Events::for($event)->rsvp($first)->going();
    Events::for($event)->rsvp($waiting)->going();

    // Within the last hour (waitlist.stop_promoting_before), freed seats stay free.
    Scenarios::minutesBeforeStart($event, 30);
    Events::for($event)->rsvp($first)->notGoing();

    expect($waiting->rsvpFor($event)?->status)->toBe(RsvpStatus::Waitlisted)
        ->and($event->fresh()?->seats_taken)->toBe(0);

    // A manager can still promote by hand.
    Events::for($event)->waitlist()->promote(limit: 1);
    expect($waiting->rsvpFor($event)?->status)->toBe(RsvpStatus::Going);
});

test('waitlist_position_is_stable', function (): void {
    $event = Scenarios::event(capacity: 1);
    Events::for($event)->rsvp(User::factory()->create())->going();
    $people = User::factory()->count(4)->create();

    foreach ($people as $person) {
        Events::for($event)->rsvp($person)->going();
    }

    $positions = $people->map(fn (User $person): ?int => Events::for($event)->waitlist()->position($person))->all();
    expect($positions)->toBe([1, 2, 3, 4]);

    // Someone in the middle leaves: those behind move up, those ahead stay.
    Events::for($event)->rsvp($people[1])->interested();
    expect(Events::for($event)->waitlist()->position($people[0]))->toBe(1)
        ->and(Events::for($event)->waitlist()->position($people[2]))->toBe(2)
        ->and(Events::for($event)->waitlist()->position($people[1]))->toBeNull()
        ->and(Events::for($event)->waitlist()->all()->pluck('attendee_id')->all())->toBe([$people[0]->getKey(), $people[2]->getKey(), $people[3]->getKey()]);

    // Answering going again keeps the place; a ticket type change moves to the back.
    Events::for($event)->rsvp($people[0])->going();
    expect(Events::for($event)->waitlist()->position($people[0]))->toBe(1);
});

test('checked_in_rsvps_are_sticky', function (): void {
    $event = Scenarios::event(capacity: 5);
    $user = User::factory()->create();
    $rsvp = Events::for($event)->rsvp($user)->going();
    $rsvp->forceFill(['checked_in_at' => Scenarios::now()])->save();

    expect(fn () => Events::for($event)->rsvp($user)->notGoing())->toThrow(InvalidRsvpTransitionException::class, 'A checked-in attendee cannot leave the event.')
        ->and(fn () => Events::for($event)->rsvp($user)->interested())->toThrow(InvalidRsvpTransitionException::class);
});

it('refuses a self-service change to or from a paid ticket type', function (): void {
    $event = Scenarios::event(capacity: 10);
    Events::for($event)->tiers()->add('Free', key: 'free');
    Events::for($event)->tiers()->add('Also free', key: 'also-free');
    Events::for($event)->tiers()->add('VIP', price: Money::ofMajor('30', 'EUR'), key: 'vip');
    $user = User::factory()->create();

    Events::for($event)->rsvp($user)->going(tier: 'free');

    // Between free ticket types: fine.
    expect(Events::for($event)->rsvp($user)->going(tier: 'also-free')->tier?->key)->toBe('also-free')
        // To a paid one: by hand only (no orders, no difference to collect).
        ->and(fn () => Events::for($event)->rsvp($user)->going(tier: 'vip'))->toThrow(InvalidRsvpTransitionException::class, 'can only be changed by the organizer');

    // A manager moves it.
    expect(Events::for($event)->attendees()->changeTier($user, 'vip')->status)->toBe(RsvpStatus::Going);
});

test('deleting_an_attendee_frees_its_seats', function (): void {
    $event = Scenarios::event(capacity: 1);
    $leaving = User::factory()->create();
    $waiting = User::factory()->create();
    Events::for($event)->rsvp($leaving)->going();
    Events::for($event)->rsvp($waiting)->going();

    // A soft delete keeps the RSVP.
    $leaving->delete();
    expect(Rsvp::query()->where('attendee_id', $leaving->getKey())->exists())->toBeTrue();

    // A force delete releases it, and the waitlist moves up.
    $leaving->forceDelete();

    expect(Rsvp::query()->where('attendee_id', $leaving->getKey())->exists())->toBeFalse()
        ->and($waiting->rsvpFor($event)?->status)->toBe(RsvpStatus::Going)
        ->and($event->fresh()?->seats_taken)->toBe(1);

    // A guest model without soft deletes.
    $guest = Guest::factory()->create();
    Events::for(Scenarios::event(capacity: 3))->rsvp($guest)->going();
    $guest->delete();
    expect(Rsvp::query()->where('attendee_type', $guest->getMorphClass())->exists())->toBeFalse();
});

test('lock_order_is_event_then_tiers_then_rsvps', function (): void {
    $event = Scenarios::event(capacity: 5);
    Events::for($event)->tiers()->add('A', key: 'a');
    Events::for($event)->tiers()->add('B', key: 'b');

    RecordsLocks::start();
    Events::for($event)->rsvp(User::factory()->create())->going(tier: 'b');

    $tables = RecordsLocks::tables();

    expect($tables[0])->toBe('events')
        ->and($tables[1])->toBe('event_admission_tiers')
        ->and(array_search('event_rsvps', $tables, true))->toBeGreaterThan(1);
})->skip(fn (): bool => ! RecordsLocks::available(), 'the lock recorder runs on sqlite');

it('fires RsvpCancelled when someone leaves their seats', function (): void {
    EventBus::fake([RsvpCancelled::class]);
    $event = Scenarios::event(capacity: 5);
    $user = User::factory()->create();

    Events::for($event)->rsvp($user)->going();
    Events::for($event)->rsvp($user)->interested();

    EventBus::assertDispatched(RsvpCancelled::class, static fn (RsvpCancelled $cancelled): bool => ! $cancelled->wasPaid);
    expect($event->fresh()?->interested_count)->toBe(1)
        ->and($event->fresh()?->seats_taken)->toBe(0);
});
