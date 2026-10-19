<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Events\EventsManager;
use RoundlyConsulting\Events\Testing\EventsFake;

/**
 * @see EventsManager
 */
final class Events extends Facade
{
    /**
     * Swap the manager for a recording, still-performing fake — behind the facade and in the
     * container, so an injected EventsManager is faked too.
     */
    public static function fake(): EventsFake
    {
        $fake = app(EventsFake::class);

        self::swap($fake);
        app()->instance(EventsManager::class, $fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return EventsManager::class;
    }
}
