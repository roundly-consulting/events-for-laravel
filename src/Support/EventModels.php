<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use RoundlyConsulting\Events\Models\AdmissionTier;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;

/**
 * The model seam: the only place that reads `events.models.*`. A host swaps a model by extending
 * the packaged class and naming the subclass in config; every query, relation, factory and
 * listener resolves the class through here.
 */
final class EventModels
{
    /** @return class-string<Event> */
    public static function event(): string
    {
        return ModelResolver::for('events.models.event', Event::class);
    }

    /** @return class-string<Venue> */
    public static function venue(): string
    {
        return ModelResolver::for('events.models.venue', Venue::class);
    }

    /** @return class-string<AdmissionTier> */
    public static function tier(): string
    {
        return ModelResolver::for('events.models.tier', AdmissionTier::class);
    }

    /** @return class-string<Rsvp> */
    public static function rsvp(): string
    {
        return ModelResolver::for('events.models.rsvp', Rsvp::class);
    }
}
