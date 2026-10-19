<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Events\EventsManager;
use RoundlyConsulting\Events\Testing\EventsFake;

/**
 * Real-world events: venues, events, ticket types, RSVPs, check-in, invitations.
 *
 * @method static \RoundlyConsulting\Events\Builders\EventBuilder make(array<string, string>|string $title)
 * @method static \RoundlyConsulting\Events\Models\Event create(\RoundlyConsulting\Events\DataTransferObjects\CreateEventData $data)
 * @method static \RoundlyConsulting\Events\Models\Event|null find(string $uuid)
 * @method static \RoundlyConsulting\Events\Models\Event findOrFail(string $uuid)
 * @method static \RoundlyConsulting\Events\Models\Event|null findBySlug(string $slug, \Illuminate\Database\Eloquent\Model|null $viewer = null)
 * @method static \RoundlyConsulting\Events\Handles\EventHandle for(\RoundlyConsulting\Events\Models\Event|string $event)
 * @method static \Illuminate\Database\Eloquent\Builder<\RoundlyConsulting\Events\Models\Event> browse(\Illuminate\Database\Eloquent\Model|null $viewer = null)
 * @method static \Illuminate\Database\Eloquent\Builder<\RoundlyConsulting\Events\Models\Event> visibleTo(\Illuminate\Database\Eloquent\Model|null $viewer)
 * @method static \RoundlyConsulting\Events\Accessors\Venues venues()
 * @method static list<\RoundlyConsulting\Events\Testing\RecordedEventOperation> recorded(\RoundlyConsulting\Events\Enums\EventOperation|null $operation = null)
 * @method static void assertNothingHappened()
 * @method static void assertCreated(\Closure|null $callback = null)
 * @method static void assertNothingCreated()
 * @method static void assertUpdated(\RoundlyConsulting\Events\Models\Event|null $event = null, list<string>|null $keys = null)
 * @method static void assertNothingUpdated()
 * @method static void assertPublished(\RoundlyConsulting\Events\Models\Event|null $event = null)
 * @method static void assertNothingPublished()
 * @method static void assertPostponed(\RoundlyConsulting\Events\Models\Event|null $event = null)
 * @method static void assertNothingPostponed()
 * @method static void assertRescheduled(\RoundlyConsulting\Events\Models\Event|null $event = null)
 * @method static void assertNothingRescheduled()
 * @method static void assertCancelled(\RoundlyConsulting\Events\Models\Event|null $event = null)
 * @method static void assertNothingCancelled()
 * @method static void assertCompleted(\RoundlyConsulting\Events\Models\Event|null $event = null)
 * @method static void assertNothingCompleted()
 * @method static void assertVenueCreated(\Closure|null $callback = null)
 * @method static void assertNoVenueCreated()
 *
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
