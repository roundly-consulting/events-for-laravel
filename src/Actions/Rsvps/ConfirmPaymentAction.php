<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Rsvps;

use RoundlyConsulting\Events\Actions\Internal\ApplySeatChangesAction;
use RoundlyConsulting\Events\Actions\Internal\ExpireHoldsAction;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Exceptions\RsvpClosedException;
use RoundlyConsulting\Events\Exceptions\RsvpHoldExpiredException;
use RoundlyConsulting\Events\Exceptions\RsvpNotFoundException;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\RsvpRules;
use RoundlyConsulting\Events\Support\SeatBook;

/**
 * Your payment webhook confirms a held RSVP by its checkout reference: held → going (a check-in
 * code is issued). Idempotent: a repeat on a going RSVP changes nothing.
 *
 * A late payment (the hold lapsed) takes a free seat again, else `RsvpHoldExpiredException` — as
 * for an attendee who declined while held, who is never put back. An unknown reference, or one a
 * newer hold replaced, is `RsvpNotFoundException`; a cancelled event is `RsvpClosedException`. In
 * each of those cases, refund the payment. A postponed event still confirms: the seat existed.
 */
final readonly class ConfirmPaymentAction
{
    public function __construct(
        private ApplySeatChangesAction $seats,
        private ExpireHoldsAction $holds,
    ) {}

    public function execute(string $reference): Rsvp
    {
        $found = EventModels::rsvp()::query()->where('payment_reference', $reference)->first()
            ?? throw RsvpNotFoundException::unknownReference();
        $event = $found->event;

        return EventTransaction::run($event, function () use ($event, $found, $reference): Rsvp {
            $book = SeatBook::open($event, allowTrashed: true);
            $rsvp = RsvpRules::rsvpOf($event, $found);

            if ($rsvp === null || $rsvp->payment_reference !== $reference) {
                throw RsvpNotFoundException::unknownReference();
            }

            if ($event->trashed() || $event->phase === EventPhase::Cancelled) {
                throw RsvpClosedException::closed();
            }

            if ($rsvp->status === RsvpStatus::Going) {
                return $rsvp;
            }

            $this->holds->execute($book);
            $rsvp->refresh();
            $tierId = $rsvp->tier_id === null ? null : (int) $rsvp->tier_id;

            return match ($rsvp->status) {
                RsvpStatus::Held => $this->seats->execute($book, $rsvp, RsvpStatus::Going, $rsvp->guests, $tierId),
                RsvpStatus::Expired => $book->fits($rsvp->partySize(), $tierId)
                    ? $this->seats->execute($book, $rsvp, RsvpStatus::Going, $rsvp->guests, $tierId, late: true)
                    : throw RsvpHoldExpiredException::expired(),
                default => throw RsvpHoldExpiredException::expired(),
            };
        });
    }
}
