<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events;

use Illuminate\Contracts\Container\Container;

/**
 * The package's public API: the root behind the `Events` facade, injectable by its own
 * class-string (a container singleton). Every method is thin — the behaviour lives in the
 * actions, each resolved through the container on every call, so host overrides and
 * `Events::fake()` apply.
 *
 * Not final on purpose: EventsFake extends it, so an injected manager still type-checks under
 * `Events::fake()`.
 */
class EventsManager
{
    public function __construct(
        protected readonly Container $container,
    ) {}
}
