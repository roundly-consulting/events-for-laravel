<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Rsvps;

use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Actions\Internal\ApplySeatChangesAction;
use RoundlyConsulting\Events\Actions\Internal\AutoPromoteAction;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Exceptions\RsvpNotFoundException;
use RoundlyConsulting\Events\Jobs\RequestAdmissionPaymentJob;
use RoundlyConsulting\Events\Models\AdmissionTier;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\RsvpRules;
use RoundlyConsulting\Events\Support\SeatBook;
use RoundlyConsulting\Events\Support\SeatErrors;

/**
 * A manager moves an RSVP to another ticket type (paid or free, hidden included). Going stays
 * going; a hold is re-priced and requests a new payment; a waitlisted RSVP queues at the back of
 * the new ticket type. The old ticket type's seats go to its waitlist.
 */
final readonly class ChangeTierAction
{
    public function __construct(
        private ApplySeatChangesAction $seats,
        private AutoPromoteAction $promote,
        private Bus $bus,
    ) {}

    public function execute(Event $event, Rsvp|Model $who, AdmissionTier|int|string $tier, ?Model $actor = null): Rsvp
    {
        return EventTransaction::run($event, function () use ($event, $who, $tier, $actor): Rsvp {
            $book = SeatBook::open($event);
            $rsvp = RsvpRules::rsvpOf($event, $who) ?? throw RsvpNotFoundException::missing();
            $chosen = RsvpRules::tier($book, $tier, manager: true);
            RsvpRules::assertParty($event, $chosen, $rsvp->guests);

            $tierId = $chosen === null ? null : (int) $chosen->getKey();
            $currentTier = $rsvp->tier_id === null ? null : (int) $rsvp->tier_id;

            if ($tierId === $currentTier) {
                return $rsvp;
            }

            $to = $rsvp->status;

            if ($to->takesSeats()) {
                if (! $book->fitsReplacing($rsvp->partySize(), $tierId, $rsvp->partySize(), $currentTier)) {
                    throw SeatErrors::noRoom($book, $rsvp->partySize(), $rsvp->partySize());
                }

                if ($to === RsvpStatus::Held && ($chosen === null || $chosen->isFree())) {
                    $to = RsvpStatus::Going;
                }
            }

            $this->seats->execute($book, $rsvp, $to, $rsvp->guests, $tierId, $actor);

            if ($rsvp->status === RsvpStatus::Held) {
                $this->bus->dispatch((new RequestAdmissionPaymentJob((int) $rsvp->getKey()))->afterCommit());
            }

            $this->promote->execute($book);

            return $rsvp;
        });
    }
}
