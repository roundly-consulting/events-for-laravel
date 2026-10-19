<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Tests\Fixtures\Models;

use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Tags\Concerns\HasTags;
use RoundlyConsulting\Tags\Contracts\Taggable;

/**
 * A host's venue subclass that takes labels too — the `Tags::extend()` widening recipe.
 */
final class TaggableVenue extends Venue implements Taggable
{
    use HasTags;
}
