<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Internal;

use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Support\EventModels;

/**
 * A venue's name, address or coordinates are part of every one of its events' calendar entries
 * (`LOCATION`, `GEO`): when they change, the announced events that are not over raise their
 * `sequence` (and `updated_at`), so subscribed calendars take the new place. Drafts announced
 * nothing; finished events stay as they were. Returns how many events moved on.
 *
 * @internal
 */
final readonly class ResequenceVenueEventsAction
{
    /** The venue attributes a calendar entry shows. */
    public const array CALENDAR = ['name', 'street', 'city', 'region', 'postal_code', 'country_code', 'formatted_address', 'latitude', 'longitude'];

    /**
     * @param  list<string>  $changed  the venue attributes that changed
     */
    public function execute(Venue $venue, array $changed): int
    {
        if (array_intersect($changed, self::CALENDAR) === []) {
            return 0;
        }

        return EventModels::event()::query()
            ->where('venue_id', $venue->getKey())
            ->inPhase(EventPhase::Scheduled, EventPhase::Postponed, EventPhase::Rescheduled)
            ->increment('sequence');
    }
}
