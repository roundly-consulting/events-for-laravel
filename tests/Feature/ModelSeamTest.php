<?php

declare(strict_types=1);

use RoundlyConsulting\Events\Models\AdmissionTier;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Models\Venue;

it('advertises a seam for each swappable model', function (): void {
    expect(Event::class)->toBeSwappableVia('events.models.event')
        ->and(Venue::class)->toBeSwappableVia('events.models.venue')
        ->and(AdmissionTier::class)->toBeSwappableVia('events.models.tier')
        ->and(Rsvp::class)->toBeSwappableVia('events.models.rsvp');
});
