<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Models\Venue;

/**
 * A venue was deleted.
 */
final readonly class VenueDeleted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Venue $venue,
        public ?Model $actor,
    ) {}
}
