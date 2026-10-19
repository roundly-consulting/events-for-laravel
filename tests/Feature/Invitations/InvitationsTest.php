<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event as EventBus;
use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\Enums\InvitationStatus;
use RoundlyConsulting\Events\Enums\RsvpResponse;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Enums\Visibility;
use RoundlyConsulting\Events\Events\InvitationAccepted;
use RoundlyConsulting\Events\Events\InvitationDeclined;
use RoundlyConsulting\Events\Events\InvitationRevoked;
use RoundlyConsulting\Events\Events\InvitationSent;
use RoundlyConsulting\Events\Exceptions\InvalidEventException;
use RoundlyConsulting\Events\Exceptions\InvalidInvitationException;
use RoundlyConsulting\Events\Exceptions\InvitationRequiredException;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\EventInvitation;
use RoundlyConsulting\Events\Tests\Fixtures\Models\Guest;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\Scenarios;

beforeEach(fn () => Scenarios::freeze());
afterEach(fn () => Scenarios::thaw());

function privateEvent(?User $organizer = null): Event
{
    $event = Scenarios::event(capacity: 10, organizer: $organizer);
    Events::for($event)->update(new UpdateEventData(visibility: Visibility::Private));

    return $event;
}

it('invites, and the invitee accepts from the link', function (): void {
    EventBus::fake([InvitationSent::class, InvitationAccepted::class]);
    $organizer = User::factory()->create();
    $event = privateEvent($organizer);
    $ceo = User::factory()->create();

    $invitation = Events::for($event)->by($organizer)->invitations()->send($ceo, message: 'Hope you can join');

    expect($invitation->status)->toBe(InvitationStatus::Pending)
        ->and($invitation->plainToken)->toMatch('/^INV1-[A-Z2-7]{32}$/')
        ->and($invitation->token_hash)->toHaveLength(64)
        ->and($invitation->expires_at?->equalTo($event->starts_at))->toBeTrue()
        ->and($invitation->invited_by_id)->toBe($organizer->getKey())
        ->and($invitation->toArray())->not->toHaveKey('token_hash')
        ->and(Events::for($event)->invitations()->pending()->count())->toBe(1);
    EventBus::assertDispatched(InvitationSent::class, static fn (InvitationSent $sent): bool => $sent->token === $invitation->plainToken && $sent->message === 'Hope you can join');

    expect(Events::invitations()->find((string) $invitation->plainToken)?->is($invitation))->toBeTrue();

    $rsvp = Events::invitations()->accept((string) $invitation->plainToken, by: $ceo);

    expect($rsvp->status)->toBe(RsvpStatus::Going)
        ->and($rsvp->attendee_id)->toBe($ceo->getKey())
        ->and($invitation->fresh()?->status)->toBe(InvitationStatus::Accepted)
        ->and($invitation->fresh()?->token_hash)->toBeNull();
    EventBus::assertDispatched(InvitationAccepted::class);

    expect(fn () => Events::for($event)->invitations()->send(User::factory()->create(), str_repeat('x', 1001)))->toThrow(InvalidEventException::class);
});

test('invitation_tokens_are_bearer_secrets', function (): void {
    $event = privateEvent();
    $invitee = Guest::factory()->create();
    $token = (string) Events::for($event)->invitations()->send($invitee)->plainToken;

    // The invitee is the invitation's own model, whoever presents the link (a guest model is not
    // an account: the link is the credential).
    $rsvp = Events::invitations()->accept($token, RsvpResponse::Interested, by: User::factory()->create());
    expect($rsvp->attendee_type)->toBe($invitee->getMorphClass())
        ->and($rsvp->status)->toBe(RsvpStatus::Interested);

    $messages = [];
    $codes = [];
    $revokedToken = (string) Events::for($event)->invitations()->send($revoked = User::factory()->create())->plainToken;
    Events::for($event)->invitations()->revoke($revoked);
    $expiredToken = (string) Events::for($event)->invitations()->send(User::factory()->create())->plainToken;

    // Used, revoked, expired, unknown and malformed tokens all read the same.
    foreach ([$token, $revokedToken, 'INV1-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'nonsense'] as $bad) {
        try {
            Events::invitations()->accept($bad);
        } catch (InvalidInvitationException $exception) {
            $messages[] = $exception->getMessage();
            $codes[] = $exception->status().$exception->errorCode();
        }
    }

    Scenarios::freeze($event->starts_at->addMinute()->format('Y-m-d H:i:s'));
    try {
        Events::invitations()->decline($expiredToken);
    } catch (InvalidInvitationException $exception) {
        $messages[] = $exception->getMessage();
        $codes[] = $exception->status().$exception->errorCode();
    }

    expect($messages)->toHaveCount(5)
        ->and(array_unique($messages))->toBe(['The invitation is not valid.'])
        ->and(array_unique($codes))->toBe(['404invalid_invitation'])
        ->and(Events::invitations()->find('nonsense'))->toBeNull();
});

test('invitation_tokens_rotate_on_resend', function (): void {
    $event = privateEvent();
    $invitee = User::factory()->create();

    $first = (string) Events::for($event)->invitations()->send($invitee)->plainToken;
    $second = (string) Events::for($event)->invitations()->send($invitee)->plainToken;

    expect($second)->not->toBe($first)
        ->and(EventInvitation::query()->count())->toBe(1)
        ->and(fn () => Events::invitations()->accept($first, by: $invitee))->toThrow(InvalidInvitationException::class)
        ->and(Events::invitations()->accept($second, by: $invitee)->status)->toBe(RsvpStatus::Going);

    // A revoked invitation sent again is pending again.
    $other = User::factory()->create();
    Events::for($event)->invitations()->send($other);
    Events::for($event)->invitations()->revoke($other);
    expect(Events::for($event)->invitations()->send($other)->status)->toBe(InvitationStatus::Pending);
});

test('invitations_cannot_be_accepted_for_someone_else', function (): void {
    $event = privateEvent();
    $invitee = User::factory()->create();
    $token = (string) Events::for($event)->invitations()->send($invitee)->plainToken;

    expect(fn () => Events::invitations()->accept($token, by: User::factory()->create()))->toThrow(InvalidInvitationException::class)
        ->and(fn () => Events::invitations()->decline($token, by: User::factory()->create()))->toThrow(InvalidInvitationException::class)
        // The token still works for its invitee.
        ->and(Events::invitations()->accept($token, by: $invitee)->status)->toBe(RsvpStatus::Going);
});

it('declines and revokes', function (): void {
    EventBus::fake([InvitationDeclined::class, InvitationRevoked::class]);
    $event = privateEvent();
    $declines = User::factory()->create();
    $waits = User::factory()->create();

    $token = (string) Events::for($event)->invitations()->send($declines)->plainToken;
    $invitation = Events::invitations()->decline($token, by: $declines);

    expect($invitation->status)->toBe(InvitationStatus::Declined)
        ->and($declines->rsvpFor($event)?->status)->toBe(RsvpStatus::NotGoing);
    EventBus::assertDispatched(InvitationDeclined::class);

    // Revoking takes a not-yet-going invitee's place on a private event.
    Events::for($event)->invitations()->send($waits);
    Events::for($event)->rsvp($waits)->interested();
    Events::for($event)->invitations()->revoke($waits);

    expect($waits->rsvpFor($event)?->status)->toBe(RsvpStatus::NotGoing)
        ->and(fn () => Events::for($event)->rsvp($waits)->going())->toThrow(InvitationRequiredException::class)
        ->and(Events::for($event)->invitations()->all()->count())->toBe(2);
    EventBus::assertDispatched(InvitationRevoked::class);

    // A going invitee stays.
    $going = User::factory()->create();
    Events::for($event)->invitations()->send($going);
    Events::for($event)->rsvp($going)->going();
    Events::for($event)->invitations()->revoke($going);
    expect($going->rsvpFor($event)?->status)->toBe(RsvpStatus::Going);
});
