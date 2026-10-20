<?php

declare(strict_types=1);

use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\DataTransferObjects\UpdateTierData;
use RoundlyConsulting\Events\DataTransferObjects\UpdateVenueData;
use RoundlyConsulting\Events\Enums\EventOperation;
use RoundlyConsulting\Events\Enums\RsvpResponse;
use RoundlyConsulting\Events\Enums\Visibility;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\AdmissionTier;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Testing\EventsFake;
use RoundlyConsulting\Events\Testing\RecordedEventOperation;
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

/**
 * Every operation and the named pair that asserts it.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function assertionPairs(): array
{
    return [
        'create' => ['assertCreated', 'assertNothingCreated'],
        'update' => ['assertUpdated', 'assertNothingUpdated'],
        'delete' => ['assertDeleted', 'assertNothingDeleted'],
        'restore' => ['assertRestored', 'assertNothingRestored'],
        'publish' => ['assertPublished', 'assertNothingPublished'],
        'unpublish' => ['assertUnpublished', 'assertNothingUnpublished'],
        'postpone' => ['assertPostponed', 'assertNothingPostponed'],
        'reschedule' => ['assertRescheduled', 'assertNothingRescheduled'],
        'cancel' => ['assertCancelled', 'assertNothingCancelled'],
        'complete' => ['assertCompleted', 'assertNothingCompleted'],
        'transition' => ['assertTransitioned', 'assertNothingTransitioned'],
        'move_online' => ['assertMovedOnline', 'assertNothingMovedOnline'],
        'transfer_organizer' => ['assertTransferred', 'assertNothingTransferred'],
        'set_poster' => ['assertPosterChanged', 'assertNoPosterChanged'],
        'categorize' => ['assertCategorized', 'assertNothingCategorized'],
        'attach_labels' => ['assertLabelled', 'assertNothingLabelled'],
        'detach_labels' => ['assertUnlabelled', 'assertNothingUnlabelled'],
        'sync_labels' => ['assertLabelled', 'assertNothingLabelled'],
        'create_venue' => ['assertVenueCreated', 'assertNoVenueCreated'],
        'update_venue' => ['assertVenueUpdated', 'assertNoVenueUpdated'],
        'delete_venue' => ['assertVenueDeleted', 'assertNoVenueDeleted'],
        'restore_venue' => ['assertVenueRestored', 'assertNoVenueRestored'],
        'geocode_venue' => ['assertVenueGeocoded', 'assertNoVenueGeocoded'],
        'create_tier' => ['assertTierCreated', 'assertNoTierCreated'],
        'update_tier' => ['assertTierUpdated', 'assertNoTierUpdated'],
        'delete_tier' => ['assertTierDeleted', 'assertNoTierDeleted'],
        'restore_tier' => ['assertTierRestored', 'assertNoTierRestored'],
        'reorder_tiers' => ['assertTiersReordered', 'assertNoTiersReordered'],
        'rsvp' => ['assertRsvped', 'assertNothingRsvped'],
        'add_attendee' => ['assertAttendeeAdded', 'assertNoAttendeeAdded'],
        'remove_attendee' => ['assertAttendeeRemoved', 'assertNoAttendeeRemoved'],
        'change_tier' => ['assertTierChanged', 'assertNoTierChanged'],
        'change_guests' => ['assertGuestsChanged', 'assertNoGuestsChanged'],
        'confirm_rsvp' => ['assertRsvpConfirmed', 'assertNoRsvpConfirmed'],
        'confirm_payment' => ['assertPaymentConfirmed', 'assertNoPaymentConfirmed'],
        'release_hold' => ['assertHoldReleased', 'assertNoHoldReleased'],
        'request_payment' => ['assertPaymentRequested', 'assertNoPaymentRequested'],
        'promote_waitlist' => ['assertPromoted', 'assertNothingPromoted'],
        'rotate_code' => ['assertCodeRotated', 'assertNoCodeRotated'],
        'forget_attendee' => ['assertAttendeeForgotten', 'assertNoAttendeeForgotten'],
        'check_in' => ['assertCheckedIn', 'assertNothingCheckedIn'],
        'manual_check_in' => ['assertCheckedIn', 'assertNothingCheckedIn'],
        'undo_check_in' => ['assertCheckInUndone', 'assertNoCheckInUndone'],
        'add_co_host' => ['assertCoHostAdded', 'assertNoCoHostAdded'],
        'remove_co_host' => ['assertCoHostRemoved', 'assertNoCoHostRemoved'],
        'send_invitation' => ['assertInvited', 'assertNothingInvited'],
        'revoke_invitation' => ['assertInvitationRevoked', 'assertNoInvitationRevoked'],
        'respond_to_invitation' => ['assertInvitationAnswered', 'assertNoInvitationAnswered'],
        'sweep' => ['assertSwept', 'assertNothingSwept'],
        'repair' => ['assertRepaired', 'assertNothingRepaired'],
    ];
}

function catalogEvent(int $capacity = 10, int $maxGuests = 2): Event
{
    return Scenarios::event(capacity: $capacity, maxGuests: $maxGuests);
}

function paidCatalogEvent(): Event
{
    $event = catalogEvent();
    Events::for($event)->tiers()->add('Paid', price: Money::ofMajor('5', 'EUR'), key: 'paid');

    return $event;
}

test('every_operation_has_an_assertion_pair', function (): void {
    $pairs = assertionPairs();
    $operations = array_map(static fn (EventOperation $operation): string => $operation->value, EventOperation::cases());

    expect(array_keys($pairs))->toEqualCanonicalizing($operations);

    foreach ($pairs as $operation => [$positive, $negative]) {
        expect(method_exists(EventsFake::class, $positive))->toBeTrue("{$operation}: {$positive} is missing")
            ->and(method_exists(EventsFake::class, $negative))->toBeTrue("{$operation}: {$negative} is missing");
    }
});

/*
 * Each case: the operation (run under the fake), the named assertion with a match, and a
 * filter that must not match. The negative passes before, the positive after — and each fails
 * the other way round.
 */
dataset('operations', [
    'delete' => [static function (): array {
        $event = catalogEvent();

        return [fn () => Events::for($event)->delete(), fn (EventsFake $fake) => $fake->assertDeleted($event), fn (EventsFake $fake) => $fake->assertDeleted(catalogEvent()), 'assertNothingDeleted'];
    }],
    'restore' => [static function (): array {
        $event = catalogEvent();
        Events::for($event)->delete();

        return [fn () => Events::for($event)->restore(), fn (EventsFake $fake) => $fake->assertRestored($event), fn (EventsFake $fake) => $fake->assertRestored(catalogEvent()), 'assertNothingRestored'];
    }],
    'unpublish' => [static function (): array {
        $event = catalogEvent();

        return [fn () => Events::for($event)->unpublish(), fn (EventsFake $fake) => $fake->assertUnpublished($event), fn (EventsFake $fake) => $fake->assertUnpublished(catalogEvent()), 'assertNothingUnpublished'];
    }],
    'transition' => [static function (): array {
        $event = catalogEvent();

        return [fn () => Events::for($event)->transition('postpone'), fn (EventsFake $fake) => $fake->assertTransitioned($event, 'postpone'), fn (EventsFake $fake) => $fake->assertTransitioned($event, 'cancel'), 'assertNothingTransitioned'];
    }],
    'move online' => [static function (): array {
        $event = catalogEvent();

        return [fn () => Events::for($event)->moveOnline('https://meet.example.com/room'), fn (EventsFake $fake) => $fake->assertMovedOnline($event), fn (EventsFake $fake) => $fake->assertMovedOnline(catalogEvent()), 'assertNothingMovedOnline'];
    }],
    'transfer' => [static function (): array {
        $event = catalogEvent();
        $to = User::factory()->create();

        return [fn () => Events::for($event)->transferTo($to), fn (EventsFake $fake) => $fake->assertTransferred($event, $to), fn (EventsFake $fake) => $fake->assertTransferred($event, User::factory()->create()), 'assertNothingTransferred'];
    }],
    'poster' => [static function (): array {
        $event = catalogEvent();

        return [fn () => Events::for($event)->poster(), fn (EventsFake $fake) => $fake->assertPosterChanged($event, removed: true), fn (EventsFake $fake) => $fake->assertPosterChanged($event, removed: false), 'assertNoPosterChanged'];
    }],
    'unlabel' => [static function (): array {
        $event = catalogEvent();
        Events::for($event)->labels()->attach(['Live']);

        return [fn () => Events::for($event)->labels()->detach(['Live']), fn (EventsFake $fake) => $fake->assertUnlabelled($event, ['live']), fn (EventsFake $fake) => $fake->assertUnlabelled($event, ['Other']), 'assertNothingUnlabelled'];
    }],
    'update venue' => [static function (): array {
        $venue = Venue::factory()->create();

        return [fn () => Events::venues()->update($venue, new UpdateVenueData(capacity: 5)), fn (EventsFake $fake) => $fake->assertVenueUpdated(static fn (Venue $updated): bool => $updated->capacity === 5), fn (EventsFake $fake) => $fake->assertVenueUpdated(static fn (Venue $updated): bool => $updated->capacity === 6), 'assertNoVenueUpdated'];
    }],
    'delete venue' => [static function (): array {
        $venue = Venue::factory()->create();

        return [fn () => Events::venues()->delete($venue), fn (EventsFake $fake) => $fake->assertVenueDeleted(static fn (Venue $deleted): bool => $deleted->is($venue)), fn (EventsFake $fake) => $fake->assertVenueDeleted(static fn (): bool => false), 'assertNoVenueDeleted'];
    }],
    'restore venue' => [static function (): array {
        $venue = Venue::factory()->create();
        Events::venues()->delete($venue);

        return [fn () => Events::venues()->restore($venue), fn (EventsFake $fake) => $fake->assertVenueRestored(static fn (Venue $restored): bool => $restored->is($venue)), fn (EventsFake $fake) => $fake->assertVenueRestored(static fn (): bool => false), 'assertNoVenueRestored'];
    }],
    'geocode venue' => [static function (): array {
        $venue = Venue::factory()->create();

        return [fn () => Events::venues()->geocode($venue), fn (EventsFake $fake) => $fake->assertVenueGeocoded(static fn (Venue $geocoded): bool => $geocoded->is($venue)), fn (EventsFake $fake) => $fake->assertVenueGeocoded(static fn (): bool => false), 'assertNoVenueGeocoded'];
    }],
    'update tier' => [static function (): array {
        $event = catalogEvent();
        Events::for($event)->tiers()->add('Standard', key: 'std');

        return [fn () => Events::for($event)->tiers()->update('std', new UpdateTierData(capacity: 3)), fn (EventsFake $fake) => $fake->assertTierUpdated(static fn (AdmissionTier $tier): bool => $tier->capacity === 3), fn (EventsFake $fake) => $fake->assertTierUpdated(static fn (AdmissionTier $tier): bool => $tier->capacity === 4), 'assertNoTierUpdated'];
    }],
    'delete tier' => [static function (): array {
        $event = catalogEvent();
        Events::for($event)->tiers()->add('Standard', key: 'std');

        return [fn () => Events::for($event)->tiers()->remove('std'), fn (EventsFake $fake) => $fake->assertTierDeleted(static fn (AdmissionTier $tier): bool => $tier->key === 'std'), fn (EventsFake $fake) => $fake->assertTierDeleted(static fn (AdmissionTier $tier): bool => $tier->key === 'vip'), 'assertNoTierDeleted'];
    }],
    'delete tier by id' => [static function (): array {
        $event = catalogEvent();
        $tier = Events::for($event)->tiers()->add('Standard', key: 'std');

        return [fn () => Events::for($event)->tiers()->remove((int) $tier->getKey()), fn (EventsFake $fake) => $fake->assertTierDeleted(static fn (AdmissionTier $deleted): bool => $deleted->is($tier)), fn (EventsFake $fake) => $fake->assertTierDeleted(static fn (): bool => false), 'assertNoTierDeleted'];
    }],
    'restore tier' => [static function (): array {
        $event = catalogEvent();
        Events::for($event)->tiers()->add('Standard', key: 'std');
        Events::for($event)->tiers()->remove('std');

        return [fn () => Events::for($event)->tiers()->restore('std'), fn (EventsFake $fake) => $fake->assertTierRestored(static fn (AdmissionTier $tier): bool => $tier->key === 'std'), fn (EventsFake $fake) => $fake->assertTierRestored(static fn (): bool => false), 'assertNoTierRestored'];
    }],
    'reorder tiers' => [static function (): array {
        $event = catalogEvent();
        Events::for($event)->tiers()->add('A', key: 'a');
        Events::for($event)->tiers()->add('B', key: 'b');

        return [fn () => Events::for($event)->tiers()->reorder(['b', 'a']), fn (EventsFake $fake) => $fake->assertTiersReordered($event), fn (EventsFake $fake) => $fake->assertTiersReordered(catalogEvent()), 'assertNoTiersReordered'];
    }],
    'add attendee' => [static function (): array {
        $event = catalogEvent();
        $user = User::factory()->create();

        return [fn () => Events::for($event)->attendees()->add($user), fn (EventsFake $fake) => $fake->assertAttendeeAdded($event, $user), fn (EventsFake $fake) => $fake->assertAttendeeAdded($event, User::factory()->create()), 'assertNoAttendeeAdded'];
    }],
    'remove attendee' => [static function (): array {
        $event = catalogEvent();
        $user = User::factory()->create();
        $rsvp = Events::for($event)->rsvp($user)->going();

        return [fn () => Events::for($event)->attendees()->remove($rsvp), fn (EventsFake $fake) => $fake->assertAttendeeRemoved($event, $user), fn (EventsFake $fake) => $fake->assertAttendeeRemoved($event, User::factory()->create()), 'assertNoAttendeeRemoved'];
    }],
    'change tier' => [static function (): array {
        $event = catalogEvent();
        Events::for($event)->tiers()->add('A', key: 'a');
        Events::for($event)->tiers()->add('B', key: 'b');
        $user = User::factory()->create();
        Events::for($event)->rsvp($user)->going(tier: 'a');

        return [fn () => Events::for($event)->attendees()->changeTier($user, 'b'), fn (EventsFake $fake) => $fake->assertTierChanged($event), fn (EventsFake $fake) => $fake->assertTierChanged(catalogEvent()), 'assertNoTierChanged'];
    }],
    'change guests' => [static function (): array {
        $event = catalogEvent();
        $user = User::factory()->create();
        Events::for($event)->rsvp($user)->going();

        return [fn () => Events::for($event)->attendees()->changeGuests($user, 1), fn (EventsFake $fake) => $fake->assertGuestsChanged($event), fn (EventsFake $fake) => $fake->assertGuestsChanged(catalogEvent()), 'assertNoGuestsChanged'];
    }],
    'confirm rsvp' => [static function (): array {
        $event = paidCatalogEvent();
        $user = User::factory()->create();
        Events::for($event)->rsvp($user)->going();

        return [fn () => Events::for($event)->attendees()->confirm($user, 'cash-1'), fn (EventsFake $fake) => $fake->assertRsvpConfirmed($event), fn (EventsFake $fake) => $fake->assertRsvpConfirmed(catalogEvent()), 'assertNoRsvpConfirmed'];
    }],
    'release hold' => [static function (): array {
        $event = paidCatalogEvent();
        $held = Events::for($event)->rsvp(User::factory()->create())->going();

        return [fn () => Events::rsvps()->release($held), fn (EventsFake $fake) => $fake->assertHoldReleased($event), fn (EventsFake $fake) => $fake->assertHoldReleased(catalogEvent()), 'assertNoHoldReleased'];
    }],
    'request payment' => [static function (): array {
        $event = paidCatalogEvent();
        // The answer's own request is recorded too (it goes through the manager): let it fail,
        // which records nothing, so the host asks again by hand below.
        FakePaymentCollector::$fails = true;

        try {
            Events::for($event)->rsvp($user = User::factory()->create())->going();
        } catch (RuntimeException) {
            // The collector's failure reaches the caller.
        }

        FakePaymentCollector::$fails = false;
        $held = $user->rsvpFor($event) ?? throw new RuntimeException('No hold.');

        return [fn () => Events::rsvps()->requestPayment($held), fn (EventsFake $fake) => $fake->assertPaymentRequested($event), fn (EventsFake $fake) => $fake->assertPaymentRequested(catalogEvent()), 'assertNoPaymentRequested'];
    }],
    'rotate code' => [static function (): array {
        $event = catalogEvent();
        $rsvp = Events::for($event)->rsvp(User::factory()->create())->going();

        return [fn () => Events::rsvps()->rotateCode($rsvp), fn (EventsFake $fake) => $fake->assertCodeRotated($event), fn (EventsFake $fake) => $fake->assertCodeRotated(catalogEvent()), 'assertNoCodeRotated'];
    }],
    'undo check-in' => [static function (): array {
        $event = catalogEvent();
        $user = User::factory()->create();
        Events::for($event)->rsvp($user)->going();
        Scenarios::minutesBeforeStart($event, 30);
        Events::for($event)->attendees()->checkIn($user);

        return [fn () => Events::for($event)->attendees()->undoCheckIn($user), fn (EventsFake $fake) => $fake->assertCheckInUndone($event), fn (EventsFake $fake) => $fake->assertCheckInUndone(catalogEvent()), 'assertNoCheckInUndone'];
    }],
    'remove co-host' => [static function (): array {
        $event = catalogEvent();
        $host = User::factory()->create();
        Events::for($event)->hosts()->add($host);

        return [fn () => Events::for($event)->hosts()->remove($host), fn (EventsFake $fake) => $fake->assertCoHostRemoved($event, $host), fn (EventsFake $fake) => $fake->assertCoHostRemoved($event, User::factory()->create()), 'assertNoCoHostRemoved'];
    }],
    'revoke invitation' => [static function (): array {
        $event = catalogEvent();
        $invitee = User::factory()->create();
        Events::for($event)->invitations()->send($invitee);

        return [fn () => Events::for($event)->invitations()->revoke($invitee), fn (EventsFake $fake) => $fake->assertInvitationRevoked($event, $invitee), fn (EventsFake $fake) => $fake->assertInvitationRevoked($event, User::factory()->create()), 'assertNoInvitationRevoked'];
    }],
    'answer invitation' => [static function (): array {
        $event = catalogEvent();
        Events::for($event)->update(new UpdateEventData(visibility: Visibility::Private));
        $invitee = User::factory()->create();
        $token = (string) Events::for($event)->invitations()->send($invitee)->plainToken;

        return [fn () => Events::invitations()->accept($token, by: $invitee), fn (EventsFake $fake) => $fake->assertInvitationAnswered($event, RsvpResponse::Going), fn (EventsFake $fake) => $fake->assertInvitationAnswered($event, RsvpResponse::NotGoing), 'assertNoInvitationAnswered'];
    }],
    'decline invitation' => [static function (): array {
        $event = catalogEvent();
        Events::for($event)->update(new UpdateEventData(visibility: Visibility::Private));
        $invitee = User::factory()->create();
        $token = (string) Events::for($event)->invitations()->send($invitee)->plainToken;

        return [fn () => Events::invitations()->decline($token, by: $invitee), fn (EventsFake $fake) => $fake->assertInvitationAnswered($event, RsvpResponse::NotGoing), fn (EventsFake $fake) => $fake->assertInvitationAnswered(catalogEvent()), 'assertNoInvitationAnswered'];
    }],
    'forget attendee' => [static function (): array {
        $event = catalogEvent();
        $user = User::factory()->create();
        Events::for($event)->rsvp($user)->going();

        return [fn () => $user->forceDelete(), fn (EventsFake $fake) => $fake->assertAttendeeForgotten($user), fn (EventsFake $fake) => $fake->assertAttendeeForgotten(User::factory()->create()), 'assertNoAttendeeForgotten'];
    }],
    'generic' => [static function (): array {
        $event = catalogEvent();
        $rsvp = Events::for($event)->rsvp(User::factory()->create())->going();

        return [
            fn () => Events::rsvps()->rotateCode($rsvp),
            fn (EventsFake $fake) => $fake->assertPerformed(EventOperation::RotateCode, static fn (RecordedEventOperation $operation): bool => $operation->concerns($event)),
            fn (EventsFake $fake) => $fake->assertPerformed(EventOperation::RotateCode, static fn (): bool => false),
            'assertNotPerformed',
        ];
    }],
]);

test('fake_assertions_cover_every_operation', function (Closure $scenario): void {
    $fake = Events::fake();
    [$act, $positive, $mismatch, $negative] = $scenario();
    $assertNone = static fn () => $negative === 'assertNotPerformed' ? $fake->assertNotPerformed(EventOperation::RotateCode) : $fake->{$negative}();

    // Before: the negative passes, the positive fails.
    $assertNone();
    expect(fn () => $positive($fake))->toThrow(ExpectationFailedException::class);

    $act();

    // After: the positive passes, a filter that does not match fails, the negative fails.
    $positive($fake);
    expect(fn () => $mismatch($fake))->toThrow(ExpectationFailedException::class, 'none matched')
        ->and($assertNone)->toThrow(ExpectationFailedException::class);
})->with('operations');

test('the_attendee_deletion_hook_is_recorded', function (): void {
    $fake = Events::fake();
    $event = catalogEvent();
    $user = User::factory()->create();
    Events::for($event)->rsvp($user)->going();

    // A soft delete keeps the RSVPs: nothing to forget yet.
    $user->delete();
    $fake->assertNoAttendeeForgotten();

    // Through the model trait: it calls the manager, so the fake sees it.
    $user->forceDelete();
    $fake->assertAttendeeForgotten($user);
    Events::assertAttendeeForgotten();

    expect($fake->recorded(EventOperation::ForgetAttendee)[0]->result)->toBe(1)
        ->and($event->fresh()?->going_count)->toBe(0);
});
