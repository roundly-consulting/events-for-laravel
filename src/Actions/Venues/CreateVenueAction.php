<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Venues;

use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Events\Dispatcher;
use RoundlyConsulting\Events\DataTransferObjects\VenueData;
use RoundlyConsulting\Events\Events\VenueCreated;
use RoundlyConsulting\Events\Jobs\GeocodeVenueJob;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Support\EventAuthorization;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventsConfig;
use RoundlyConsulting\Events\Support\KeyTypes;
use RoundlyConsulting\Events\Support\VenueInput;

/**
 * Creates a venue. Its timezone decides how every wall-clock time of its events is read: given,
 * else `events.venues.default_timezone`, else the app's (geocoding never sets it). With `geocode`
 * the coordinates are looked up now; with `events.venues.geocode_on_create` a venue without
 * coordinates is geocoded on the queue after commit.
 */
final readonly class CreateVenueAction
{
    public function __construct(
        private GeocodeVenueAction $geocode,
        private Dispatcher $events,
        private Bus $bus,
    ) {}

    public function execute(VenueData $data): Venue
    {
        KeyTypes::assertMorphable($data->owner);
        EventAuthorization::authorize($data->actor, 'create', EventModels::venue());

        $model = EventModels::venue();
        $venue = new $model;
        $venue->fill([
            'name' => VenueInput::name($data->name),
            'timezone' => VenueInput::timezone($data->timezone),
            'capacity' => VenueInput::capacity($data->capacity),
            'owner_type' => $data->owner?->getMorphClass(),
            'owner_id' => $data->owner?->getKey(),
            'meta' => $data->meta === [] ? null : $data->meta,
        ]);

        VenueInput::applyAddress($venue, $data->address);

        if ($data->coordinates !== null) {
            $venue->coordinates = $data->coordinates;
        }

        $venue->save();
        $this->events->dispatch(new VenueCreated($venue, $data->actor));

        if ($data->geocode) {
            return $this->geocode->execute($venue);
        }

        if ($venue->coordinates === null && EventsConfig::geocodeOnCreate()) {
            $this->bus->dispatch((new GeocodeVenueJob((int) $venue->getKey()))->afterCommit());
        }

        return $venue;
    }
}
