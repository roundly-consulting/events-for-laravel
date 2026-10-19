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
 * @method static void assertPerformed(\RoundlyConsulting\Events\Enums\EventOperation $operation, \Closure|null $matches = null)
 * @method static void assertNotPerformed(\RoundlyConsulting\Events\Enums\EventOperation $operation)
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
 * @method static void assertDeleted(\RoundlyConsulting\Events\Models\Event|null $event = null)
 * @method static void assertNothingDeleted()
 * @method static void assertRestored(\RoundlyConsulting\Events\Models\Event|null $event = null)
 * @method static void assertNothingRestored()
 * @method static void assertUnpublished(\RoundlyConsulting\Events\Models\Event|null $event = null)
 * @method static void assertNothingUnpublished()
 * @method static void assertTransitioned(\RoundlyConsulting\Events\Models\Event|null $event = null, string|null $name = null)
 * @method static void assertNothingTransitioned()
 * @method static void assertMovedOnline(\RoundlyConsulting\Events\Models\Event|null $event = null)
 * @method static void assertNothingMovedOnline()
 * @method static void assertTransferred(\RoundlyConsulting\Events\Models\Event|null $event = null, \Illuminate\Database\Eloquent\Model|null $to = null)
 * @method static void assertNothingTransferred()
 * @method static void assertPosterChanged(\RoundlyConsulting\Events\Models\Event|null $event = null, bool|null $removed = null)
 * @method static void assertNoPosterChanged()
 * @method static void assertCategorized(\RoundlyConsulting\Events\Models\Event|null $event = null, \RoundlyConsulting\Tags\Models\Tag|string|int|null $category = null)
 * @method static void assertNothingCategorized()
 * @method static void assertLabelled(\RoundlyConsulting\Events\Models\Event|null $event = null, list<\RoundlyConsulting\Tags\Models\Tag|int|string>|null $labels = null)
 * @method static void assertNothingLabelled()
 * @method static void assertUnlabelled(\RoundlyConsulting\Events\Models\Event|null $event = null, list<\RoundlyConsulting\Tags\Models\Tag|int|string>|null $labels = null)
 * @method static void assertNothingUnlabelled()
 * @method static void assertVenueCreated(\Closure|null $callback = null)
 * @method static void assertNoVenueCreated()
 * @method static void assertVenueUpdated(\Closure|null $callback = null)
 * @method static void assertNoVenueUpdated()
 * @method static void assertVenueDeleted(\Closure|null $callback = null)
 * @method static void assertNoVenueDeleted()
 * @method static void assertVenueRestored(\Closure|null $callback = null)
 * @method static void assertNoVenueRestored()
 * @method static void assertVenueGeocoded(\Closure|null $callback = null)
 * @method static void assertNoVenueGeocoded()
 * @method static void assertTierCreated(\Closure|null $callback = null)
 * @method static void assertNoTierCreated()
 * @method static void assertTierUpdated(\Closure|null $callback = null)
 * @method static void assertNoTierUpdated()
 * @method static void assertTierDeleted(\Closure|null $callback = null)
 * @method static void assertNoTierDeleted()
 * @method static void assertTierRestored(\Closure|null $callback = null)
 * @method static void assertNoTierRestored()
 * @method static void assertTiersReordered(\RoundlyConsulting\Events\Models\Event|null $event = null)
 * @method static void assertNoTiersReordered()
 * @method static void assertRsvped(\RoundlyConsulting\Events\Models\Event|null $event = null, \Illuminate\Database\Eloquent\Model|null $attendee = null, \RoundlyConsulting\Events\Enums\RsvpStatus|null $status = null)
 * @method static void assertNothingRsvped()
 * @method static void assertAttendeeAdded(\RoundlyConsulting\Events\Models\Event|null $event = null, \Illuminate\Database\Eloquent\Model|null $attendee = null)
 * @method static void assertNoAttendeeAdded()
 * @method static void assertAttendeeRemoved(\RoundlyConsulting\Events\Models\Event|null $event = null, \Illuminate\Database\Eloquent\Model|null $attendee = null)
 * @method static void assertNoAttendeeRemoved()
 * @method static void assertTierChanged(\RoundlyConsulting\Events\Models\Event|null $event = null)
 * @method static void assertNoTierChanged()
 * @method static void assertGuestsChanged(\RoundlyConsulting\Events\Models\Event|null $event = null)
 * @method static void assertNoGuestsChanged()
 * @method static void assertRsvpConfirmed(\RoundlyConsulting\Events\Models\Event|null $event = null)
 * @method static void assertNoRsvpConfirmed()
 * @method static void assertHoldReleased(\RoundlyConsulting\Events\Models\Event|null $event = null)
 * @method static void assertNoHoldReleased()
 * @method static void assertPaymentRequested(\RoundlyConsulting\Events\Models\Event|null $event = null)
 * @method static void assertNoPaymentRequested()
 * @method static void assertCodeRotated(\RoundlyConsulting\Events\Models\Event|null $event = null)
 * @method static void assertNoCodeRotated()
 * @method static void assertAttendeeForgotten(\Illuminate\Database\Eloquent\Model|null $attendee = null)
 * @method static void assertNoAttendeeForgotten()
 * @method static void assertPromoted(\RoundlyConsulting\Events\Models\Event|null $event = null)
 * @method static void assertNothingPromoted()
 * @method static void assertPaymentConfirmed(string|null $reference = null)
 * @method static void assertNoPaymentConfirmed()
 * @method static void assertCheckedIn(\RoundlyConsulting\Events\Models\Event|null $event = null, \Illuminate\Database\Eloquent\Model|null $attendee = null)
 * @method static void assertNothingCheckedIn()
 * @method static void assertCheckInUndone(\RoundlyConsulting\Events\Models\Event|null $event = null)
 * @method static void assertNoCheckInUndone()
 * @method static void assertInvited(\RoundlyConsulting\Events\Models\Event|null $event = null, \Illuminate\Database\Eloquent\Model|null $invitee = null)
 * @method static void assertNothingInvited()
 * @method static void assertCoHostAdded(\RoundlyConsulting\Events\Models\Event|null $event = null, \Illuminate\Database\Eloquent\Model|null $host = null)
 * @method static void assertNoCoHostAdded()
 * @method static void assertCoHostRemoved(\RoundlyConsulting\Events\Models\Event|null $event = null, \Illuminate\Database\Eloquent\Model|null $host = null)
 * @method static void assertNoCoHostRemoved()
 * @method static void assertInvitationRevoked(\RoundlyConsulting\Events\Models\Event|null $event = null, \Illuminate\Database\Eloquent\Model|null $invitee = null)
 * @method static void assertNoInvitationRevoked()
 * @method static void assertInvitationAnswered(\RoundlyConsulting\Events\Models\Event|null $event = null, \RoundlyConsulting\Events\Enums\RsvpResponse|null $response = null)
 * @method static void assertNoInvitationAnswered()
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
