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
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\TagsManager;

/**
 * Sets (or with null clears) the event's one category. Always a sync: tags refuses an attach
 * past a single type's limit, so "attach" would throw on a re-categorization. A string is a slug
 * or name of a live category (curated: an unknown one throws tags' `TagNotFoundException`), an
 * int a tag key.
 *
 * The event row is locked first, then tags takes its own locks (taggable row, tag rows) inside
 * the same transaction — one consistent order. Fires `EventUpdated` with `category` when it
 * changed.
 */
final readonly class CategorizeEventAction
{
    public function __construct(
        private TagsManager $tags,
        private Dispatcher $events,
    ) {}

    public function execute(Event $event, Tag|int|string|null $category, ?Model $actor = null, bool $silent = false): TagChanges
    {
        return EventTransaction::run($event, function () use ($event, $category, $actor, $silent): TagChanges {
            LockedEvent::lock($event);

            $changes = $this->tags->sync($event, $category, EventTagType::Category);

            if (! $changes->isEmpty()) {
                $this->events->dispatch(new EventUpdated($event, ['category'], $actor, $silent));
            }

            return $changes;
        });
    }
}
