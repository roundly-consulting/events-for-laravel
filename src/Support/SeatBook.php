<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Models\AdmissionTier;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;

/**
 * The seats of one event inside one transaction: the event row locked first, then every ticket
 * type row in ascending id (the package-wide lock order: event → ticket types → RSVPs). Seat
 * counts are read from the locked rows, never from a model passed in, and every RSVP change goes
 * through {@see self::move()}, which keeps the denormalised counters exact with deltas on the
 * locked rows (query-builder writes: no model events, no `updated_at` bump).
 *
 * @internal
 */
final class SeatBook
{
    /**
     * @param  array<int, AdmissionTier>  $tiers  live ticket types by id
     */
    private function __construct(
        public readonly Event $event,
        private array $tiers,
    ) {}

    public static function open(Event $event, bool $allowTrashed = false): self
    {
        LockedEvent::lock($event, $allowTrashed);

        $tiers = EventModels::tier()::query()
            ->where('event_id', $event->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $map = [];

        foreach ($tiers as $tier) {
            $map[(int) $tier->getKey()] = $tier;
        }

        return new self($event, $map);
    }

    public function tier(?int $id): ?AdmissionTier
    {
        return $id === null ? null : ($this->tiers[$id] ?? null);
    }

    public function hasTiers(): bool
    {
        return $this->tiers !== [];
    }

    /**
     * @return list<AdmissionTier>
     */
    public function tiers(): array
    {
        return array_values($this->tiers);
    }

    /**
     * Seats left under the event's capacity, or null without one.
     */
    public function eventSeatsLeft(): ?int
    {
        return $this->event->capacity === null ? null : max(0, $this->event->capacity - $this->event->seats_taken);
    }

    /**
     * Seats left for a party of the ticket type: the smaller of the event's and the ticket type's
     * room (null: unlimited).
     */
    public function seatsLeft(?int $tierId): ?int
    {
        $event = $this->eventSeatsLeft();
        $tier = $this->tier($tierId)?->seatsLeft();

        return match (true) {
            $event === null => $tier,
            $tier === null => $event,
            default => min($event, $tier),
        };
    }

    public function fits(int $party, ?int $tierId): bool
    {
        $left = $this->seatsLeft($tierId);

        return $left === null || $party <= $left;
    }

    /**
     * Whether a party fits once the RSVP gives back what it holds now (a party or ticket type
     * change of a going or held RSVP).
     */
    public function fitsReplacing(int $party, ?int $tierId, int $currentParty, ?int $currentTier): bool
    {
        $event = $this->eventSeatsLeft();
        $tier = $this->tier($tierId)?->seatsLeft();

        if ($event !== null && $party > $event + $currentParty) {
            return false;
        }

        return $tier === null || $party <= $tier + ($tierId === $currentTier ? $currentParty : 0);
    }

    /**
     * Whether the event itself (not counting ticket type limits) has room once the RSVP gives back
     * its current seats — tells "event full" from "ticket type sold out".
     */
    public function eventFitsReplacing(int $party, int $currentParty): bool
    {
        $event = $this->eventSeatsLeft();

        return $event === null || $party <= $event + $currentParty;
    }

    public function eventFits(int $party): bool
    {
        $left = $this->eventSeatsLeft();

        return $left === null || $party <= $left;
    }

    /**
     * Whether a waitlisted party of the ticket type would fit the free seats — then a newcomer
     * waits too: nobody jumps the queue.
     */
    public function waitlistWouldFit(?int $tierId, ?int $except = null): bool
    {
        $left = $this->seatsLeft($tierId);

        $query = EventModels::rsvp()::query()
            ->where('event_id', $this->event->getKey())
            ->where('status', RsvpStatus::Waitlisted->value)
            ->when($except !== null, static fn ($q) => $q->whereKeyNot($except));
        $tierId === null ? $query->whereNull('tier_id') : $query->where('tier_id', $tierId);

        if ($left !== null) {
            $query->whereRaw('(1 + guests) <= ?', [$left]);
        }

        return $query->exists();
    }

    /**
     * Writes the RSVP and moves the counters by the difference between what it held before and
     * what it holds now.
     */
    public function move(Rsvp $rsvp, ?RsvpStatus $fromStatus, int $fromParty, ?int $fromTier): void
    {
        $rsvp->save();

        $toStatus = $rsvp->status;
        $toParty = $rsvp->partySize();
        $seatsBefore = $fromStatus !== null && $fromStatus->takesSeats() ? $fromParty : 0;
        $seatsAfter = $toStatus->takesSeats() ? $toParty : 0;

        $delta = array_filter([
            'seats_taken' => $seatsAfter - $seatsBefore,
            'going_count' => ($toStatus === RsvpStatus::Going ? $toParty : 0) - ($fromStatus === RsvpStatus::Going ? $fromParty : 0),
            'interested_count' => ($toStatus === RsvpStatus::Interested ? 1 : 0) - ($fromStatus === RsvpStatus::Interested ? 1 : 0),
            'waitlist_count' => ($toStatus === RsvpStatus::Waitlisted ? $toParty : 0) - ($fromStatus === RsvpStatus::Waitlisted ? $fromParty : 0),
        ]);

        if ($delta !== []) {
            $this->event->newQueryWithoutScopes()->whereKey($this->event->getKey())->toBase()->incrementEach($delta);
            $this->sync($this->event, $delta);
        }

        $toTier = $rsvp->tier_id === null ? null : (int) $rsvp->tier_id;

        if ($fromTier === $toTier) {
            $this->moveTierSeats($toTier, $seatsAfter - $seatsBefore);
        } else {
            $this->moveTierSeats($fromTier, -$seatsBefore);
            $this->moveTierSeats($toTier, $seatsAfter);
        }
    }

    /**
     * Removes an RSVP row, giving back whatever it held.
     */
    public function remove(Rsvp $rsvp): void
    {
        $status = $rsvp->status;
        $party = $rsvp->partySize();
        $tier = $rsvp->tier_id === null ? null : (int) $rsvp->tier_id;

        $rsvp->status = RsvpStatus::NotGoing;
        $this->move($rsvp, $status, $party, $tier);
        $rsvp->delete();
    }

    private function moveTierSeats(?int $tierId, int $seats): void
    {
        $tier = $this->tier($tierId);

        if ($seats === 0 || $tierId === null) {
            return;
        }

        EventModels::tier()::query()->withTrashed()->whereKey($tierId)->toBase()->incrementEach(['seats_taken' => $seats]);

        if ($tier !== null) {
            $this->sync($tier, ['seats_taken' => $seats]);
        }
    }

    /**
     * @param  array<string, int>  $delta
     */
    private function sync(Model $model, array $delta): void
    {
        foreach ($delta as $column => $change) {
            $value = (int) $model->getAttribute($column) + $change;
            $model->setAttribute($column, $value);
            $model->syncOriginalAttribute($column);
        }
    }
}
