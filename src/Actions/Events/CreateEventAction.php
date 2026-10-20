<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Events;

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use RoundlyConsulting\Events\Actions\Internal\ResolveScheduleAction;
use RoundlyConsulting\Events\Actions\Internal\WriteCoHostAction;
use RoundlyConsulting\Events\Actions\Tiers\CreateTierAction;
use RoundlyConsulting\Events\DataTransferObjects\CreateEventData;
use RoundlyConsulting\Events\Enums\EventOperation;
use RoundlyConsulting\Events\Enums\EventTagType;
use RoundlyConsulting\Events\Events\EventCreated;
use RoundlyConsulting\Events\EventsManager;
use RoundlyConsulting\Events\Exceptions\InvalidEventException;
use RoundlyConsulting\Events\Exceptions\InvalidEventScheduleException;
use RoundlyConsulting\Events\Exceptions\InvalidOrganizerException;
use RoundlyConsulting\Events\Exceptions\VenueNotFoundException;
use RoundlyConsulting\Events\Models\AdmissionTier;
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
use RoundlyConsulting\Tags\DataTransferObjects\TagChanges;
use RoundlyConsulting\Tags\TagsManager;

/**
 * Creates an event as a draft: validates the content, resolves the schedule in the event's
 * timezone (explicit, else the venue's, else `events.defaults.timezone`, else the app's), derives
 * the attendance mode, and writes the event with its co-hosts, ticket types and poster in one
 * transaction, with its category and labels. Each of those is its own operation on the manager
 * (`AddCoHost`, `CreateTier`, `SetPoster`, `Categorize`, `AttachLabels`), so `Events::fake()`
 * records a builder's parts as it records the same calls on a handle.
 *
 * With an actor, the policy's `create` ability is checked, and organizing as anyone but the actor
 * needs the organizer's approval (`Organizer::allowsEventManagementBy($actor)`).
 */
final readonly class CreateEventAction
{
    public function __construct(
        private ResolveScheduleAction $schedules,
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

            $manager = Container::getInstance()->make(EventsManager::class);
            $context = ['event' => $event, 'actor' => $actor, 'system' => false, 'silent' => false];

            foreach ($data->coHosts as $host) {
                $manager->perform(
                    EventOperation::AddCoHost,
                    WriteCoHostAction::class,
                    static fn (WriteCoHostAction $action): bool => $action->execute($event, $host, $actor),
                    [...$context, 'host' => $host],
                );
            }

            foreach ($data->tiers as $tier) {
                $manager->perform(
                    EventOperation::CreateTier,
                    CreateTierAction::class,
                    static fn (CreateTierAction $action): AdmissionTier => $action->execute($event, $tier, $actor),
                    [...$context, 'data' => $tier],
                );
            }

            if ($data->poster !== null || $data->posterDraft !== null) {
                $manager->perform(
                    EventOperation::SetPoster,
                    SetPosterAction::class,
                    static fn (SetPosterAction $action): Event => $action->execute($event, $data->poster, $data->posterDraft, $actor),
                    [...$context, 'removed' => false],
                );
            }

            // The new row is this transaction's own, so tags' taggable lock comes after it.
            if ($data->category !== null) {
                $category = $data->category;
                $manager->perform(
                    EventOperation::Categorize,
                    TagsManager::class,
                    static fn (TagsManager $tags): TagChanges => $tags->sync($event, $category, EventTagType::Category),
                    [...$context, 'category' => $category],
                );
            }

            if ($data->labels !== []) {
                $manager->perform(
                    EventOperation::AttachLabels,
                    TagsManager::class,
                    static fn (TagsManager $tags): TagChanges => $tags->attach($event, $data->labels, EventTagType::Label),
                    [...$context, 'labels' => $data->labels],
                );
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
