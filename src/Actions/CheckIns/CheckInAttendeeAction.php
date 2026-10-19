<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\CheckIns;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\DataTransferObjects\CheckInResult;
use RoundlyConsulting\Events\Enums\CheckInMethod;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Events\AttendeeCheckedIn;
use RoundlyConsulting\Events\Exceptions\InvalidRsvpTransitionException;
use RoundlyConsulting\Events\Exceptions\RsvpNotFoundException;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Support\CheckInWriter;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\RsvpRules;

/**
 * A manager checks someone in by hand — no code, any time (the window does not apply). They must
 * be going: add a walk-in with `attendees()->add()` first.
 *
 * The RSVP is read under a row lock inside a transaction that locks nothing else — an RSVP lock
 * is never followed by an event lock — so a decline or a removal cannot land between the read
 * and the write; the write's own compare-and-swap backs that up.
 */
final readonly class CheckInAttendeeAction
{
    public function __construct(
        private Dispatcher $events,
    ) {}

    public function execute(Event $event, Rsvp|Model $who, ?int $guests = null, ?Model $actor = null): CheckInResult
    {
        $model = EventModels::rsvp();

        $result = EventTransaction::run(new $model, static function () use ($event, $who, $guests, $actor): CheckInResult {
            $rsvp = RsvpRules::rsvpOf($event, $who) ?? throw RsvpNotFoundException::missing();

            if ($rsvp->status !== RsvpStatus::Going) {
                throw InvalidRsvpTransitionException::notGoing();
            }

            CheckInWriter::assertActive($event);

            return CheckInWriter::write($rsvp, $guests, CheckInMethod::Manual, $actor);
        });

        if ($result->firstCheckIn) {
            $this->events->dispatch(new AttendeeCheckedIn($result));
        }

        return $result;
    }
}
