<?php

declare(strict_types=1);

use RoundlyConsulting\Events\DataTransferObjects\UpdateTierData;
use RoundlyConsulting\Events\Exceptions\CheckInRefusedException;
use RoundlyConsulting\Events\Exceptions\InvalidInvitationException;
use RoundlyConsulting\Events\Exceptions\RsvpNotFoundException;
use RoundlyConsulting\Events\Exceptions\TierNotFoundException;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\Scenarios;

beforeEach(fn () => Scenarios::freeze());
afterEach(fn () => Scenarios::thaw());

test('handles_refuse_other_events_children', function (): void {
    $mine = Scenarios::event(capacity: 5);
    $theirs = Scenarios::event(capacity: 5);
    $theirRsvp = Events::for($theirs)->rsvp(User::factory()->create())->going();
    $theirTier = Events::for($theirs)->tiers()->add('Theirs');
    Events::for($theirs)->invitations()->send($invitee = User::factory()->create());
    Scenarios::minutesBeforeStart($mine, 30);

    $handle = Events::for($mine);

    expect(fn () => $handle->attendees()->remove($theirRsvp))->toThrow(RsvpNotFoundException::class)
        ->and(fn () => $handle->attendees()->checkIn($theirRsvp))->toThrow(RsvpNotFoundException::class)
        ->and(fn () => $handle->attendees()->confirm($theirRsvp))->toThrow(RsvpNotFoundException::class)
        ->and(fn () => $handle->attendees()->changeTier($theirRsvp, $theirTier))->toThrow(RsvpNotFoundException::class)
        ->and(fn () => $handle->attendees()->changeGuests($theirRsvp, 1))->toThrow(RsvpNotFoundException::class)
        ->and(fn () => $handle->attendees()->release($theirRsvp))->toThrow(RsvpNotFoundException::class)
        ->and(fn () => $handle->attendees()->undoCheckIn($theirRsvp))->toThrow(RsvpNotFoundException::class)
        ->and(fn () => $handle->tiers()->update($theirTier, new UpdateTierData(capacity: 1)))->toThrow(TierNotFoundException::class)
        ->and(fn () => $handle->tiers()->remove($theirTier))->toThrow(TierNotFoundException::class)
        ->and(fn () => $handle->invitations()->revoke($invitee))->toThrow(InvalidInvitationException::class)
        ->and(fn () => $handle->checkIn((string) $theirRsvp->checkInCode()))->toThrow(CheckInRefusedException::class, 'This code is for another event.');

    // Nothing of theirs changed.
    expect($theirRsvp->fresh()?->isGoing())->toBeTrue()
        ->and($theirRsvp->fresh()?->isCheckedIn())->toBeFalse();
});
