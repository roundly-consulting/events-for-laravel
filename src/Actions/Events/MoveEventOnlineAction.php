<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Events;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Enums\AttendanceMode;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Events\EventMovedOnline;
use RoundlyConsulting\Events\Events\EventUpdated;
use RoundlyConsulting\Events\Exceptions\InvalidEventException;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Support\Clock;
use RoundlyConsulting\Events\Support\EventInput;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\LockedEvent;

/**
 * Moves an event online: the join link is set, the venue dropped (or kept with `keepVenue`, a
 * hybrid event), `moved_online_at` stamped and the calendar `sequence` raised. The status does not
 * change — structured data reports it as `EventMovedOnline`. A finished event cannot move.
 *
 * A draft announced nothing, so nothing "moves": it just gets the link and the mode (an ordinary
 * edit, `EventUpdated`), and is published as an online event.
 */
final readonly class MoveEventOnlineAction
{
    public function __construct(
        private Dispatcher $events,
    ) {}

    public function execute(Event $event, string $url, bool $keepVenue = false, ?Model $actor = null, bool $silent = false): Event
    {
        $url = (string) EventInput::onlineUrl($url);

        return EventTransaction::run($event, function () use ($event, $url, $keepVenue, $actor, $silent): Event {
            LockedEvent::lock($event);

            if ($event->phase->isTerminal()) {
                throw InvalidEventException::finished();
            }

            $event->online_url = $url;

            if (! $keepVenue) {
                $event->venue_id = null;
            }

            $event->attendance_mode = $event->venue_id === null ? AttendanceMode::Online : AttendanceMode::Mixed;

            if ($event->phase === EventPhase::Draft) {
                $changed = array_keys($event->getDirty());
                $event->save();

                if ($changed !== []) {
                    $this->events->dispatch(new EventUpdated($event, $changed, $actor, $silent));
                }

                return $event;
            }

            $event->moved_online_at = Clock::now();
            $event->sequence = $event->sequence + 1;
            $event->save();

            $this->events->dispatch(new EventMovedOnline($event, $actor, $silent));

            return $event;
        });
    }
}
