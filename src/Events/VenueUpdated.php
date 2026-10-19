<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Models\Venue;

/**
 * A venue changed. `changed` lists the attributes that did.
 */
final readonly class VenueUpdated implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<string>  $changed
     */
    public function __construct(
        public Venue $venue,
        public array $changed,
        public ?Model $actor,
    ) {}
}
