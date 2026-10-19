<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Tests\Fixtures\Lifecycles;

use RoundlyConsulting\Events\Contracts\EventLifecycle;
use RoundlyConsulting\Events\Enums\EventVerb;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition;

/**
 * A lifecycle whose states are not event states.
 */
final class BrokenLifecycle extends LifecycleDefinition implements EventLifecycle
{
    public function define(LifecycleBuilder $lifecycle): void
    {
        $lifecycle->states(PlainStatus::class)->initial(PlainStatus::Open)->terminal(PlainStatus::Closed);
        $lifecycle->transition('close')->from(PlainStatus::Open)->to(PlainStatus::Closed)->allowSystem();
    }

    public function transitionFor(EventVerb $verb): ?string
    {
        return $verb === EventVerb::Cancel ? 'close' : null;
    }
}
