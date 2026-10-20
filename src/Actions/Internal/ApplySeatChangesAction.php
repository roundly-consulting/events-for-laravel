<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Internal;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Crypto\CryptoManager;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Events\AttendeeConfirmed;
use RoundlyConsulting\Events\Events\AttendeePromoted;
use RoundlyConsulting\Events\Events\AttendeeWaitlisted;
use RoundlyConsulting\Events\Events\RsvpCancelled;
use RoundlyConsulting\Events\Events\RsvpChanged;
use RoundlyConsulting\Events\Events\RsvpHoldExpired;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Support\Clock;
use RoundlyConsulting\Events\Support\EventsConfig;
use RoundlyConsulting\Events\Support\SeatBook;

/**
 * The one place an RSVP changes status, party or ticket type: it sets what the target status
 * implies — a check-in code for going, a priced hold for held, a queue place for waitlisted — and
 * moves the event's and the ticket types' counters by exact deltas on the locked rows. Fires
 * `RsvpChanged` and the status events (after commit). `answer` marks the attendee's own answer
 * (`responded_at`); `promotion` a move off the waitlist; `paid` a seat a payment confirmed —
 * any other way into going (a free ticket type, a comp seat) drops the old hold's checkout, so
 * its webhook is not found (refund) and leaving never reports a payment.
 *
 * @internal
 */
final readonly class ApplySeatChangesAction
{
    public function __construct(
        private IssueCheckInCodeAction $codes,
        private Dispatcher $events,
        private CryptoManager $crypto,
    ) {}

    public function execute(
        SeatBook $book,
        Rsvp $rsvp,
        RsvpStatus $to,
        int $guests,
        ?int $tierId,
        ?Model $actor = null,
        bool $promotion = false,
        bool $late = false,
        bool $answer = false,
        bool $paid = false,
    ): Rsvp {
        $from = $rsvp->exists ? $rsvp->status : null;
        $fromParty = $rsvp->exists ? $rsvp->partySize() : 0;
        $fromTier = $rsvp->exists && $rsvp->tier_id !== null ? (int) $rsvp->tier_id : null;
        $wasPaid = $from === RsvpStatus::Going && $rsvp->payment_reference !== null && $rsvp->amount !== null;

        $rsvp->status = $to;
        $rsvp->guests = $guests;
        $rsvp->tier_id = $tierId;
        $now = Clock::now();

        match ($to) {
            RsvpStatus::Going => $this->going($rsvp, $from, $paid),
            RsvpStatus::Held => $this->held($book, $rsvp, $promotion, $from !== RsvpStatus::Held || $fromParty !== $rsvp->partySize() || $fromTier !== $tierId),
            RsvpStatus::Waitlisted => $this->waitlisted($rsvp, $from !== RsvpStatus::Waitlisted || $fromTier !== $tierId),
            default => $this->away($rsvp),
        };

        if ($answer) {
            $rsvp->responded_at = $now;
        }

        $book->move($rsvp, $from, $fromParty, $fromTier);

        if ($from !== $to) {
            $this->events->dispatch(new RsvpChanged($rsvp, $from, $actor));
        }

        if ($to === RsvpStatus::Going && $from !== RsvpStatus::Going) {
            $this->events->dispatch(new AttendeeConfirmed($rsvp, $late));
        }

        if ($to === RsvpStatus::Waitlisted && $from !== RsvpStatus::Waitlisted) {
            $this->events->dispatch(new AttendeeWaitlisted($rsvp));
        }

        if ($promotion) {
            $this->events->dispatch(new AttendeePromoted($rsvp));
        }

        if ($to === RsvpStatus::Expired && $from === RsvpStatus::Held) {
            $this->events->dispatch(new RsvpHoldExpired($rsvp));
        }

        if ($from !== null && $from->takesSeats() && in_array($to, [RsvpStatus::Interested, RsvpStatus::NotGoing], true)) {
            $this->events->dispatch(new RsvpCancelled($rsvp, $wasPaid));
        }

        return $rsvp;
    }

    private function going(Rsvp $rsvp, ?RsvpStatus $from, bool $paid): void
    {
        if ($from !== RsvpStatus::Going) {
            $rsvp->confirmed_at = Clock::now();

            if (! $paid) {
                $rsvp->payment_reference = null;
                $rsvp->payment_url = null;
                $rsvp->amount = null;
            }
        }

        $rsvp->held_until = null;
        $rsvp->waitlisted_at = null;

        if ($rsvp->check_in_code_hash === null) {
            $this->codes->execute($rsvp);
        }
    }

    private function held(SeatBook $book, Rsvp $rsvp, bool $promotion, bool $refresh): void
    {
        $this->revokeCode($rsvp);
        $rsvp->waitlisted_at = null;

        if (! $refresh) {
            return;
        }

        $hold = $promotion ? EventsConfig::promotionHold() : EventsConfig::holdFor();
        $until = Clock::now()->add($hold);
        $startsAt = $book->event->starts_at;
        $price = $book->tier($rsvp->tier_id === null ? null : (int) $rsvp->tier_id)?->price;

        $rsvp->held_until = $until->greaterThan($startsAt) ? $startsAt : $until;
        $rsvp->amount = $price?->multiply((string) $rsvp->partySize());
        // A new hold supersedes any checkout of the previous one, and gets its own idempotency
        // key (kept after the hold ends, for the audit).
        $rsvp->payment_reference = null;
        $rsvp->payment_url = null;
        $rsvp->hold_key = $this->crypto->base32Encode($this->crypto->randomBytes(10));
    }

    private function waitlisted(Rsvp $rsvp, bool $requeue): void
    {
        $this->revokeCode($rsvp);
        $rsvp->held_until = null;

        if ($requeue || $rsvp->waitlisted_at === null) {
            $rsvp->waitlisted_at = Clock::now();
        }
    }

    private function away(Rsvp $rsvp): void
    {
        $this->revokeCode($rsvp);
        $rsvp->held_until = null;
        $rsvp->waitlisted_at = null;
    }

    private function revokeCode(Rsvp $rsvp): void
    {
        $rsvp->check_in_code = null;
        $rsvp->check_in_code_hash = null;
    }
}
