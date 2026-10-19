<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Events;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Actions\Internal\ResolveScheduleAction;
use RoundlyConsulting\Events\DataTransferObjects\ResolvedSchedule;
use RoundlyConsulting\Events\DataTransferObjects\ScheduleData;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Enums\EventVerb;
use RoundlyConsulting\Events\Events\EventUpdated;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Support\EventStates;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\EventTransitions;
use RoundlyConsulting\Events\Support\LockedEvent;

/**
 * Gives an event a new date. Times are read in the event's timezone (an instant stays an
 * instant). Without a new end the event keeps its length.
 *
 * Atomic: under the event's lock it writes the new times, keeps the originally announced start
 * in `previous_starts_at` (the first reschedule only, per schema.org), then applies the lifecycle's reschedule transition in the same transaction — a
 * denial rolls the times back. Every write of `ends_at` is an Eloquent save, so the automatic
 * completion follows it.
 *
 * A draft has announced nothing: it is just edited, no transition is applied.
 */
final readonly class RescheduleEventAction
{
    public function __construct(
        private ResolveScheduleAction $schedules,
        private Dispatcher $events,
    ) {}

    public function execute(
        Event $event,
        CarbonInterface|string $startsAt,
        CarbonInterface|string|null $endsAt = null,
        ?string $reason = null,
        ?Model $actor = null,
        bool $system = false,
        bool $silent = false,
    ): Event {
        if ($event->phase === EventPhase::Draft) {
            return $this->editDraft($event, $startsAt, $endsAt, $actor, $silent);
        }

        $transition = EventStates::transitionFor(EventVerb::Reschedule);

        EventTransitions::apply(
            $event,
            $transition,
            $actor,
            $system,
            $reason,
            silent: $silent,
            reschedule: true,
            before: function (Event $locked) use ($startsAt, $endsAt): void {
                $schedule = $this->resolve($locked, $startsAt, $endsAt);

                if ($locked->previous_starts_at === null) {
                    $locked->previous_starts_at = $locked->starts_at;
                }

                $locked->starts_at = $schedule->startsAt;
                $locked->ends_at = $schedule->endsAt;
                $locked->doors_open_at = null;
                $locked->save();
            },
        );

        return $event;
    }

    private function editDraft(Event $event, CarbonInterface|string $startsAt, CarbonInterface|string|null $endsAt, ?Model $actor, bool $silent): Event
    {
        return EventTransaction::run($event, function () use ($event, $startsAt, $endsAt, $actor, $silent): Event {
            LockedEvent::lock($event);
            $schedule = $this->resolve($event, $startsAt, $endsAt);

            $event->starts_at = $schedule->startsAt;
            $event->ends_at = $schedule->endsAt;
            $event->save();

            $this->events->dispatch(new EventUpdated($event, ['starts_at', 'ends_at'], $actor, $silent));

            return $event;
        });
    }

    private function resolve(Event $event, CarbonInterface|string $startsAt, CarbonInterface|string|null $endsAt): ResolvedSchedule
    {
        if ($event->all_day) {
            $timezone = $event->timezone;
            $from = $startsAt instanceof CarbonInterface ? $startsAt->setTimezone($timezone)->format('Y-m-d') : substr(trim($startsAt), 0, 10);

            if ($endsAt === null) {
                // Keep the number of days.
                $days = (int) CarbonImmutable::parse((string) $event->starts_on)->diffInDays(CarbonImmutable::parse((string) $event->ends_on), true);
                $first = CarbonImmutable::createFromFormat('!Y-m-d', $from, $timezone);
                $through = $first instanceof CarbonImmutable ? $first->addDays($days)->format('Y-m-d') : $from;
            } else {
                $through = $endsAt instanceof CarbonInterface ? $endsAt->setTimezone($timezone)->format('Y-m-d') : substr(trim($endsAt), 0, 10);
            }

            return $this->schedules->execute(new ScheduleData(allDayFrom: $from, allDayThrough: $through), $timezone);
        }

        $length = $event->starts_at->diffAsCarbonInterval($event->ends_at);

        return $this->schedules->execute(
            $endsAt === null
                ? new ScheduleData(startsAt: $startsAt, duration: $length)
                : new ScheduleData(startsAt: $startsAt, endsAt: $endsAt),
            $event->timezone,
        );
    }
}
