<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Support\EventManagers;
use RoundlyConsulting\Events\Support\EventModels;

/**
 * Who is asking, and what that means for each event a response renders. A list precomputes the
 * events the viewer manages in one query and eager-loads the viewer's own RSVPs, so role-aware
 * fields cost no query per row.
 *
 * @internal
 */
final class Viewer
{
    /**
     * @param  array<array-key, true>|null  $managed  the managed event keys of a list, or null (ask per event)
     */
    public function __construct(
        public readonly ?Model $user,
        private ?array $managed = null,
        public readonly bool $show = false,
    ) {}

    public static function of(Request $request, bool $show = false): self
    {
        $user = $request->user();

        return new self($user instanceof Model ? $user : null, null, $show);
    }

    /**
     * Precompute, in one query, which of these events the viewer manages.
     *
     * @param  iterable<Event>  $events
     */
    public function forList(iterable $events): self
    {
        $keys = [];

        foreach ($events as $event) {
            $keys[] = $event->getKey();
        }

        $managed = [];

        if ($this->user !== null && $keys !== []) {
            foreach (EventModels::event()::query()->managedBy($this->user)->whereKey($keys)->pluck('id') as $key) {
                $managed[$key] = true;
            }
        }

        return new self($this->user, $managed, false);
    }

    public function manages(Event $event): bool
    {
        if ($this->user === null) {
            return false;
        }

        return $this->managed !== null ? isset($this->managed[$event->getKey()]) : EventManagers::allows($event, $this->user);
    }

    /**
     * The viewer's own RSVP: from the constrained `rsvps` load of a list, else one query.
     */
    public function rsvp(Event $event): ?Rsvp
    {
        if ($this->user === null) {
            return null;
        }

        $mine = fn (Rsvp $rsvp): bool => $rsvp->attendee_type === $this->user->getMorphClass() && (string) $rsvp->attendee_id === (string) $this->user->getKey();

        if ($event->relationLoaded('rsvps')) {
            return $event->rsvps->first($mine);
        }

        return $event->rsvps()
            ->where('attendee_type', $this->user->getMorphClass())
            ->where('attendee_id', $this->user->getKey())
            ->first();
    }

    public function isGoing(Event $event): bool
    {
        return $this->rsvp($event)?->status === RsvpStatus::Going;
    }
}
