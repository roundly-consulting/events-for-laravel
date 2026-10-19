<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Events;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Actions\Internal\WriteCoHostAction;
use RoundlyConsulting\Events\Contracts\Organizer;
use RoundlyConsulting\Events\Events\OrganizerTransferred;
use RoundlyConsulting\Events\Exceptions\InvalidOrganizerException;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\EventHost;
use RoundlyConsulting\Events\Support\EventManagers;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\KeyTypes;
use RoundlyConsulting\Events\Support\LockedEvent;

/**
 * Hands the event to another organizer. With an actor, the new organizer must allow them (you
 * cannot give an event to a team you do not belong to); `keepAsHost` keeps the old organizer as a
 * co-host. A new organizer that was a co-host stops being one.
 */
final readonly class TransferOrganizerAction
{
    public function __construct(
        private WriteCoHostAction $hosts,
        private Dispatcher $events,
    ) {}

    public function execute(Event $event, Model&Organizer $organizer, bool $keepAsHost = false, ?Model $actor = null): Event
    {
        KeyTypes::assertMorphable($organizer);

        if ($actor !== null && ! EventManagers::modelAllows($organizer, $actor) && ! EventManagers::isAdmin($actor)) {
            throw InvalidOrganizerException::notAllowed();
        }

        return EventTransaction::run($event, function () use ($event, $organizer, $keepAsHost, $actor): Event {
            LockedEvent::lock($event);
            $previous = $event->organizer;

            if ($previous !== null && $previous->is($organizer)) {
                return $event;
            }

            EventHost::query()
                ->where('event_id', $event->getKey())
                ->where('host_type', $organizer->getMorphClass())
                ->where('host_id', $organizer->getKey())
                ->delete();

            $event->organizer_type = $organizer->getMorphClass();
            $event->organizer_id = $organizer->getKey();
            $event->save();
            $event->setRelation('organizer', $organizer);
            $event->unsetRelation('hosts');

            if ($keepAsHost && $previous !== null) {
                $this->hosts->execute($event, $previous, $actor);
            }

            if ($previous !== null) {
                $this->events->dispatch(new OrganizerTransferred($event, $previous, $organizer, $actor));
            }

            return $event;
        });
    }
}
