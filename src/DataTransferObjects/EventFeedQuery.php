<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Tags\Models\Tag;

/**
 * What a public calendar feed lists: optionally one category (its sub-categories included), one
 * label, one organizer or one venue, under a calendar name.
 */
final readonly class EventFeedQuery
{
    public function __construct(
        public Tag|string|null $category = null,
        public Tag|string|null $label = null,
        public ?Model $organizer = null,
        public Venue|int|null $venue = null,
        public ?string $name = null,
    ) {}
}
