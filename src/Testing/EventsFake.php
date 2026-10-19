<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Testing;

use Illuminate\Contracts\Container\Container;
use RoundlyConsulting\Events\EventsManager;

/**
 * A recording, still-performing manager, installed by `Events::fake()`.
 */
final class EventsFake extends EventsManager
{
    public function __construct(Container $container)
    {
        parent::__construct($container);
    }
}
