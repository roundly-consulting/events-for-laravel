<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Events;

use Illuminate\Contracts\Events\Dispatcher;
use RoundlyConsulting\Events\Actions\Internal\ResolveScheduleAction;
use RoundlyConsulting\Events\Actions\Internal\WriteCoHostAction;
use RoundlyConsulting\Events\Actions\Tiers\CreateTierAction;
use RoundlyConsulting\Events\DataTransferObjects\CreateEventData;
use RoundlyConsulting\Events\Events\EventCreated;
use RoundlyConsulting\Events\Exceptions\InvalidEventException;
use RoundlyConsulting\Events\Exceptions\InvalidEventScheduleException;
use RoundlyConsulting\Events\Exceptions\InvalidOrganizerException;
use RoundlyConsulting\Events\Exceptions\VenueNotFoundException;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Support\EventAuthorization;
use RoundlyConsulting\Events\Support\EventInput;
use RoundlyConsulting\Events\Support\EventManagers;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventsConfig;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\WallClock;
use RoundlyConsulting\Sluggable\Exceptions\SlugAlreadyTakenException;

/**
 * Creates an event as a draft: validates the content, resolves the schedule in the event's
 * timezone (explicit, else the venue's, else `events.defaults.timezone`, else the app's), derives
 * the attendance mode, and writes the event with its co-hosts, ticket types and poster in one
 * transaction.
 *
 * With an actor, the policy's `create` ability is checked, and organizing as anyone but the actor
 * needs the organizer's approval (`Organizer::allowsEventManagementBy($actor)`).
 */
final readonly class CreateEventAction
{
    public function __construct(
        private ResolveScheduleAction $schedules,
        private WriteCoHostAction $coHosts,
        private CreateTierAction $tiers,
        private SetPosterAction $posters,
        private Dispatcher $events,
    ) {}

    public function execute(CreateEventData $data): Event
    {
        $organizer = EventInput::organizer($data->organizer);
        $actor = $data->actor;

        if ($actor !== null) {
            EventAuthorization::authorize($actor, 'create', EventModels::event());

            if (! EventManagers::modelAllows($organizer, $actor)) {
                throw InvalidOrganizerException::notAllowed();
            }
        }

        $title = EventInput::title($data->title);
        $description = EventInput::description($data->description);
        $venue = $this->venue($data->venue);
        $onlineUrl = EventInput::onlineUrl($data->onlineUrl);
        $timezone = $data->schedule->timezone ?? ($venue instanceof Venue ? $venue->timezone : null) ?? EventsConfig::defaultTimezone();
        $schedule = $this->schedules->execute($data->schedule, $timezone);

        $rsvpOpensAt = $data->rsvpOpensAt === null ? null : WallClock::toUtc($data->rsvpOpensAt, $timezone);
        $rsvpClosesAt = $data->rsvpClosesAt === null ? null : WallClock::toUtc($data->rsvpClosesAt, $timezone);

        if ($rsvpOpensAt !== null && $rsvpClosesAt !== null && ! $rsvpOpensAt->lessThan($rsvpClosesAt)) {
            throw InvalidEventScheduleException::invalidRsvpWindow();
        }

        $attributes = [
            'title' => $title,
            'description' => $description,
            'organizer_type' => $organizer->getMorphClass(),
            'organizer_id' => $organizer->getKey(),
            'venue_id' => $venue?->getKey(),
            'online_url' => $onlineUrl,
            'attendance_mode' => EventInput::attendanceMode($data->attendanceMode, $venue !== null, $onlineUrl !== null),
            'visibility' => $data->visibility ?? EventsConfig::defaultVisibility(),
            'timezone' => $schedule->timezone,
            'starts_at' => $schedule->startsAt,
            'ends_at' => $schedule->endsAt,
            'doors_open_at' => $schedule->doorsOpenAt,
            'all_day' => $schedule->allDay,
            'capacity' => EventInput::nonNegative($data->capacity, 'capacity'),
            'max_guests' => EventInput::nonNegative($data->maxGuests, 'max_guests') ?? EventsConfig::defaultMaxGuests(),
            'waitlist' => $data->waitlist ?? EventsConfig::defaultWaitlist(),
            'waitlist_auto_promote' => $data->waitlistAutoPromote ?? true,
            'rsvp_opens_at' => $rsvpOpensAt,
            'rsvp_closes_at' => $rsvpClosesAt,
            'guest_list' => $data->guestList ?? EventsConfig::defaultGuestList(),
            'show_counts' => $data->showCounts ?? EventsConfig::defaultShowCounts(),
            'reminders' => EventInput::reminders($data->reminders),
            'meta' => $data->meta === [] ? null : $data->meta,
        ];

        foreach ($data->coHosts as $host) {
            EventInput::organizer($host);
        }

        $model = EventModels::event();

        return EventTransaction::run(new $model, function () use ($model, $attributes, $data, $actor): Event {
            $event = new $model;
            $event->fill($attributes);

            if ($data->slug !== null && $data->slug !== []) {
                $event->setAttribute('slug', $data->slug);
            }

            try {
                $event->save();
            } catch (SlugAlreadyTakenException) {
                throw InvalidEventException::slugTaken(implode(', ', $data->slug ?? []));
            }

            foreach ($data->coHosts as $host) {
                $this->coHosts->execute($event, $host, $actor);
            }

            foreach ($data->tiers as $tier) {
                $this->tiers->execute($event, $tier, $actor);
            }

            if ($data->poster !== null || $data->posterDraft !== null) {
                $this->posters->execute($event, $data->poster, $data->posterDraft, $actor);
            }

            $this->events->dispatch(new EventCreated($event, $actor));

            return $event;
        });
    }

    private function venue(Venue|int|null $venue): ?Venue
    {
        if ($venue === null || $venue instanceof Venue) {
            return $venue;
        }

        return EventModels::venue()::query()->find($venue) ?? throw VenueNotFoundException::missing();
    }
}
