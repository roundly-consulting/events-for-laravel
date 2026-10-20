<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Rsvps;

use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Actions\Internal\ApplySeatChangesAction;
use RoundlyConsulting\Events\Actions\Internal\AutoPromoteAction;
use RoundlyConsulting\Events\Actions\Internal\ExpireHoldsAction;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Exceptions\RsvpNotFoundException;
use RoundlyConsulting\Events\Jobs\RequestAdmissionPaymentJob;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\RsvpRules;
use RoundlyConsulting\Events\Support\SeatBook;
use RoundlyConsulting\Events\Support\SeatErrors;

/**
 * A manager changes the number of guests: more needs seats (else the event or ticket type is
 * full), fewer gives seats to the waitlist; a hold is re-priced and requests a new payment.
 */
final readonly class ChangeGuestsAction
{
    public function __construct(
        private ApplySeatChangesAction $seats,
        private ExpireHoldsAction $holds,
        private AutoPromoteAction $promote,
        private Bus $bus,
    ) {}

    public function execute(Event $event, Rsvp|Model $who, int $guests, ?Model $actor = null): Rsvp
    {
        return EventTransaction::run($event, function () use ($event, $who, $guests, $actor): Rsvp {
            $book = SeatBook::open($event);
            // Lapsed holds give their seats back before anything is counted.
            $expired = $this->holds->execute($book);
            $rsvp = RsvpRules::rsvpOf($event, $who) ?? throw RsvpNotFoundException::missing();
            $tierId = $rsvp->tier_id === null ? null : (int) $rsvp->tier_id;
            RsvpRules::assertParty($event, $book->tier($tierId), $guests);

            if ($guests === $rsvp->guests) {
                if ($expired > 0) {
                    $this->promote->execute($book);
                }

                return $rsvp;
            }

            if ($rsvp->status->takesSeats() && ! $book->fitsReplacing(1 + $guests, $tierId, $rsvp->partySize(), $tierId)) {
                throw SeatErrors::noRoom($book, 1 + $guests, $rsvp->partySize());
            }

            $this->seats->execute($book, $rsvp, $rsvp->status, $guests, $tierId, $actor);

            if ($rsvp->status === RsvpStatus::Held) {
                $this->bus->dispatch((new RequestAdmissionPaymentJob((int) $rsvp->getKey()))->afterCommit());
            }

            $this->promote->execute($book);

            return $rsvp;
        });
    }
}
