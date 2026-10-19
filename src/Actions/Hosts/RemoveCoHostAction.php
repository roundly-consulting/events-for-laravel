<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Hosts;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Events\CoHostRemoved;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\EventHost;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\LockedEvent;

/**
 * Removes a co-host (nothing happens for a model that is not one).
 */
final readonly class RemoveCoHostAction
{
    public function __construct(
        private Dispatcher $events,
    ) {}

    public function execute(Event $event, Model $host, ?Model $actor = null): bool
    {
        return EventTransaction::run($event, function () use ($event, $host, $actor): bool {
            LockedEvent::lock($event);

            $removed = EventHost::query()
                ->where('event_id', $event->getKey())
                ->where('host_type', $host->getMorphClass())
                ->where('host_id', $host->getKey())
                ->delete() > 0;

            if ($removed) {
                $event->unsetRelation('hosts');
                $this->events->dispatch(new CoHostRemoved($event, $host, $actor));
            }

            return $removed;
        });
    }
}
