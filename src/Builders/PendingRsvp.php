<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Builders;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Actions\Rsvps\RespondToEventAction;
use RoundlyConsulting\Events\DataTransferObjects\RsvpRequest;
use RoundlyConsulting\Events\Enums\EventOperation;
use RoundlyConsulting\Events\Enums\RsvpResponse;
use RoundlyConsulting\Events\Handles\EventHandle;
use RoundlyConsulting\Events\Models\AdmissionTier;
use RoundlyConsulting\Events\Models\Rsvp;

/**
 * `Events::for($event)->rsvp($attendee)` (or `$user->rsvp($event)`) — one attendee's answer.
 * Each verb returns the RSVP: `going()` yields going, held (a paid ticket type: `payment_url`,
 * `held_until`) or waitlisted (`waitlistPosition()`) — match on `$rsvp->status`.
 *
 * With an actor, the policy is checked: the attendee answering for themselves needs `rsvp`; a
 * manager answering for someone else needs `manageAttendees`.
 */
final class PendingRsvp
{
    public function __construct(
        private readonly EventHandle $handle,
        private readonly Model $attendee,
        private ?Model $actor = null,
    ) {
        $this->actor ??= $handle->actor();
    }

    public function by(?Model $actor): self
    {
        $this->actor = $actor;

        return $this;
    }

    /**
     * Going — on a ticket type (a model, id or key; omitted: the single one on sale), with
     * guests (the party is 1 + guests).
     */
    public function going(AdmissionTier|int|string|null $tier = null, int $guests = 0, ?string $note = null): Rsvp
    {
        return $this->respond(new RsvpRequest($this->attendee, RsvpResponse::Going, $tier, $guests, $note, $this->actor));
    }

    public function interested(): Rsvp
    {
        return $this->respond(new RsvpRequest($this->attendee, RsvpResponse::Interested, actor: $this->actor));
    }

    /**
     * Not going: frees any seats; the waitlist moves up in the same transaction.
     */
    public function notGoing(): Rsvp
    {
        return $this->respond(new RsvpRequest($this->attendee, RsvpResponse::NotGoing, actor: $this->actor));
    }

    private function respond(RsvpRequest $request): Rsvp
    {
        if ($this->actor !== null) {
            $self = $this->actor->is($this->attendee);
            $this->handle->by($this->actor)->authorize($self ? 'rsvp' : 'manageAttendees');
        }

        $event = $this->handle->event();

        return $this->handle->manager()->perform(
            EventOperation::Rsvp,
            RespondToEventAction::class,
            static fn (RespondToEventAction $action): Rsvp => $action->execute($event, $request),
            [...$this->handle->context(), 'actor' => $this->actor, 'attendee' => $this->attendee, 'data' => $request],
        );
    }
}
