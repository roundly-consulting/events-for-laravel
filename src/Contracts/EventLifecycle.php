<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Contracts;

use RoundlyConsulting\Events\Enums\EventVerb;

/**
 * Implemented by an event lifecycle definition (a lifecycle-for-laravel `LifecycleDefinition`).
 * It names the transition behind each of the package's verbs — `publish()`, `postpone()`,
 * `reschedule()`, `cancel()`, `complete()`, `unpublish()` — or null when the lifecycle has none
 * (calling that verb then throws `UnknownEventTransitionException`).
 */
interface EventLifecycle
{
    public function transitionFor(EventVerb $verb): ?string;
}
