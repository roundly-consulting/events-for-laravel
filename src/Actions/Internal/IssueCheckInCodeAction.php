<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Internal;

use RoundlyConsulting\Events\Contracts\CheckInCodes;
use RoundlyConsulting\Events\Models\Rsvp;

/**
 * Gives a going RSVP a fresh check-in code: kept encrypted (so its QR can be drawn again) and as a
 * hash (the lookup key). The plain code is never logged or put in an event. Not saved here — the
 * caller writes the RSVP.
 *
 * @internal
 */
final readonly class IssueCheckInCodeAction
{
    public function __construct(
        private CheckInCodes $codes,
    ) {}

    public function execute(Rsvp $rsvp): void
    {
        $code = $this->codes->issue($rsvp);

        $rsvp->check_in_code = $code;
        $rsvp->check_in_code_hash = $this->codes->hash($code);
    }
}
