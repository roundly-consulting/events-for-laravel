<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Maintenance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use RoundlyConsulting\Events\Actions\Internal\ReleaseAttendeeRsvpsAction;
use RoundlyConsulting\Events\DataTransferObjects\RepairOptions;
use RoundlyConsulting\Events\DataTransferObjects\RepairReport;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Support\EventCounters;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\LockedEvent;

/**
 * Recomputes every event's counters under its row lock (a drift is a bug or a hand-made write)
 * and releases the RSVPs of attendees whose row is gone — seats returned, waitlist promoted —
 * one `whereNotExists` per attendee type.
 */
final readonly class RepairEventsAction
{
    public function __construct(
        private ReleaseAttendeeRsvpsAction $release,
    ) {}

    public function execute(?RepairOptions $options = null): RepairReport
    {
        $options ??= new RepairOptions;
        $checked = 0;
        $fixed = 0;
        $released = 0;

        if ($options->orphans) {
            $released = $this->orphans($options->dryRun);
        }

        if ($options->counts) {
            EventModels::event()::withTrashed()->chunkById(max(1, $options->chunk), function ($events) use ($options, &$checked, &$fixed): void {
                foreach ($events as $event) {
                    $checked++;
                    $fixed += $this->counts($event, $options->dryRun) ? 1 : 0;
                }
            });
        }

        return new RepairReport($checked, $fixed, $released, $options->dryRun);
    }

    private function counts(Event $event, bool $dryRun): bool
    {
        return EventTransaction::run($event, static function () use ($event, $dryRun): bool {
            LockedEvent::lock($event, allowTrashed: true);
            $stored = EventCounters::stored($event);
            $actual = EventCounters::recount($event);

            if ($stored == $actual) {
                return false;
            }

            if (! $dryRun) {
                $event->newQueryWithoutScopes()->whereKey($event->getKey())->toBase()->update([
                    'seats_taken' => $actual->seatsTaken,
                    'going_count' => $actual->goingCount,
                    'interested_count' => $actual->interestedCount,
                    'waitlist_count' => $actual->waitlistCount,
                ]);

                foreach ($actual->tierSeats as $tier => $seats) {
                    EventModels::tier()::withTrashed()->whereKey($tier)->toBase()->update(['seats_taken' => $seats]);
                }
            }

            return true;
        });
    }

    private function orphans(bool $dryRun): int
    {
        $rsvps = EventModels::rsvp();
        $table = (new $rsvps)->getTable();
        $released = 0;

        foreach ($rsvps::query()->distinct()->pluck('attendee_type') as $type) {
            $class = Relation::getMorphedModel((string) $type) ?? (string) $type;

            if (! is_subclass_of($class, Model::class)) {
                continue;
            }

            $attendee = new $class;
            $ids = $rsvps::query()
                ->where('attendee_type', $type)
                ->whereNotExists(static function ($query) use ($attendee, $table): void {
                    $query->selectRaw('1')
                        ->from($attendee->getTable())
                        ->whereColumn($attendee->getQualifiedKeyName(), $table.'.attendee_id');
                })
                ->distinct()
                ->pluck('attendee_id');

            foreach ($ids as $id) {
                $ghost = new $class;
                $ghost->setAttribute($ghost->getKeyName(), $id);
                $released += $dryRun ? $rsvps::query()->where('attendee_type', $type)->where('attendee_id', $id)->count() : $this->release->execute($ghost);
            }
        }

        return $released;
    }
}
