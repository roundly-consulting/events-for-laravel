<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Listeners;

use RoundlyConsulting\Events\Actions\Internal\ExpireHoldsAction;
use RoundlyConsulting\Events\Contracts\EventState;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\SeatBook;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitioning;

/**
 * Inside the transition's transaction, on the event row lifecycle already holds: every status
 * change raises the calendar `sequence`, and entering Cancelled releases every held seat (no
 * payment is ever collected for a cancelled event). Runs for lifecycle's own model shortcuts too.
 *
 * @internal
 */
final readonly class OnEventTransitioning
{
    public function __construct(
        private ExpireHoldsAction $holds,
    ) {}

    public function handle(LifecycleTransitioning $transitioning): void
    {
        $event = $transitioning->subject;

        if (! $event instanceof Event || ! is_a($event, EventModels::event()) || $transitioning->lifecycle !== 'status') {
            return;
        }

        $event->newQueryWithoutScopes()->whereKey($event->getKey())->increment('sequence');
        $event->forceFill(['sequence' => $event->sequence + 1])->syncOriginalAttribute('sequence');

        $to = $transitioning->to;

        if ($to instanceof EventState && $to->phase() === EventPhase::Cancelled) {
            $this->onCancelled($event);
        }
    }

    /**
     * Every hold expires (no payment is collected for a cancelled event); going attendees keep their
     * RSVPs — they are told, and the host refunds the paid ones (`RsvpCancelled` is not fired for
     * them: they did not leave).
     */
    private function onCancelled(Event $event): void
    {
        $this->holds->execute(SeatBook::open($event), all: true);
    }
}
