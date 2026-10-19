<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Tests\Fixtures\Models;

use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

/**
 * A host subclass named in `events.models.*` — the swap is real only if the package creates it.
 */
class CustomEvent extends Event
{
    use CountsCreations;
}
