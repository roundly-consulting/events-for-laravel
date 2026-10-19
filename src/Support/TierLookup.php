<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use RoundlyConsulting\Events\Exceptions\TierNotFoundException;
use RoundlyConsulting\Events\Models\AdmissionTier;
use RoundlyConsulting\Events\Models\Event;

/**
 * Resolves a ticket type of one event — a model, an id or a key. A ticket type of another event,
 * a trashed one and an unknown one are all not found (scoping is a security boundary).
 *
 * @internal
 */
final class TierLookup
{
    public static function resolve(Event $event, AdmissionTier|int|string $tier, bool $withTrashed = false): AdmissionTier
    {
        $query = EventModels::tier()::query()->where('event_id', $event->getKey());

        if ($withTrashed) {
            $query->withTrashed();
        }

        $found = match (true) {
            $tier instanceof AdmissionTier => $query->whereKey($tier->getKey())->first(),
            is_int($tier) => $query->whereKey($tier)->first(),
            default => $query->where('key', $tier)->first(),
        };

        return $found ?? throw TierNotFoundException::missing();
    }
}
