<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RoundlyConsulting\Events\EventsManager;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Geolocation\Exceptions\RateLimitExceededException;

/**
 * Geocodes one venue on the queue (`events.venues.geocode_on_create`, `events:geocode`). A rate
 * limit releases the job for a retry; a deleted venue is skipped.
 */
final class GeocodeVenueJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public readonly int $venueId,
        public readonly bool $force = false,
    ) {}

    public function handle(EventsManager $events): void
    {
        $venue = EventModels::venue()::query()->find($this->venueId);

        if ($venue === null) {
            return;
        }

        try {
            $events->venues()->geocode($venue, $this->force);
        } catch (RateLimitExceededException $exception) {
            $this->release(max(1, $exception->retryAfterSeconds()));
        }
    }
}
