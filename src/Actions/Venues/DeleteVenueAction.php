<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Venues;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Events\VenueDeleted;
use RoundlyConsulting\Events\Exceptions\InvalidVenueException;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Support\EventAuthorization;
use RoundlyConsulting\Events\Support\EventModels;

/**
 * Soft-deletes a venue — refused while an event that is not over uses it (a draft, scheduled,
 * postponed or rescheduled one: a postponed event past its original date is still alive). Past
 * events keep their trashed venue for calendars and structured data.
 */
final readonly class DeleteVenueAction
{
    public function __construct(
        private Dispatcher $events,
    ) {}

    public function execute(Venue $venue, ?Model $actor = null): void
    {
        EventAuthorization::authorize($actor, 'delete', $venue);

        $inUse = EventModels::event()::query()
            ->where('venue_id', $venue->getKey())
            ->inPhase(EventPhase::Draft, EventPhase::Scheduled, EventPhase::Postponed, EventPhase::Rescheduled)
            ->exists();

        if ($inUse) {
            throw InvalidVenueException::inUse();
        }

        $venue->delete();
        $this->events->dispatch(new VenueDeleted($venue, $actor));
    }
}
