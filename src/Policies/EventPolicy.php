<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Policies;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Enums\GuestList;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Enums\Visibility;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Support\EventManagers;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventsConfig;
use RoundlyConsulting\Events\Support\EventVisibility;

/**
 * Who views and manages events. Managers (the organizer's approved users, co-hosts' approved
 * users, the admin ability) run every management ability; co-hosts and transfers need the
 * organizer itself. Swap it with `events.authorization.policy` (extend this class).
 *
 * Not final on purpose: a host policy extends it to change one ability.
 */
class EventPolicy
{
    public function viewAny(?Model $user): bool
    {
        return true;
    }

    public function view(?Model $user, Event $event): bool
    {
        return EventVisibility::canView($event, $user);
    }

    public function create(?Model $user): bool
    {
        if ($user === null) {
            return false;
        }

        $ability = EventsConfig::createAbility();

        return $ability === null
            || ($user instanceof Authenticatable && App::make(Gate::class)->forUser($user)->allows($ability));
    }

    public function update(?Model $user, Event $event): bool
    {
        return $this->manages($user, $event);
    }

    public function delete(?Model $user, Event $event): bool
    {
        return $this->manages($user, $event);
    }

    public function restore(?Model $user, Event $event): bool
    {
        return $this->manages($user, $event);
    }

    public function publish(?Model $user, Event $event): bool
    {
        return $this->manages($user, $event);
    }

    public function unpublish(?Model $user, Event $event): bool
    {
        return $this->manages($user, $event);
    }

    public function postpone(?Model $user, Event $event): bool
    {
        return $this->manages($user, $event);
    }

    public function reschedule(?Model $user, Event $event): bool
    {
        return $this->manages($user, $event);
    }

    public function cancel(?Model $user, Event $event): bool
    {
        return $this->manages($user, $event);
    }

    public function complete(?Model $user, Event $event): bool
    {
        return $this->manages($user, $event);
    }

    public function transition(?Model $user, Event $event): bool
    {
        return $this->manages($user, $event);
    }

    public function moveOnline(?Model $user, Event $event): bool
    {
        return $this->manages($user, $event);
    }

    public function manageTiers(?Model $user, Event $event): bool
    {
        return $this->manages($user, $event);
    }

    public function invite(?Model $user, Event $event): bool
    {
        return $this->manages($user, $event);
    }

    public function manageAttendees(?Model $user, Event $event): bool
    {
        return $this->manages($user, $event);
    }

    public function checkIn(?Model $user, Event $event): bool
    {
        return $this->manages($user, $event);
    }

    public function viewWaitlist(?Model $user, Event $event): bool
    {
        return $this->manages($user, $event);
    }

    /**
     * Co-hosts manage the event, never its co-host list: only the organizer's approved users
     * (and admins) add or remove co-hosts.
     */
    public function manageHosts(?Model $user, Event $event): bool
    {
        return EventManagers::organizerAllows($event, $user);
    }

    public function transfer(?Model $user, Event $event): bool
    {
        return EventManagers::organizerAllows($event, $user);
    }

    /**
     * Whether the user may answer at all: they can see it, it is announced and not over, and a
     * private event admits them. The RSVP rules (window, start, capacity) apply on top.
     */
    public function rsvp(?Model $user, Event $event): bool
    {
        if ($user === null || ! $this->view($user, $event)) {
            return false;
        }

        $phase = $event->phase;

        if ($phase === EventPhase::Draft) {
            return $this->manages($user, $event);
        }

        if ($phase->isTerminal()) {
            return false;
        }

        return $event->visibility !== Visibility::Private
            || $this->manages($user, $event)
            || EventVisibility::isInvitedOrAttending($event, $user);
    }

    /**
     * Managers always; going attendees when the guest list is shown to attendees; any viewer when
     * it is public.
     */
    public function viewAttendees(?Model $user, Event $event): bool
    {
        if ($this->manages($user, $event)) {
            return true;
        }

        return match ($event->guest_list) {
            GuestList::Public => $this->view($user, $event),
            GuestList::Attendees => $this->isGoing($user, $event),
            GuestList::Hidden => false,
        };
    }

    /**
     * Interest is a softer signal: managers only.
     */
    public function viewInterested(?Model $user, Event $event): bool
    {
        return $this->manages($user, $event);
    }

    public function viewCounts(?Model $user, Event $event): bool
    {
        return $this->manages($user, $event) || ($event->show_counts && $this->view($user, $event));
    }

    /**
     * The join link: managers and going attendees.
     */
    public function viewOnlineUrl(?Model $user, Event $event): bool
    {
        return $this->manages($user, $event) || $this->isGoing($user, $event);
    }

    protected function manages(?Model $user, Event $event): bool
    {
        return EventManagers::allows($event, $user);
    }

    protected function isGoing(?Model $user, Event $event): bool
    {
        return $user !== null && EventModels::rsvp()::query()
            ->where('event_id', $event->getKey())
            ->where('attendee_type', $user->getMorphClass())
            ->where('attendee_id', $user->getKey())
            ->where('status', RsvpStatus::Going->value)
            ->exists();
    }
}
