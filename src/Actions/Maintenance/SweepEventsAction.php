<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Maintenance;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Contracts\Events\Dispatcher;
use RoundlyConsulting\Events\Actions\Internal\AutoPromoteAction;
use RoundlyConsulting\Events\Actions\Internal\ClaimReminderAction;
use RoundlyConsulting\Events\Actions\Internal\ExpireHoldsAction;
use RoundlyConsulting\Events\DataTransferObjects\SweepOptions;
use RoundlyConsulting\Events\DataTransferObjects\SweepResult;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Events\RemindersDispatched;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Notifications\Notifier;
use RoundlyConsulting\Events\Notifications\RecipientResolver;
use RoundlyConsulting\Events\Support\Clock;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventsConfig;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\ReminderSchedule;
use RoundlyConsulting\Events\Support\SeatBook;

/**
 * The periodic sweep behind `events:sweep` (schedule it every minute): releases lapsed holds and
 * moves each waitlist up, event by event under each event's lock — exactly once, however many
 * sweeps run at the same time.
 */
final readonly class SweepEventsAction
{
    public function __construct(
        private ExpireHoldsAction $holds,
        private AutoPromoteAction $promote,
        private ClaimReminderAction $claims,
        private Notifier $notifier,
        private Dispatcher $events,
    ) {}

    public function execute(?SweepOptions $options = null): SweepResult
    {
        $options ??= new SweepOptions;
        $rsvps = EventModels::rsvp();
        $ids = $rsvps::query()
            ->where('status', RsvpStatus::Held->value)
            ->where('held_until', '<=', Clock::nowUtc())
            ->distinct()
            ->orderBy('event_id')
            ->when($options->limit !== null, static fn ($query) => $query->limit((int) $options->limit))
            ->pluck('event_id');

        $events = 0;
        $expired = 0;
        $promoted = 0;

        foreach ($ids->chunk(max(1, $options->chunk)) as $chunk) {
            foreach (EventModels::event()::withTrashed()->whereKey($chunk->all())->get() as $event) {
                $events++;

                EventTransaction::run($event, function () use ($event, &$expired, &$promoted): void {
                    $book = SeatBook::open($event, allowTrashed: true);
                    $expired += $this->holds->execute($book);
                    $promoted += count($this->promote->execute($book));
                });
            }
        }

        [$reminders, $notified] = $this->reminders($options);

        return new SweepResult($events, $expired, $promoted, $reminders, $notified);
    }

    /**
     * Every reminder now due, exactly once. Skipped: an offset due before the current schedule
     * was announced (`max(published_at, rescheduled_at)`), one more than
     * `events.reminders.max_lateness` late, and anything at or after the start (an all-day
     * event's civil hour, {@see ReminderSchedule::anchor()}).
     *
     * @return array{0: int, 1: int}
     */
    private function reminders(SweepOptions $options): array
    {
        $now = Clock::now();
        $lateness = EventsConfig::reminderMaxLateness();
        $reminders = 0;
        $notified = 0;

        // Offsets are at most P31D: nothing starting later can be due. An all-day event's
        // reminders count back from a civil hour on its first day, after its midnight start.
        EventModels::event()::query()
            ->inPhase(EventPhase::Scheduled, EventPhase::Rescheduled)
            ->where(static fn ($query) => $query->where('starts_at', '>', Clock::format($now))
                ->orWhere(static fn ($allDay) => $allDay->where('all_day', true)->where('starts_at', '>', Clock::format($now->subDays(2)))))
            ->where('starts_at', '<=', Clock::format($now->addDays(32)))
            ->when($options->limit !== null, static fn ($query) => $query->limit((int) $options->limit))
            ->chunkById(max(1, $options->chunk), function ($events) use ($now, $lateness, &$reminders, &$notified): void {
                foreach ($events as $event) {
                    foreach (ReminderSchedule::offsets($event) as $offset) {
                        $sent = $this->remind($event, $offset, $now, $lateness);

                        if ($sent !== null) {
                            $reminders++;
                            $notified += $sent;
                        }
                    }
                }
            });

        return [$reminders, $notified];
    }

    private function remind(Event $event, string $offset, CarbonImmutable $now, CarbonInterval $lateness): ?int
    {
        $due = ReminderSchedule::dueAt($event, $offset);
        $announced = $event->rescheduled_at !== null && ($event->published_at === null || $event->rescheduled_at->greaterThan($event->published_at))
            ? $event->rescheduled_at
            : $event->published_at;

        if ($due === null || $due->greaterThan($now) || ! ReminderSchedule::anchor($event)->greaterThan($now)
            || ($announced !== null && $due->lessThan($announced))
            || $due->add($lateness)->lessThan($now)) {
            return null;
        }

        $delivery = $this->claims->execute($event, $offset);

        if ($delivery === null) {
            return null;
        }

        $count = $this->notifier->send('reminder', $event, RecipientResolver::audience('reminder', $event), data: ['offset' => $offset]);
        $delivery->forceFill(['dispatched_at' => $now, 'recipients' => $count])->save();
        $this->events->dispatch(new RemindersDispatched($event, $offset, $count));

        return $count;
    }
}
