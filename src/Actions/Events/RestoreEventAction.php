<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Events;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Events\EventRestored;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\LockedEvent;

/**
 * Restores a soft-deleted event (its slugs, poster, RSVPs and lifecycle state were kept).
 */
final readonly class RestoreEventAction
{
    public function __construct(
        private Dispatcher $events,
    ) {}

    public function execute(Event $event, ?Model $actor = null): Event
    {
        return EventTransaction::run($event, function () use ($event, $actor): Event {
            LockedEvent::lock($event, allowTrashed: true);

            if ($event->trashed()) {
                $event->restore();
                $this->events->dispatch(new EventRestored($event, $actor));
            }

            return $event;
        });
    }
}
