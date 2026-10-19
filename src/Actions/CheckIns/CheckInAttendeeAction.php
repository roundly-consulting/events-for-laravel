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
use RoundlyConsulting\Events\Support\RsvpRules;

/**
 * A manager checks someone in by hand — no code, any time (the window does not apply). They must
 * be going: add a walk-in with `attendees()->add()` first.
 */
final readonly class CheckInAttendeeAction
{
    public function __construct(
        private Dispatcher $events,
    ) {}

    public function execute(Event $event, Rsvp|Model $who, ?int $guests = null, ?Model $actor = null): CheckInResult
    {
        $rsvp = RsvpRules::rsvpOf($event, $who) ?? throw RsvpNotFoundException::missing();

        if ($rsvp->status !== RsvpStatus::Going) {
            throw InvalidRsvpTransitionException::notGoing();
        }

        CheckInWriter::assertActive($event);
        $result = CheckInWriter::write($rsvp, $guests, CheckInMethod::Manual, $actor);

        if ($result->firstCheckIn) {
            $this->events->dispatch(new AttendeeCheckedIn($result));
        }

        return $result;
    }
}
