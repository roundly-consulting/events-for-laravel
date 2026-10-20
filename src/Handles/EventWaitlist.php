<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Handles;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Actions\Rsvps\PromoteWaitlistAction;
use RoundlyConsulting\Events\Enums\EventOperation;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Support\EventModels;

/**
 * `Events::for($event)->waitlist()` — the queue, in order (`waitlisted_at`, then id).
 */
final readonly class EventWaitlist
{
    public function __construct(
        private EventHandle $handle,
    ) {}

    /**
     * @return Collection<int, Rsvp>
     */
    public function all(): Collection
    {
        $this->handle->authorize('viewWaitlist');

        return EventModels::rsvp()::query()
            ->where('event_id', $this->handle->event()->getKey())
            ->where('status', RsvpStatus::Waitlisted->value)
            ->orderBy('waitlisted_at')
            ->orderBy('id')
            ->with('attendee')
            ->get();
    }

    /**
     * Move the queue up into free seats by hand: first fit in order, at most `$limit` — after the
     * automatic cutoff, or with automatic promotion off.
     *
     * @return Collection<int, Rsvp>
     */
    public function promote(?int $limit = null): Collection
    {
        $this->handle->authorize('manageAttendees');

        return $this->handle->manager()->perform(
            EventOperation::PromoteWaitlist,
            PromoteWaitlistAction::class,
            fn (PromoteWaitlistAction $action): Collection => $action->execute($this->handle->event(), $limit),
            $this->handle->context(['limit' => $limit]),
        );
    }

    /**
     * The attendee's 1-based place in the queue, or null — an actor reads their own, or any with
     * `viewWaitlist`.
     */
    public function position(Model $attendee): ?int
    {
        $actor = $this->handle->actor();

        if ($actor === null || ! $actor->is($attendee)) {
            $this->handle->authorize('viewWaitlist');
        }

        return EventModels::rsvp()::query()
            ->where('event_id', $this->handle->event()->getKey())
            ->where('attendee_type', $attendee->getMorphClass())
            ->where('attendee_id', $attendee->getKey())
            ->first()?->waitlistPosition();
    }
}
