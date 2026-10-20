<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\CheckIns;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Events\CheckInUndone;
use RoundlyConsulting\Events\Exceptions\RsvpNotFoundException;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\RsvpRules;

/**
 * Undoes a check-in (scanned by mistake): the attendee is going and not checked in again. The
 * RSVP row alone is locked, and the write is a compare-and-swap on `checked_in_at IS NOT NULL`,
 * so two undos announce one `CheckInUndone`. The returned RSVP reports
 * `wasChanged('checked_in_at')` only when this call undid a check-in.
 */
final readonly class UndoCheckInAction
{
    public function __construct(
        private Dispatcher $events,
    ) {}

    public function execute(Event $event, Rsvp|Model $who, ?Model $actor = null): Rsvp
    {
        $model = EventModels::rsvp();

        [$rsvp, $undone] = EventTransaction::run(new $model, static function () use ($event, $who): array {
            $rsvp = RsvpRules::rsvpOf($event, $who) ?? throw RsvpNotFoundException::missing();

            $cleared = [
                'checked_in_at' => null,
                'checked_in_guests' => null,
                'check_in_method' => null,
                'checked_in_by_type' => null,
                'checked_in_by_id' => null,
            ];

            $undone = $rsvp->checked_in_at !== null && EventModels::rsvp()::query()
                ->whereKey($rsvp->getKey())
                ->whereNotNull('checked_in_at')
                ->update($cleared) === 1;

            if ($undone) {
                // What this call changed, as a save would report it — then the stored row.
                $rsvp->forceFill($cleared)->syncChanges();
                $rsvp->refresh();
            }

            return [$rsvp, $undone];
        });

        if ($undone) {
            $this->events->dispatch(new CheckInUndone($rsvp, $actor));
        }

        return $rsvp;
    }
}
