<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Event as EventBus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Events\Enums\InvitationStatus;
use RoundlyConsulting\Events\Enums\RsvpResponse;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Events\RsvpChanged;
use RoundlyConsulting\Events\Exceptions\InvalidInvitationException;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\EventInvitation;
use RoundlyConsulting\Events\Notifications\EventInvitationNotification;
use RoundlyConsulting\Events\Support\EventVisibility;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\Scenarios;

beforeEach(fn () => Scenarios::freeze());
afterEach(fn () => Scenarios::thaw());

function townHall(bool $publish = true): Event
{
    $builder = Events::make('Town hall')->organizedBy(User::factory()->create())->private()
        ->startsAt('2027-02-10 20:00')->timezone('Europe/Bratislava');

    return $publish ? $builder->publish() : $builder->create();
}

test('invitation_links_follow_a_rescheduled_start', function (): void {
    $event = townHall();
    $invitee = User::factory()->create();
    $token = (string) Events::for($event)->invitations()->send($invitee)->plainToken;

    Events::for($event)->reschedule('2027-03-10 20:00');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-02-20 12:00:00', 'UTC'));

    $invitation = Events::invitations()->find($token);
    expect($invitation)->not->toBeNull()
        ->and($invitation?->expiresAt()?->equalTo($event->fresh()?->starts_at))->toBeTrue()
        ->and(Events::invitations()->accept($token, by: $invitee)->status)->toBe(RsvpStatus::Going);
});

test('invitation_links_stay_valid_while_postponed_and_die_at_the_start', function (): void {
    $event = townHall();
    $invitee = User::factory()->create();
    $other = User::factory()->create();
    $token = (string) Events::for($event)->invitations()->send($invitee)->plainToken;
    $late = (string) Events::for($event)->invitations()->send($other)->plainToken;

    Events::for($event)->postpone('Storm');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-02-20 12:00:00', 'UTC'));

    // The date is open: the old start no longer ends the link.
    expect(Events::invitations()->find($token)?->expiresAt())->toBeNull()
        ->and(Events::invitations()->accept($token, RsvpResponse::Interested, $invitee)->status)->toBe(RsvpStatus::Interested);

    Events::for($event)->reschedule('2027-03-10 20:00');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-03-10 19:00:00', 'UTC'));

    expect(fn () => Events::invitations()->accept($late, by: $other))->toThrow(InvalidInvitationException::class)
        ->and(Events::invitations()->find($late))->toBeNull();
});

test('invitations_sent_on_a_draft_reach_the_invitee_on_publish', function (): void {
    Notification::fake();
    $draft = townHall(publish: false);
    $guest = User::factory()->create();
    $accepted = User::factory()->create();

    $first = Events::for($draft)->invitations()->send($guest, message: 'Board only');
    Events::for($draft)->invitations()->send($accepted);
    EventInvitation::query()->where('invitee_id', $accepted->getKey())->update(['status' => InvitationStatus::Accepted->value, 'token_hash' => null]);

    // Nobody can open a draft: nothing is sent yet.
    Notification::assertNothingSent();

    Events::for($draft)->publish();

    Notification::assertSentTo($guest, EventInvitationNotification::class, static fn (EventInvitationNotification $notice): bool => $notice->data['message'] === 'Board only'
        && is_string($notice->data['token']) && $notice->data['token'] !== $first->plainToken);
    Notification::assertNotSentTo($accepted, EventInvitationNotification::class);

    // The link from the publish notice works; the draft's token was replaced.
    $sent = Notification::sent($guest, EventInvitationNotification::class)->first();
    expect(fn () => Events::invitations()->accept((string) $first->plainToken, by: $guest))->toThrow(InvalidInvitationException::class)
        ->and(Events::invitations()->accept((string) $sent?->data['token'], by: $guest)->status)->toBe(RsvpStatus::Going);
});

test('a_revocation_racing_an_answer_stays_revoked', function (): void {
    $event = townHall();
    $invitee = User::factory()->create();
    $token = (string) Events::for($event)->invitations()->send($invitee)->plainToken;

    // The organizer's revoke lands right after the answer's RSVP commits.
    $revoked = false;
    EventBus::listen(RsvpChanged::class, static function () use (&$revoked, $event, $invitee): void {
        if (! $revoked) {
            $revoked = true;
            Events::for($event)->invitations()->revoke($invitee);
        }
    });

    Events::invitations()->accept($token, RsvpResponse::Interested, $invitee);

    expect(EventInvitation::query()->where('event_id', $event->getKey())->first()?->status)->toBe(InvitationStatus::Revoked)
        ->and(EventVisibility::canView($event->fresh() ?? $event, $invitee))->toBeFalse();
});

test('an_answer_to_a_link_revoked_meanwhile_is_refused', function (): void {
    $event = Scenarios::event(capacity: 10);
    $invitee = User::factory()->create();
    $token = (string) Events::for($event)->invitations()->send($invitee)->plainToken;

    // Revoked right after the link was read, before the answer is written.
    $once = false;
    EventBus::listen('eloquent.retrieved: '.EventInvitation::class, static function () use (&$once, $event, $invitee): void {
        if (! $once) {
            $once = true;
            Events::for($event)->invitations()->revoke($invitee);
        }
    });

    expect(fn () => Events::invitations()->accept($token, RsvpResponse::NotGoing, $invitee))->toThrow(InvalidInvitationException::class)
        ->and($invitee->rsvpFor($event))->toBeNull();
});

test('queued_invitation_notices_are_encrypted', function (): void {
    Queue::fake();
    $event = Scenarios::event(capacity: 10);
    $invitee = User::factory()->create();

    $token = (string) Events::for($event)->invitations()->send($invitee)->plainToken;
    $jobs = Queue::pushed(SendQueuedNotifications::class);

    expect($jobs)->toHaveCount(1)
        ->and($jobs->first()->shouldBeEncrypted)->toBeTrue()
        ->and($token)->not->toBe('');
});

test('resending_an_accepted_invitation_mails_the_new_link', function (): void {
    Notification::fake();
    $event = townHall();
    $invitee = User::factory()->create();
    $first = Events::for($event)->invitations()->send($invitee);
    Events::invitations()->accept((string) $first->plainToken, by: $invitee);

    // Sent again (the invitee lost the link): a new token, and the notice that carries it.
    $again = Events::for($event)->invitations()->send($invitee);

    expect($again->status)->toBe(InvitationStatus::Accepted)
        ->and(Events::invitations()->find((string) $again->plainToken))->not->toBeNull();
    Notification::assertSentToTimes($invitee, EventInvitationNotification::class, 2);
    Notification::assertSentTo($invitee, EventInvitationNotification::class, static fn (EventInvitationNotification $notice): bool => $notice->data['token'] === $again->plainToken);

    // A notice whose link died before delivery (sent again meanwhile) is dropped: no dead link.
    $stale = new EventInvitationNotification($event, data: ['token' => (string) $first->plainToken, 'message' => null]);
    expect($stale->shouldSend($invitee, 'mail'))->toBeFalse()
        ->and((new EventInvitationNotification($event, data: ['token' => (string) $again->plainToken, 'message' => null]))->shouldSend($invitee, 'mail'))->toBeTrue();

    // A notice with no link (a host's own) keeps the old rule: pending invitations only.
    $pending = User::factory()->create();
    Events::for($event)->invitations()->send($pending);
    expect((new EventInvitationNotification($event))->shouldSend($pending, 'mail'))->toBeTrue()
        ->and((new EventInvitationNotification($event))->shouldSend($invitee, 'mail'))->toBeFalse();
});
