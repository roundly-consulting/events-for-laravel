<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Tests\Fixtures\Models;

use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

/**
 * A host subclass named in `events.models.*` — the swap is real only if the package creates it.
 */
class CustomRsvp extends Rsvp
{
    use CountsCreations;
}
