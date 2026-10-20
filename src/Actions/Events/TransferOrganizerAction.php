<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Events;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Actions\Internal\WriteCoHostAction;
use RoundlyConsulting\Events\Contracts\Organizer;
use RoundlyConsulting\Events\Enums\EventTagType;
use RoundlyConsulting\Events\Events\OrganizerTransferred;
use RoundlyConsulting\Events\Exceptions\InvalidOrganizerException;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\EventHost;
use RoundlyConsulting\Events\Support\EventManagers;
use RoundlyConsulting\Events\Support\EventsConfig;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\KeyTypes;
use RoundlyConsulting\Events\Support\LockedEvent;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\TagsManager;

/**
 * Hands the event to another organizer. With an actor, the new organizer must allow them (you
 * cannot give an event to a team you do not belong to); `keepAsHost` keeps the old organizer as a
 * co-host. A new organizer that was a co-host stops being one. With labels scoped per organizer
 * (`events.tags.scope = organizer`) the event's labels move into the new organizer's catalogue
 * (found by name there, or created with every translation), so the new organizer manages them.
 */
final readonly class TransferOrganizerAction
{
    public function __construct(
        private WriteCoHostAction $hosts,
        private TagsManager $tags,
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

            $labels = EventsConfig::tagsScopedByOrganizer() ? $event->labels : null;

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

            // The event row is locked; tags takes its own locks after it.
            if ($labels !== null && $labels->isNotEmpty()) {
                $catalog = $this->tags->type(EventTagType::Label)->within($organizer);
                $this->tags->sync($event, $labels->map(static fn (Tag $label): Tag => $catalog->findOrCreate($label->getTranslations('name')))->all(), EventTagType::Label);
            }

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
