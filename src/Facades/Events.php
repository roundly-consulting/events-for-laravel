<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Events\DataTransferObjects\ExampleEventsData;
use RoundlyConsulting\Events\EventsManager;
use RoundlyConsulting\Events\Testing\EventsFake;

/**
 * Keep one `@method static` line per public manager method — FacadeTest's
 * `toDocumentItsRoot()` fails on a missing, stale or miscounted line.
 *
 * @method static string example(ExampleEventsData $data)
 * @method static void assertExampleCalled(\Closure|null $callback = null)
 * @method static void assertNothingCalled()
 *
 * @see EventsManager
 */
final class Events extends Facade
{
    /**
     * Swap the manager for a recording fake — behind the facade and in the container, so an
     * injected EventsManager is faked too.
     */
    public static function fake(): EventsFake
    {
        $fake = app(EventsFake::class);

        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return EventsManager::class;
    }
}
