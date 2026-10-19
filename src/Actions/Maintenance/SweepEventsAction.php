<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Maintenance;

use RoundlyConsulting\Events\Actions\Internal\AutoPromoteAction;
use RoundlyConsulting\Events\Actions\Internal\ExpireHoldsAction;
use RoundlyConsulting\Events\DataTransferObjects\SweepOptions;
use RoundlyConsulting\Events\DataTransferObjects\SweepResult;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Support\Clock;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventTransaction;
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

        return new SweepResult($events, $expired, $promoted);
    }
}
