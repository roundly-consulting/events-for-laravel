<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Venues;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Events\VenueGeocoded;
use RoundlyConsulting\Events\Events\VenueGeocodingFailed;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Support\Clock;
use RoundlyConsulting\Events\Support\EventAuthorization;
use RoundlyConsulting\Geolocation\Enum\GeolocationType;
use RoundlyConsulting\Geolocation\GeolocationManager;

/**
 * Looks a venue's address up through your geolocation pipeline and stores the coordinates — only
 * when they are missing, unless forced. The timezone is never touched (geocoding returns none).
 *
 * A failure keeps the venue as it was and fires `VenueGeocodingFailed`: no result, every provider
 * unavailable, or the pipeline's trailing *default* location — the host's configured fallback,
 * not the address, which would put every venue on the same spot. A rate limit propagates (the
 * queued job retries); configuration errors propagate.
 */
final readonly class GeocodeVenueAction
{
    public function __construct(
        private GeolocationManager $geolocation,
        private Dispatcher $events,
    ) {}

    public function execute(Venue $venue, bool $force = false, ?Model $actor = null): Venue
    {
        EventAuthorization::authorize($actor, 'geocode', $venue);

        if ($venue->coordinates !== null && ! $force) {
            return $venue;
        }

        $address = $venue->formattedAddress();
        $location = $address === '' ? null : $this->geolocation->locateAddress($address);

        if ($location === null || $location->type === GeolocationType::Default) {
            $this->events->dispatch(new VenueGeocodingFailed($venue));

            return $venue;
        }

        $venue->coordinates = $location->coordinates();
        $venue->geocoded_at = Clock::now();
        $venue->save();

        $this->events->dispatch(new VenueGeocoded($venue));

        return $venue;
    }
}
