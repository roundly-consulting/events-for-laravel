<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Tests\Fixtures;

use Illuminate\Foundation\Application;
use RoundlyConsulting\Events\Tests\Fixtures\Models\CustomEvent;
use RoundlyConsulting\Events\Tests\Fixtures\Models\CustomRsvp;
use RoundlyConsulting\Events\Tests\Fixtures\Models\CustomTier;
use RoundlyConsulting\Events\Tests\Fixtures\Models\CustomVenue;
use RoundlyConsulting\Events\Tests\TestCase;

/**
 * Host subclasses named in `events.models.*` before the providers boot — the only window in which
 * a swap is real.
 */
abstract class SwappedModelsTestCase extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $this->swapModel('events.models.event', CustomEvent::class);
        $this->swapModel('events.models.venue', CustomVenue::class);
        $this->swapModel('events.models.tier', CustomTier::class);
        $this->swapModel('events.models.rsvp', CustomRsvp::class);

        parent::defineEnvironment($app);
    }
}
