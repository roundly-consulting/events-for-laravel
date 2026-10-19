<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Tiers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Events\TierRestored;
use RoundlyConsulting\Events\Models\AdmissionTier;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\LockedEvent;
use RoundlyConsulting\Events\Support\TierLookup;

/**
 * Restores a deleted ticket type of the event (with its key).
 */
final readonly class RestoreTierAction
{
    public function __construct(
        private Dispatcher $events,
    ) {}

    public function execute(Event $event, AdmissionTier|int|string $tier, ?Model $actor = null): AdmissionTier
    {
        return EventTransaction::run($event, function () use ($event, $tier, $actor): AdmissionTier {
            LockedEvent::lock($event);
            $tier = TierLookup::resolve($event, $tier, withTrashed: true);

            if ($tier->trashed()) {
                $tier->restore();
                $event->unsetRelation('tiers');
                $this->events->dispatch(new TierRestored($tier, $actor));
            }

            return $tier;
        });
    }
}
