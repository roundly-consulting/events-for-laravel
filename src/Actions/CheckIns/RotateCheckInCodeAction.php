<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\CheckIns;

use RoundlyConsulting\Events\Actions\Internal\IssueCheckInCodeAction;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Exceptions\InvalidRsvpTransitionException;
use RoundlyConsulting\Events\Exceptions\RsvpNotFoundException;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\LockedEvent;
use RoundlyConsulting\Events\Support\RsvpRules;

/**
 * Issues a going RSVP a new check-in code (a leaked screenshot, say): the old code stops working
 * at once.
 */
final readonly class RotateCheckInCodeAction
{
    public function __construct(
        private IssueCheckInCodeAction $codes,
    ) {}

    public function execute(Rsvp $rsvp): Rsvp
    {
        $event = $rsvp->event;

        return EventTransaction::run($event, function () use ($event, $rsvp): Rsvp {
            LockedEvent::lock($event, allowTrashed: true);
            $current = RsvpRules::rsvpOf($event, $rsvp) ?? throw RsvpNotFoundException::missing();

            if ($current->status !== RsvpStatus::Going) {
                throw InvalidRsvpTransitionException::notGoing();
            }

            $this->codes->execute($current);
            $current->save();
            $rsvp->setRawAttributes($current->getAttributes(), true);

            return $rsvp;
        });
    }
}
