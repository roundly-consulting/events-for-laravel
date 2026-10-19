<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Tests\Fixtures\Lifecycles;

use RoundlyConsulting\Events\Contracts\EventLifecycle;
use RoundlyConsulting\Events\Enums\EventStatus;
use RoundlyConsulting\Events\Enums\EventVerb;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition;

/**
 * Expires through a transition that does not allow the system: lifecycle refuses to compile it.
 */
final class UncompilableLifecycle extends LifecycleDefinition implements EventLifecycle
{
    public function define(LifecycleBuilder $lifecycle): void
    {
        $lifecycle->states(EventStatus::class)->initial(EventStatus::Draft)->terminal(EventStatus::Completed);
        $lifecycle->state(EventStatus::Draft)->expiresAtAttribute('ends_at')->expiresVia('finish');
        $lifecycle->transition('finish')->from(EventStatus::Draft)->to(EventStatus::Completed);
    }

    public function transitionFor(EventVerb $verb): ?string
    {
        return null;
    }
}
