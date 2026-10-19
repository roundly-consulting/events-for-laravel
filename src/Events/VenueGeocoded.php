<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Events\Models\Venue;

/**
 * A venue got coordinates from geocoding.
 */
final readonly class VenueGeocoded implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Venue $venue,
    ) {}
}
