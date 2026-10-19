<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Tiers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Events\TierDeleted;
use RoundlyConsulting\Events\Exceptions\InvalidTierException;
use RoundlyConsulting\Events\Models\AdmissionTier;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\LockedEvent;
use RoundlyConsulting\Events\Support\TierLookup;

/**
 * Soft-deletes a ticket type — refused while anyone is going on it, holds its seats or waits for
 * them (a waitlisted RSVP on a deleted ticket type could never be promoted). Its key stays taken;
 * `tiers()->restore()` brings it back.
 */
final readonly class DeleteTierAction
{
    public function __construct(
        private Dispatcher $events,
    ) {}

    public function execute(Event $event, AdmissionTier|int|string $tier, ?Model $actor = null): void
    {
        EventTransaction::run($event, function () use ($event, $tier, $actor): void {
            LockedEvent::lock($event);
            $tier = TierLookup::resolve($event, $tier);

            $inUse = EventModels::rsvp()::query()
                ->where('tier_id', $tier->getKey())
                ->whereIn('status', [RsvpStatus::Going->value, RsvpStatus::Held->value, RsvpStatus::Waitlisted->value])
                ->exists();

            if ($inUse) {
                throw InvalidTierException::inUse();
            }

            $tier->delete();
            $event->unsetRelation('tiers');
            $this->events->dispatch(new TierDeleted($tier, $actor));
        });
    }
}
