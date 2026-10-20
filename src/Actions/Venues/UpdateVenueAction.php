<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Venues;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Actions\Internal\ResequenceVenueEventsAction;
use RoundlyConsulting\Events\DataTransferObjects\UpdateVenueData;
use RoundlyConsulting\Events\Events\VenueUpdated;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Support\EventAuthorization;
use RoundlyConsulting\Events\Support\NotGiven;
use RoundlyConsulting\Events\Support\VenueInput;

/**
 * Changes a venue: only the fields given. Never changes the timezone or instants of its events —
 * `events:doctor` reports events whose timezone no longer matches their venue's. A new name,
 * address or coordinates raise the calendar `sequence` of its announced events that are not over.
 */
final readonly class UpdateVenueAction
{
    public function __construct(
        private ResequenceVenueEventsAction $sequences,
        private Dispatcher $events,
    ) {}

    public function execute(Venue $venue, UpdateVenueData $data, ?Model $actor = null): Venue
    {
        EventAuthorization::authorize($actor, 'update', $venue);

        if (! $data->name instanceof NotGiven) {
            $venue->setAttribute('name', VenueInput::name($data->name));
        }

        if (! $data->address instanceof NotGiven) {
            VenueInput::applyAddress($venue, $data->address);
        }

        if (! $data->coordinates instanceof NotGiven) {
            $venue->coordinates = $data->coordinates;
        }

        if (! $data->timezone instanceof NotGiven) {
            $venue->timezone = VenueInput::timezone($data->timezone);
        }

        if (! $data->capacity instanceof NotGiven) {
            $venue->capacity = VenueInput::capacity($data->capacity);
        }

        if (! $data->meta instanceof NotGiven) {
            $venue->meta = $data->meta === [] ? null : $data->meta;
        }

        $changed = array_keys($venue->getDirty());

        if ($changed !== []) {
            $venue->save();
            $this->sequences->execute($venue, $changed);
            $this->events->dispatch(new VenueUpdated($venue, $changed, $actor));
        }

        return $venue;
    }
}
