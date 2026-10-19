<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Tiers;

use RoundlyConsulting\Events\Exceptions\InvalidTierException;
use RoundlyConsulting\Events\Models\AdmissionTier;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\LockedEvent;
use RoundlyConsulting\Events\Support\TierLookup;

/**
 * Orders the event's ticket types: the list names every live one exactly once (models, ids or
 * keys), first to last.
 */
final readonly class ReorderTiersAction
{
    /**
     * @param  list<AdmissionTier|int|string>  $order
     */
    public function execute(Event $event, array $order): void
    {
        EventTransaction::run($event, static function () use ($event, $order): void {
            LockedEvent::lock($event);

            $ids = array_map(static fn (AdmissionTier|int|string $tier): int => TierLookup::resolve($event, $tier)->getKey(), $order);
            $live = EventModels::tier()::query()->where('event_id', $event->getKey())->pluck('id')->map(static fn (mixed $id): int => (int) $id)->sort()->values()->all();
            $given = $ids;
            sort($given);

            if ($given !== $live) {
                throw InvalidTierException::reorderMismatch();
            }

            foreach ($ids as $position => $id) {
                EventModels::tier()::query()->whereKey($id)->update(['position' => $position + 1]);
            }

            $event->unsetRelation('tiers');
        });
    }
}
