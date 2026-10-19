<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\CheckIns;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Contracts\CheckInCodes;
use RoundlyConsulting\Events\DataTransferObjects\CheckInResult;
use RoundlyConsulting\Events\Enums\CheckInMethod;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Events\AttendeeCheckedIn;
use RoundlyConsulting\Events\Exceptions\CheckInRefusedException;
use RoundlyConsulting\Events\Exceptions\InvalidCheckInCodeException;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Support\CheckInWriter;
use RoundlyConsulting\Events\Support\EventAuthorization;
use RoundlyConsulting\Events\Support\EventModels;
use SensitiveParameter;

/**
 * Checks an attendee in by the code they show: the scanner input is normalised (trimmed,
 * upper-cased, the code found inside a URL or behind a suffix), looked up by its hash, and
 * refused when unknown or revoked (`invalid_code`), of another event in a scoped check-in
 * (`wrong_event`, never naming the other event), not going, for an event that is not on, or
 * outside the window (`check_in.opens_before` the start to `check_in.closes_after` the end)
 * unless `ignoreWindow`. Idempotent: a second scan reports `firstCheckIn: false`.
 *
 * The unscoped form (no event) finds the event from the code and requires the actor: the
 * `checkIn` policy runs on that event, and a non-manager is answered exactly like an unknown
 * code — no oracle for other events' codes.
 */
final readonly class CheckInByCodeAction
{
    public function __construct(
        private CheckInCodes $codes,
        private Dispatcher $events,
    ) {}

    public function execute(#[SensitiveParameter] string $input, ?Event $event = null, ?Model $by = null, ?int $guests = null, bool $ignoreWindow = false): CheckInResult
    {
        $code = $this->codes->extract($input) ?? throw InvalidCheckInCodeException::unknown();
        $hash = $this->codes->hash($code);
        $rsvp = EventModels::rsvp()::query()->where('check_in_code_hash', $hash)->first()
            ?? throw InvalidCheckInCodeException::unknown();
        $owner = $rsvp->event;

        if ($event === null) {
            if ($by === null || ! EventAuthorization::allows($by, 'checkIn', $owner)) {
                throw InvalidCheckInCodeException::unknown();
            }
        } elseif (! $owner->is($event)) {
            throw CheckInRefusedException::because(CheckInRefusedException::WRONG_EVENT);
        }

        if ($rsvp->status !== RsvpStatus::Going) {
            throw CheckInRefusedException::because(CheckInRefusedException::NOT_GOING);
        }

        CheckInWriter::assertActive($owner);

        if (! $ignoreWindow) {
            CheckInWriter::assertWindow($owner);
        }

        // The write needs the RSVP to still be going and to still hold this code.
        $result = CheckInWriter::write($rsvp, $guests, CheckInMethod::Code, $by, $hash);

        if ($result->firstCheckIn) {
            $this->events->dispatch(new AttendeeCheckedIn($result));
        }

        return $result;
    }
}
