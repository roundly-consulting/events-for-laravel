<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Tags;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Enums\EventTagType;
use RoundlyConsulting\Events\Events\EventUpdated;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\LockedEvent;
use RoundlyConsulting\Tags\DataTransferObjects\TagChanges;
use RoundlyConsulting\Tags\TagsManager;

/**
 * Adds labels to the event (free-form: unknown names are created; at most ten per event). Names, slugs, tag keys or tag models.
 *
 * The event row is locked first, then tags takes its own locks inside the same transaction.
 * Fires `EventUpdated` with `labels` when anything changed.
 */
final readonly class AttachLabelsAction
{
    public function __construct(
        private TagsManager $tags,
        private Dispatcher $events,
    ) {}

    public function execute(Event $event, mixed $labels, ?Model $actor = null, bool $silent = false): TagChanges
    {
        return EventTransaction::run($event, function () use ($event, $labels, $actor, $silent): TagChanges {
            LockedEvent::lock($event);

            $changes = $this->tags->attach($event, $labels, EventTagType::Label);

            if (! $changes->isEmpty()) {
                $this->events->dispatch(new EventUpdated($event, ['labels'], $actor, $silent));
            }

            return $changes;
        });
    }
}
