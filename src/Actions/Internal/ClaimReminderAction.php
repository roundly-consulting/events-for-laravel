<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Internal;

use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\ReminderDelivery;
use RoundlyConsulting\Events\Support\Clock;

/**
 * Claims one reminder: the insert of `(event, offset, starts_at)` into the deliveries table is
 * the claim, so with any number of sweepers exactly one wins. A rescheduled event has a new
 * `starts_at`, so its reminders re-arm on their own.
 *
 * @internal
 */
final readonly class ClaimReminderAction
{
    public function execute(Event $event, string $offset): ?ReminderDelivery
    {
        $delivery = new ReminderDelivery;
        $inserted = $delivery->newQuery()->insertOrIgnore([
            'event_id' => $event->getKey(),
            'offset' => $offset,
            'starts_at' => Clock::format($event->starts_at),
        ]);

        if ($inserted !== 1) {
            return null;
        }

        return $delivery->newQuery()
            ->where('event_id', $event->getKey())
            ->where('offset', $offset)
            ->where('starts_at', Clock::format($event->starts_at))
            ->first();
    }
}
