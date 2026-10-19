<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use RoundlyConsulting\Events\Exceptions\EventNotFoundException;
use RoundlyConsulting\Events\Exceptions\EventTrashedException;
use RoundlyConsulting\Events\Models\Event;

/**
 * Lock first, then read. The event row `FOR UPDATE` is the first statement of every seat
 * transaction: under MySQL's REPEATABLE READ a plain read before it would fix a stale snapshot,
 * and two racers could both see a free seat. The locked row's stored values are copied onto the
 * given model, so the caller (and a deadlock retry) works on current state.
 *
 * Lock order everywhere: the event row, then its ticket type rows (ascending id), then RSVP rows.
 * Lifecycle's own locker also takes the event row first, and tags-for-laravel locks the taggable
 * row first, so nesting either inside an events transaction never inverts the order.
 *
 * @internal
 */
final class LockedEvent
{
    public static function lock(Event $event, bool $allowTrashed = false): Event
    {
        $fresh = $event->newQueryWithoutScopes()
            ->whereKey($event->getKey())
            ->lockForUpdate()
            ->first();

        if (! $fresh instanceof Event) {
            throw EventNotFoundException::missing();
        }

        $event->setRawAttributes($fresh->getAttributes(), true);

        if (! $allowTrashed && $event->trashed()) {
            throw EventTrashedException::trashed();
        }

        return $event;
    }
}
