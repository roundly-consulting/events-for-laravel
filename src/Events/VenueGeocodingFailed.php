<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Events\Models\Venue;

/**
 * Geocoding found no usable location for a venue; its coordinates stay empty.
 */
final readonly class VenueGeocodingFailed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Venue $venue,
    ) {}
}
