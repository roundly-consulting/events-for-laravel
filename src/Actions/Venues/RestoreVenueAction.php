<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Venues;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Events\VenueRestored;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Support\EventAuthorization;

/**
 * Restores a soft-deleted venue.
 */
final readonly class RestoreVenueAction
{
    public function __construct(
        private Dispatcher $events,
    ) {}

    public function execute(Venue $venue, ?Model $actor = null): Venue
    {
        EventAuthorization::authorize($actor, 'restore', $venue);

        if ($venue->trashed()) {
            $venue->restore();
            $this->events->dispatch(new VenueRestored($venue, $actor));
        }

        return $venue;
    }
}
