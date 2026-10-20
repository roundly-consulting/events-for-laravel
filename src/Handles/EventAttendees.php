<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Handles;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Actions\CheckIns\CheckInAttendeeAction;
use RoundlyConsulting\Events\Actions\CheckIns\UndoCheckInAction;
use RoundlyConsulting\Events\Actions\Rsvps\AddAttendeeAction;
use RoundlyConsulting\Events\Actions\Rsvps\ChangeGuestsAction;
use RoundlyConsulting\Events\Actions\Rsvps\ChangeTierAction;
use RoundlyConsulting\Events\Actions\Rsvps\ConfirmRsvpAction;
use RoundlyConsulting\Events\Actions\Rsvps\ReleaseHoldAction;
use RoundlyConsulting\Events\Actions\Rsvps\RemoveAttendeeAction;
use RoundlyConsulting\Events\DataTransferObjects\CheckInResult;
use RoundlyConsulting\Events\Enums\EventOperation;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Models\AdmissionTier;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Support\EventModels;

/**
 * `Events::for($event)->attendees()` — the managers' side of the list. Writes check the
 * `manageAttendees` ability when the handle names an actor. An RSVP of another event is never
 * found.
 */
final readonly class EventAttendees
{
    public function __construct(
        private EventHandle $handle,
    ) {}

    /**
     * Put someone on the list: going at once on any ticket type (a comp seat), over capacity only
     * with `overCapacity: true`.
     */
    public function add(Model $attendee, AdmissionTier|int|string|null $tier = null, int $guests = 0, bool $overCapacity = false): Rsvp
    {
        $this->handle->authorize('manageAttendees');

        return $this->handle->manager()->perform(
            EventOperation::AddAttendee,
            AddAttendeeAction::class,
            fn (AddAttendeeAction $action): Rsvp => $action->execute($this->handle->event(), $attendee, $tier, $guests, $overCapacity, $this->handle->actor()),
            $this->handle->context(['attendee' => $attendee, 'tier' => $tier, 'guests' => $guests]),
        );
    }

    /**
     * Take someone off the list (checked-in or not); their seats go to the waitlist.
     */
    public function remove(Rsvp|Model $who): self
    {
        $this->handle->authorize('manageAttendees');
        $this->handle->manager()->perform(
            EventOperation::RemoveAttendee,
            RemoveAttendeeAction::class,
            fn (RemoveAttendeeAction $action): Rsvp => $action->execute($this->handle->event(), $who, $this->handle->actor()),
            $this->handle->context(['attendee' => $who]),
        );

        return $this;
    }

    public function changeTier(Rsvp|Model $who, AdmissionTier|int|string $tier): Rsvp
    {
        $this->handle->authorize('manageAttendees');

        return $this->handle->manager()->perform(
            EventOperation::ChangeTier,
            ChangeTierAction::class,
            fn (ChangeTierAction $action): Rsvp => $action->execute($this->handle->event(), $who, $tier, $this->handle->actor()),
            $this->handle->context(['attendee' => $who, 'tier' => $tier]),
        );
    }

    public function changeGuests(Rsvp|Model $who, int $guests): Rsvp
    {
        $this->handle->authorize('manageAttendees');

        return $this->handle->manager()->perform(
            EventOperation::ChangeGuests,
            ChangeGuestsAction::class,
            fn (ChangeGuestsAction $action): Rsvp => $action->execute($this->handle->event(), $who, $guests, $this->handle->actor()),
            $this->handle->context(['attendee' => $who, 'guests' => $guests]),
        );
    }

    /**
     * Confirm a held RSVP by hand. With the payment reference it counts as paid; without one it
     * was paid another way (cash at the door) and the hold's online checkout is dropped.
     */
    public function confirm(Rsvp|Model $who, ?string $reference = null): Rsvp
    {
        $this->handle->authorize('manageAttendees');

        return $this->handle->manager()->perform(
            EventOperation::ConfirmRsvp,
            ConfirmRsvpAction::class,
            fn (ConfirmRsvpAction $action): Rsvp => $action->execute($this->handle->event(), $who, $reference, $this->handle->actor()),
            $this->handle->context(['attendee' => $who, 'reference' => $reference]),
        );
    }

    /**
     * Release a hold now (the payment failed); the waitlist moves up.
     */
    public function release(Rsvp|Model $who): Rsvp
    {
        $this->handle->authorize('manageAttendees');

        return $this->handle->manager()->perform(
            EventOperation::ReleaseHold,
            ReleaseHoldAction::class,
            fn (ReleaseHoldAction $action): Rsvp => $action->execute($this->handle->event(), $who, $this->handle->actor()),
            $this->handle->context(['attendee' => $who]),
        );
    }

    /**
     * Check someone in by hand — no code, any time. They must be going.
     */
    public function checkIn(Rsvp|Model $who, ?int $guests = null): CheckInResult
    {
        $this->handle->authorize('checkIn');

        return $this->handle->manager()->perform(
            EventOperation::ManualCheckIn,
            CheckInAttendeeAction::class,
            fn (CheckInAttendeeAction $action): CheckInResult => $action->execute($this->handle->event(), $who, $guests, $this->handle->actor()),
            $this->handle->context(['attendee' => $who, 'guests' => $guests]),
        );
    }

    public function undoCheckIn(Rsvp|Model $who): self
    {
        $this->handle->authorize('checkIn');
        $this->handle->manager()->perform(
            EventOperation::UndoCheckIn,
            UndoCheckInAction::class,
            fn (UndoCheckInAction $action): Rsvp => $action->execute($this->handle->event(), $who, $this->handle->actor()),
            $this->handle->context(['attendee' => $who]),
        );

        return $this;
    }

    /**
     * Who is through the door (`checkIn` with an actor): going RSVPs with a check-in.
     *
     * @return Collection<int, Rsvp>
     */
    public function checkedIn(): Collection
    {
        $this->handle->authorize('checkIn');

        return EventModels::rsvp()::query()
            ->where('event_id', $this->handle->event()->getKey())
            ->where('status', RsvpStatus::Going->value)
            ->whereNotNull('checked_in_at')
            ->with('attendee')
            ->orderBy('checked_in_at')
            ->get();
    }

    /**
     * The attendee's RSVP, or null — an actor reads their own, or any with `manageAttendees`.
     */
    public function find(Model $attendee): ?Rsvp
    {
        $actor = $this->handle->actor();

        if ($actor === null || ! $actor->is($attendee)) {
            $this->handle->authorize('manageAttendees');
        }

        return EventModels::rsvp()::query()
            ->where('event_id', $this->handle->event()->getKey())
            ->where('attendee_type', $attendee->getMorphClass())
            ->where('attendee_id', $attendee->getKey())
            ->first();
    }

    /**
     * The guest list (`viewAttendees` with an actor: the event's `guest_list` decides).
     *
     * @return Collection<int, Rsvp>
     */
    public function going(): Collection
    {
        $this->handle->authorize('viewAttendees');

        return $this->withStatus(RsvpStatus::Going);
    }

    /**
     * Interested people (`viewInterested` with an actor: managers).
     *
     * @return Collection<int, Rsvp>
     */
    public function interested(): Collection
    {
        $this->handle->authorize('viewInterested');

        return $this->withStatus(RsvpStatus::Interested);
    }

    /**
     * @return Collection<int, Rsvp>
     */
    private function withStatus(RsvpStatus $status): Collection
    {
        return EventModels::rsvp()::query()
            ->where('event_id', $this->handle->event()->getKey())
            ->where('status', $status->value)
            ->with('attendee')
            ->orderBy('id')
            ->get();
    }
}
