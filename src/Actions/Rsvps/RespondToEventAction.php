<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Rsvps;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Actions\Internal\ApplySeatChangesAction;
use RoundlyConsulting\Events\Actions\Internal\AutoPromoteAction;
use RoundlyConsulting\Events\Actions\Internal\ExpireHoldsAction;
use RoundlyConsulting\Events\DataTransferObjects\RsvpRequest;
use RoundlyConsulting\Events\Enums\InvitationStatus;
use RoundlyConsulting\Events\Enums\RsvpResponse;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Exceptions\InvalidRsvpTransitionException;
use RoundlyConsulting\Events\Models\AdmissionTier;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\EventInvitation;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Support\Clock;
use RoundlyConsulting\Events\Support\EventManagers;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\KeyTypes;
use RoundlyConsulting\Events\Support\RsvpRules;
use RoundlyConsulting\Events\Support\SeatBook;
use RoundlyConsulting\Events\Support\SeatErrors;

/**
 * An attendee answers: going, interested or not going. One RSVP per attendee and event.
 *
 * Going takes seats — the party (attendee plus guests) within the ticket type's limits — while
 * both the event and the ticket type have room and nobody waiting for that ticket type would fit
 * them (no queue-jumping); a free ticket type is then going, a paid one held while the payment is
 * pending. Without room the attendee joins the waitlist (or, without one, `EventFullException` /
 * `TierSoldOutException`). Leaving frees the seats and the waitlist moves up in the same
 * transaction. A checked-in attendee cannot leave.
 *
 * Race-free: the event row is locked first, then its ticket types, then the RSVP; lapsed holds
 * are released before any seat is counted. A hold the attendee asked for requests its payment
 * right after commit, so the caller gets the checkout; the collector's exception reaches them.
 */
final readonly class RespondToEventAction
{
    public function __construct(
        private ApplySeatChangesAction $seats,
        private ExpireHoldsAction $holds,
        private AutoPromoteAction $promote,
        private RequestAdmissionPaymentAction $payments,
    ) {}

    public function execute(Event $event, RsvpRequest $request): Rsvp
    {
        KeyTypes::assertMorphable($request->attendee);
        KeyTypes::assertMorphable($request->actor);

        $rsvp = EventTransaction::run($event, function () use ($event, $request): Rsvp {
            $book = SeatBook::open($event);
            $manager = EventManagers::allows($event, $request->actor) || EventManagers::allows($event, $request->attendee);

            RsvpRules::assertOpen($event, $request->response, $manager);
            $invitation = RsvpRules::assertInvited($event, $request->attendee, $request->response, $manager);
            $this->holds->execute($book);

            $rsvp = RsvpRules::rsvpOf($event, $request->attendee) ?? $this->blank($event, $request->attendee);

            if ($request->note !== null) {
                $rsvp->note = $request->note;
            }

            match ($request->response) {
                RsvpResponse::Going => $this->going($book, $rsvp, $request, $manager),
                RsvpResponse::Interested => $this->leave($book, $rsvp, RsvpStatus::Interested, $request->actor),
                RsvpResponse::NotGoing => $this->leave($book, $rsvp, RsvpStatus::NotGoing, $request->actor),
            };

            if ($invitation !== null && $request->response === RsvpResponse::Going) {
                $this->accept($invitation);
            }

            $this->promote->execute($book);

            return $rsvp;
        });

        // A new or refreshed hold has a new key — held_until alone misses a refresh near the
        // start, where every hold ends at the start.
        if ($rsvp->status === RsvpStatus::Held && $rsvp->payment_reference === null
            && ($rsvp->wasChanged('hold_key') || $rsvp->wasRecentlyCreated)) {
            $rsvp->getConnection()->afterCommit(fn () => $this->payments->execute($rsvp));
        }

        return $rsvp;
    }

    private function going(SeatBook $book, Rsvp $rsvp, RsvpRequest $request, bool $manager): void
    {
        $current = $rsvp->exists ? $rsvp->status : null;
        $currentTier = $rsvp->exists && $rsvp->tier_id !== null ? (int) $rsvp->tier_id : null;
        $currentParty = $rsvp->exists ? $rsvp->partySize() : 0;
        $keepsTier = $request->tier === null && $currentTier !== null && in_array($current, [RsvpStatus::Going, RsvpStatus::Held, RsvpStatus::Waitlisted], true);

        $tier = RsvpRules::tier($book, $keepsTier ? $currentTier : $request->tier, $manager);
        RsvpRules::assertParty($book->event, $tier, $request->guests);

        $tierId = $tier === null ? null : (int) $tier->getKey();
        $party = 1 + $request->guests;
        $target = $this->seatStatus($tier);

        if ($current === RsvpStatus::Going || $current === RsvpStatus::Held) {
            if ($tierId === $currentTier && $party === $currentParty) {
                return;
            }

            if ($current === RsvpStatus::Going && $tierId !== $currentTier
                && ($target === RsvpStatus::Held || ! ($book->tier($currentTier)?->isFree() ?? true))) {
                // No orders, no price difference to collect: a paid ticket type changes by hand.
                throw InvalidRsvpTransitionException::paidTierChange();
            }

            if (! $book->fitsReplacing($party, $tierId, $currentParty, $currentTier)) {
                throw SeatErrors::noRoom($book, $party, $currentParty);
            }

            $this->seats->execute($book, $rsvp, $current === RsvpStatus::Going ? RsvpStatus::Going : $target, $request->guests, $tierId, $request->actor, answer: true);

            return;
        }

        if ($current === RsvpStatus::Waitlisted && $tierId === $currentTier) {
            if ($party !== $currentParty) {
                $this->seats->execute($book, $rsvp, RsvpStatus::Waitlisted, $request->guests, $tierId, $request->actor, answer: true);
            }

            return;
        }

        if ($book->fits($party, $tierId) && ! $book->waitlistWouldFit($tierId, $rsvp->exists ? (int) $rsvp->getKey() : null)) {
            $this->seats->execute($book, $rsvp, $target, $request->guests, $tierId, $request->actor, answer: true);

            return;
        }

        if (! $book->event->waitlist) {
            throw SeatErrors::noRoom($book, $party);
        }

        $this->seats->execute($book, $rsvp, RsvpStatus::Waitlisted, $request->guests, $tierId, $request->actor, answer: true);
    }

    private function leave(SeatBook $book, Rsvp $rsvp, RsvpStatus $to, ?Model $actor): void
    {
        $current = $rsvp->exists ? $rsvp->status : null;

        if ($current === $to) {
            if ($rsvp->isDirty()) {
                $rsvp->save();
            }

            return;
        }

        if ($current === RsvpStatus::Going && $rsvp->isCheckedIn()) {
            throw InvalidRsvpTransitionException::checkedIn();
        }

        $this->seats->execute($book, $rsvp, $to, $rsvp->exists ? $rsvp->guests : 0, $rsvp->tier_id === null ? null : (int) $rsvp->tier_id, $actor, answer: true);
    }

    private function seatStatus(?AdmissionTier $tier): RsvpStatus
    {
        return $tier !== null && ! $tier->isFree() ? RsvpStatus::Held : RsvpStatus::Going;
    }

    private function blank(Event $event, Model $attendee): Rsvp
    {
        $model = EventModels::rsvp();
        $rsvp = new $model;
        $rsvp->event_id = $event->getKey();
        $rsvp->attendee_type = $attendee->getMorphClass();
        $rsvp->attendee_id = $attendee->getKey();
        $rsvp->setRelation('attendee', $attendee);

        return $rsvp;
    }

    private function accept(EventInvitation $invitation): void
    {
        if ($invitation->status === InvitationStatus::Pending) {
            $invitation->status = InvitationStatus::Accepted;
            $invitation->responded_at = Clock::now();
            $invitation->token_hash = null;
            $invitation->save();
        }
    }
}
