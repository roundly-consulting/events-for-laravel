<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Tests\Fixtures\Lifecycles;

use RoundlyConsulting\Events\Contracts\EventLifecycle;
use RoundlyConsulting\Events\Enums\EventVerb;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition;

/**
 * draft → submitted → approved (community approval), then called off or done. No postpone,
 * reschedule or unpublish: those verbs map to null.
 */
final class CustomLifecycle extends LifecycleDefinition implements EventLifecycle
{
    public function define(LifecycleBuilder $lifecycle): void
    {
        $lifecycle->states(CustomStatus::class)
            ->initial(CustomStatus::Draft)
            ->terminal(CustomStatus::Called, CustomStatus::Done);

        $lifecycle->state(CustomStatus::Approved)->stamps('published_at')->expiresAtAttribute('ends_at')->expiresVia('finish');

        $lifecycle->transition('submit')->from(CustomStatus::Draft)->to(CustomStatus::Submitted)->allowSystem();
        $lifecycle->transition('approve')->from(CustomStatus::Submitted)->to(CustomStatus::Approved)->allowSystem();
        $lifecycle->transition('call_off')->from(CustomStatus::Approved)->to(CustomStatus::Called)->allowSystem();
        $lifecycle->transition('finish')->from(CustomStatus::Approved)->to(CustomStatus::Done)->allowSystem();
    }

    public function transitionFor(EventVerb $verb): ?string
    {
        return match ($verb) {
            EventVerb::Publish => 'approve',
            EventVerb::Cancel => 'call_off',
            EventVerb::Complete => 'finish',
            default => null,
        };
    }
}
