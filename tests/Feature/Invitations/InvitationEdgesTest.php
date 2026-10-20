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
