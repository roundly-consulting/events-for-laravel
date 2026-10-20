<?php

declare(strict_types=1);

use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Testing\EventsFake;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\Scenarios;

beforeEach(fn () => Scenarios::freeze());
afterEach(fn () => Scenarios::thaw());

it('records check-ins, invitations and co-hosts', function (): void {
    $fake = Events::fake();
    $event = Scenarios::event(capacity: 5);
    $member = User::factory()->create();
    $walkIn = User::factory()->create();
    $friend = User::factory()->create();
    $alice = User::factory()->create();

    $fake->assertNothingCheckedIn();
    $fake->assertNothingInvited();
    $fake->assertNoCoHostAdded();

    $code = (string) $member->rsvp($event)->going()->checkInCode();
    Scenarios::minutesBeforeStart($event, 30);
    Events::for($event)->checkIn($code);
    Events::for($event)->attendees()->add($walkIn);
    Events::for($event)->attendees()->checkIn($walkIn);
    Events::for($event)->invitations()->send($friend);
    Events::for($event)->hosts()->add($alice);

    $fake->assertCheckedIn($event, $member);
    $fake->assertCheckedIn($event, $walkIn);
    $fake->assertCheckedIn();
    $fake->assertInvited($event, $friend);
    $fake->assertCoHostAdded($event, $alice);

    expect(fn () => $fake->assertCheckedIn($event, $friend))->toThrow(ExpectationFailedException::class, 'none matched')
        ->and(fn () => $fake->assertInvited($event, $alice))->toThrow(ExpectationFailedException::class, 'none matched')
        ->and(fn () => $fake->assertCoHostAdded($event, $friend))->toThrow(ExpectationFailedException::class, 'none matched')
        ->and(fn () => $fake->assertNothingCheckedIn())->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertNothingInvited())->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertNoCoHostAdded())->toThrow(ExpectationFailedException::class);

    // The package's own matchers.
    expect($member)->toBeGoingTo($event)->toBeCheckedInTo($event)
        ->and($friend)->not->toBeGoingTo($event);
});

it('fails the check-in assertion before any check-in', function (): void {
    Events::fake()->assertCheckedIn();
})->throws(ExpectationFailedException::class, 'Expected a [check_in|manual_check_in] event operation, but none was recorded.');

test('expectations_pass_and_fail', function (): void {
    $event = Scenarios::event(capacity: 1);
    $going = User::factory()->create();
    $waiting = User::factory()->create();
    Events::for($event)->rsvp($going)->going();
    Events::for($event)->rsvp($waiting)->going();

    expect($going)->toBeGoingTo($event)
        ->and($waiting)->toBeWaitlistedFor($event)
        ->and(fn () => expect($waiting)->toBeGoingTo($event))->toThrow(ExpectationFailedException::class, 'they are waitlisted')
        ->and(fn () => expect($going)->toBeWaitlistedFor($event))->toThrow(ExpectationFailedException::class, 'they are going')
        ->and(fn () => expect($going)->toBeCheckedInTo($event))->toThrow(ExpectationFailedException::class, 'not')
        ->and(fn () => expect(User::factory()->create())->toBeGoingTo($event))->toThrow(ExpectationFailedException::class, 'not on its list')
        ->and(fn () => expect('nobody')->toBeGoingTo($event))->toThrow(ExpectationFailedException::class);
});

test('fake_naming_rule_is_pinned', function (): void {
    // Every assert<Verb> has assertNothing<Verb>; every assert<Noun><Verb> has assertNo<Noun><Verb>.
    $methods = array_map(static fn (ReflectionMethod $method): string => $method->getName(), (new ReflectionClass(EventsFake::class))->getMethods(ReflectionMethod::IS_PUBLIC));
    // The generic pair is the one exception: assertPerformed ↔ assertNotPerformed.
    expect($methods)->toContain('assertPerformed', 'assertNotPerformed');
    $positives = array_filter($methods, static fn (string $name): bool => str_starts_with($name, 'assert')
        && ! str_starts_with($name, 'assertNothing') && ! preg_match('/^assertNo[A-Z]/', $name)
        && ! in_array($name, ['assertPerformed', 'assertNotPerformed'], true));

    expect($positives)->not->toBeEmpty();

    foreach ($positives as $positive) {
        $rest = substr($positive, 6);
        $pairs = ['assertNothing'.$rest, 'assertNo'.$rest];

        expect(array_intersect($pairs, $methods))->not->toBeEmpty("{$positive} has no negative pair");
    }

    // Nouns take `No`, verbs take `Nothing`.
    expect($methods)->toContain('assertNoVenueCreated', 'assertNoTierCreated', 'assertNoCoHostAdded', 'assertNoPaymentConfirmed', 'assertNothingRsvped', 'assertNothingCheckedIn',
        'assertNothingDeleted', 'assertNoPosterChanged', 'assertNoTiersReordered', 'assertNoAttendeeForgotten', 'assertNoInvitationAnswered');
});

test('fake_ignores_no_op_co_host_and_undo_calls', function (): void {
    $organizer = User::factory()->create();
    $event = Scenarios::event(organizer: $organizer, capacity: 5);
    $stranger = User::factory()->create();
    $host = User::factory()->create();
    $attendee = User::factory()->create();
    Events::for($event)->rsvp($attendee)->going();
    $fake = Events::fake();

    // Calls that change nothing: the organizer is never their own co-host, a stranger is no
    // co-host to remove, and an attendee who never checked in has nothing to undo.
    Events::for($event)->hosts()->add($organizer);
    Events::for($event)->hosts()->remove($stranger);
    Events::for($event)->attendees()->undoCheckIn($attendee);

    $fake->assertNoCoHostAdded();
    $fake->assertNoCoHostRemoved();
    $fake->assertNoCheckInUndone();
    expect(fn () => $fake->assertCoHostAdded($event, $organizer))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertCoHostRemoved($event, $stranger))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertCheckInUndone($event))->toThrow(ExpectationFailedException::class);

    // The same calls when they do something.
    Events::for($event)->hosts()->add($host);
    Events::for($event)->hosts()->remove($host);
    Scenarios::minutesBeforeStart($event, 30);
    Events::for($event)->attendees()->checkIn($attendee);
    Events::for($event)->attendees()->undoCheckIn($attendee);

    $fake->assertCoHostAdded($event, $host);
    $fake->assertCoHostRemoved($event, $host);
    $fake->assertCheckInUndone($event);
    expect(fn () => $fake->assertNoCoHostAdded())->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertNoCoHostRemoved())->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertNoCheckInUndone())->toThrow(ExpectationFailedException::class);
});

test('a_repeat_scan_satisfies_assert_nothing_checked_in', function (): void {
    $event = Scenarios::event(capacity: 5);
    $member = User::factory()->create();
    $code = (string) Events::for($event)->rsvp($member)->going()->checkInCode();
    Scenarios::minutesBeforeStart($event, 30);
    Events::for($event)->checkIn($code);
    $fake = Events::fake();

    // Scanned again (or checked in by hand again): nobody new came through the door.
    Events::for($event)->checkIn($code);
    Events::for($event)->attendees()->checkIn($member);

    $fake->assertNothingCheckedIn();
    expect(fn () => $fake->assertCheckedIn($event, $member))->toThrow(ExpectationFailedException::class);

    // A first check-in counts.
    $walkIn = User::factory()->create();
    Events::for($event)->attendees()->add($walkIn);
    Events::for($event)->attendees()->checkIn($walkIn);

    $fake->assertCheckedIn($event, $walkIn);
    expect(fn () => $fake->assertNothingCheckedIn())->toThrow(ExpectationFailedException::class);
});
