<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Events;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Actions\Internal\AutoPromoteAction;
use RoundlyConsulting\Events\Actions\Internal\CapHoldsAtStartAction;
use RoundlyConsulting\Events\Actions\Internal\ResolveScheduleAction;
use RoundlyConsulting\Events\DataTransferObjects\ResolvedSchedule;
use RoundlyConsulting\Events\DataTransferObjects\ScheduleData;
use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Events\EventUpdated;
use RoundlyConsulting\Events\Exceptions\InvalidEventException;
use RoundlyConsulting\Events\Exceptions\InvalidEventScheduleException;
use RoundlyConsulting\Events\Exceptions\VenueNotFoundException;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Support\EventInput;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\LockedEvent;
use RoundlyConsulting\Events\Support\NotGiven;
use RoundlyConsulting\Events\Support\SeatBook;
use RoundlyConsulting\Events\Support\Timezones;
use RoundlyConsulting\Events\Support\WallClock;

/**
 * Changes an event: only the fields given. A new start on a published event is refused —
 * `reschedule()` keeps the announced date and tells attendees; repeating the current start is
 * not a new one. An end, a doors time or a
 * timezone change (instants kept, local dates recomputed) is an ordinary update; a time, venue
 * or online-link change raises the calendar `sequence`. A higher capacity lets the waitlist move up. Fires `EventUpdated` with the changed attributes, nothing when nothing changed.
 */
final readonly class UpdateEventAction
{
    /** Attributes whose change bumps the iCalendar SEQUENCE. */
    private const array SEQUENCED = ['starts_at', 'ends_at', 'all_day', 'venue_id', 'online_url', 'attendance_mode', 'timezone'];

    public function __construct(
        private ResolveScheduleAction $schedules,
        private AutoPromoteAction $promote,
        private CapHoldsAtStartAction $holds,
        private Dispatcher $events,
    ) {}

    public function execute(Event $event, UpdateEventData $data, ?Model $actor = null, bool $silent = false): Event
    {
        return EventTransaction::run($event, function () use ($event, $data, $actor, $silent): Event {
            LockedEvent::lock($event);

            if ($event->phase !== EventPhase::Draft && $this->movesStart($event, $data)) {
                throw InvalidEventException::useReschedule();
            }

            $this->content($event, $data);
            $this->place($event, $data);
            $this->schedule($event, $data);
            $this->settings($event, $data);

            $changed = array_keys($event->getDirty());

            if ($changed === []) {
                return $event;
            }

            if (array_intersect($changed, self::SEQUENCED) !== [] && $event->phase !== EventPhase::Draft) {
                $event->sequence = $event->sequence + 1;
            }

            // An Eloquent save, never a quiet or query-builder write: lifecycle re-arms the
            // automatic completion when ends_at changes.
            $event->save();

            // A draft's test holds end at its new start too.
            if (in_array('starts_at', $changed, true)) {
                $this->holds->execute(SeatBook::open($event));
            }

            // More room (a higher or no capacity), or automatic promotion switched back on, lets
            // the waitlist move up.
            if (in_array('capacity', $changed, true) || (in_array('waitlist_auto_promote', $changed, true) && $event->waitlist_auto_promote)) {
                $this->promote->execute(SeatBook::open($event));
            }

            $this->events->dispatch(new EventUpdated($event, array_values(array_diff($changed, ['sequence', 'updated_at'])), $actor, $silent));

            return $event;
        });
    }

    private function content(Event $event, UpdateEventData $data): void
    {
        if (! $data->title instanceof NotGiven) {
            $event->setAttribute('title', EventInput::title($data->title));
        }

        if (! $data->description instanceof NotGiven) {
            $description = EventInput::description($data->description);
            $description === null ? $event->forgetAllTranslations('description') : $event->setAttribute('description', $description);
        }

        if (! $data->meta instanceof NotGiven) {
            $event->meta = $data->meta === [] ? null : $data->meta;
        }
    }

    private function place(Event $event, UpdateEventData $data): void
    {
        $venue = $data->venue;
        $url = $data->onlineUrl;

        if (! $venue instanceof NotGiven) {
            $event->venue_id = $this->venue($venue)?->getKey();
        }

        if (! $url instanceof NotGiven) {
            $event->online_url = EventInput::onlineUrl($url);
        }

        if (! $venue instanceof NotGiven || ! $url instanceof NotGiven || ! $data->attendanceMode instanceof NotGiven) {
            $explicit = $data->attendanceMode instanceof NotGiven ? null : $data->attendanceMode;
            $event->attendance_mode = EventInput::attendanceMode($explicit, $event->venue_id !== null, $event->online_url !== null);
        }
    }

    /**
     * Whether the change names a start other than the current one (read in the timezone the
     * change leaves the event in).
     */
    private function movesStart(Event $event, UpdateEventData $data): bool
    {
        if (! $data->movesStart()) {
            return false;
        }

        $timezone = $data->timezone instanceof NotGiven ? $event->timezone : $data->timezone;

        if (! Timezones::isValid($timezone)) {
            throw InvalidEventScheduleException::invalidTimezone($timezone);
        }

        if (! $data->allDayFrom instanceof NotGiven) {
            return ! $event->all_day || trim($data->allDayFrom) !== $event->starts_on;
        }

        return ! $data->startsAt instanceof NotGiven && ! WallClock::toUtc($data->startsAt, $timezone)->equalTo($event->starts_at);
    }

    private function schedule(Event $event, UpdateEventData $data): void
    {
        if (! $data->timezone instanceof NotGiven) {
            if (! Timezones::isValid($data->timezone)) {
                throw InvalidEventScheduleException::invalidTimezone($data->timezone);
            }

            $event->timezone = $data->timezone;
        }

        $timezone = $event->timezone;

        if (! $data->allDayFrom instanceof NotGiven) {
            $through = $data->allDayThrough instanceof NotGiven ? null : $data->allDayThrough;
            $this->apply($event, $this->schedules->execute(new ScheduleData(allDayFrom: $data->allDayFrom, allDayThrough: $through), $timezone));

            return;
        }

        $touched = ! $data->startsAt instanceof NotGiven || ! $data->endsAt instanceof NotGiven
            || ! $data->duration instanceof NotGiven || ! $data->doorsOpenAt instanceof NotGiven;

        if (! $touched) {
            return;
        }

        $starts = $data->startsAt instanceof NotGiven ? $event->starts_at : WallClock::toUtc($data->startsAt, $timezone);
        $ends = match (true) {
            ! $data->endsAt instanceof NotGiven => WallClock::toUtc($data->endsAt, $timezone),
            ! $data->duration instanceof NotGiven => $this->schedules->execute(new ScheduleData(startsAt: $starts, duration: $data->duration), $timezone)->endsAt,
            ! $data->startsAt instanceof NotGiven => $starts->add($event->starts_at->diffAsCarbonInterval($event->ends_at)),
            default => $event->ends_at,
        };
        $doors = match (true) {
            ! $data->doorsOpenAt instanceof NotGiven => $data->doorsOpenAt === null ? null : WallClock::toUtc($data->doorsOpenAt, $timezone),
            default => $event->doors_open_at,
        };

        $this->apply($event, $this->schedules->checked(new ResolvedSchedule($timezone, $starts, $ends, $event->all_day, $event->all_day ? null : $doors)));
    }

    private function settings(Event $event, UpdateEventData $data): void
    {
        $timezone = $event->timezone;

        if (! $data->visibility instanceof NotGiven) {
            $event->visibility = $data->visibility;
        }

        if (! $data->capacity instanceof NotGiven) {
            $event->capacity = EventInput::nonNegative($data->capacity, 'capacity');
        }

        if (! $data->maxGuests instanceof NotGiven) {
            $event->max_guests = (int) EventInput::nonNegative($data->maxGuests, 'max_guests');
        }

        if (! $data->waitlist instanceof NotGiven) {
            $event->waitlist = $data->waitlist;
        }

        if (! $data->waitlistAutoPromote instanceof NotGiven) {
            $event->waitlist_auto_promote = $data->waitlistAutoPromote;
        }

        if (! $data->rsvpOpensAt instanceof NotGiven) {
            $event->rsvp_opens_at = $data->rsvpOpensAt === null ? null : WallClock::toUtc($data->rsvpOpensAt, $timezone);
        }

        if (! $data->rsvpClosesAt instanceof NotGiven) {
            $event->rsvp_closes_at = $data->rsvpClosesAt === null ? null : WallClock::toUtc($data->rsvpClosesAt, $timezone);
        }

        if ($event->rsvp_opens_at !== null && $event->rsvp_closes_at !== null && ! $event->rsvp_opens_at->lessThan($event->rsvp_closes_at)) {
            throw InvalidEventScheduleException::invalidRsvpWindow();
        }

        if (! $data->guestList instanceof NotGiven) {
            $event->guest_list = $data->guestList;
        }

        if (! $data->showCounts instanceof NotGiven) {
            $event->show_counts = $data->showCounts;
        }

        if (! $data->reminders instanceof NotGiven) {
            $event->reminders = EventInput::reminders($data->reminders);
        }
    }

    private function apply(Event $event, ResolvedSchedule $schedule): void
    {
        $event->starts_at = $schedule->startsAt;
        $event->ends_at = $schedule->endsAt;
        $event->doors_open_at = $schedule->doorsOpenAt;
        $event->all_day = $schedule->allDay;
    }

    private function venue(Venue|int|null $venue): ?Venue
    {
        if ($venue === null || $venue instanceof Venue) {
            return $venue;
        }

        return EventModels::venue()::query()->find($venue) ?? throw VenueNotFoundException::missing();
    }
}
