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
    $positives = array_filter($methods, static fn (string $name): bool => str_starts_with($name, 'assert')
        && ! str_starts_with($name, 'assertNothing') && ! preg_match('/^assertNo[A-Z]/', $name));

    expect($positives)->not->toBeEmpty();

    foreach ($positives as $positive) {
        $rest = substr($positive, 6);
        $pairs = ['assertNothing'.$rest, 'assertNo'.$rest];

        expect(array_intersect($pairs, $methods))->not->toBeEmpty("{$positive} has no negative pair");
    }

    // Nouns take `No`, verbs take `Nothing`.
    expect($methods)->toContain('assertNoVenueCreated', 'assertNoTierCreated', 'assertNoCoHostAdded', 'assertNoPaymentConfirmed', 'assertNothingRsvped', 'assertNothingCheckedIn');
});
