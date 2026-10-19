<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Events\EventsManager;
use RoundlyConsulting\Events\Testing\EventsFake;
use RoundlyConsulting\PackageToolkit\Concerns\RedactsSensitiveArguments;

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
 * @method static \RoundlyConsulting\Tags\Handles\TypeTags categories()
 * @method static \RoundlyConsulting\Tags\Handles\TypeTags labels()
 * @method static \RoundlyConsulting\Events\Accessors\Rsvps rsvps()
 * @method static \RoundlyConsulting\Events\Accessors\Invitations invitations()
 * @method static \RoundlyConsulting\Events\DataTransferObjects\CheckInResult checkIn(string $code, \Illuminate\Database\Eloquent\Model $by, int|null $guests = null, bool $ignoreWindow = false)
 * @method static \RoundlyConsulting\Events\Calendar\IcsCalendar ics(iterable<\RoundlyConsulting\Events\Models\Event> $events, \Illuminate\Database\Eloquent\Model|null $viewer = null)
 * @method static \RoundlyConsulting\Events\Calendar\Feeds feeds()
 * @method static array<string, mixed>|null jsonLd(\RoundlyConsulting\Events\Models\Event $event)
 * @method static \RoundlyConsulting\Events\DataTransferObjects\SweepResult sweep(\RoundlyConsulting\Events\DataTransferObjects\SweepOptions|null $options = null)
 * @method static \RoundlyConsulting\Events\DataTransferObjects\RepairReport repair(\RoundlyConsulting\Events\DataTransferObjects\RepairOptions|null $options = null)
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
 * @method static void assertCategorized(\RoundlyConsulting\Events\Models\Event|null $event = null, \RoundlyConsulting\Tags\Models\Tag|string|int|null $category = null)
 * @method static void assertNothingCategorized()
 * @method static void assertLabelled(\RoundlyConsulting\Events\Models\Event|null $event = null, list<\RoundlyConsulting\Tags\Models\Tag|int|string>|null $labels = null)
 * @method static void assertNothingLabelled()
 * @method static void assertVenueCreated(\Closure|null $callback = null)
 * @method static void assertNoVenueCreated()
 * @method static void assertTierCreated(\Closure|null $callback = null)
 * @method static void assertNoTierCreated()
 * @method static void assertRsvped(\RoundlyConsulting\Events\Models\Event|null $event = null, \Illuminate\Database\Eloquent\Model|null $attendee = null, \RoundlyConsulting\Events\Enums\RsvpStatus|null $status = null)
 * @method static void assertNothingRsvped()
 * @method static void assertPromoted(\RoundlyConsulting\Events\Models\Event|null $event = null)
 * @method static void assertNothingPromoted()
 * @method static void assertPaymentConfirmed(string|null $reference = null)
 * @method static void assertNoPaymentConfirmed()
 * @method static void assertCheckedIn(\RoundlyConsulting\Events\Models\Event|null $event = null, \Illuminate\Database\Eloquent\Model|null $attendee = null)
 * @method static void assertNothingCheckedIn()
 * @method static void assertInvited(\RoundlyConsulting\Events\Models\Event|null $event = null, \Illuminate\Database\Eloquent\Model|null $invitee = null)
 * @method static void assertNothingInvited()
 * @method static void assertCoHostAdded(\RoundlyConsulting\Events\Models\Event|null $event = null, \Illuminate\Database\Eloquent\Model|null $host = null)
 * @method static void assertNoCoHostAdded()
 * @method static void assertSwept()
 * @method static void assertNothingSwept()
 * @method static void assertRepaired()
 * @method static void assertNothingRepaired()
 *
 * @see EventsManager
 */
final class Events extends Facade
{
    // Check-in codes passed to Events::checkIn() never show in a stack trace's facade frame.
    use RedactsSensitiveArguments;

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
