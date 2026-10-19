<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Tests\Fixtures\Lifecycles;

use RoundlyConsulting\Events\Contracts\EventLifecycle;
use RoundlyConsulting\Events\Enums\EventStatus;
use RoundlyConsulting\Events\Enums\EventVerb;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition;

/**
 * Starts announced — the package needs a Draft first.
 */
final class ScheduledFirstLifecycle extends LifecycleDefinition implements EventLifecycle
{
    public function define(LifecycleBuilder $lifecycle): void
    {
        $lifecycle->states(EventStatus::class)->initial(EventStatus::Scheduled)->terminal(EventStatus::Cancelled);
        $lifecycle->transition('cancel')->from(EventStatus::Scheduled)->to(EventStatus::Cancelled)->allowSystem();
    }

    public function transitionFor(EventVerb $verb): ?string
    {
        return $verb === EventVerb::Cancel ? 'cancel' : null;
    }
}
