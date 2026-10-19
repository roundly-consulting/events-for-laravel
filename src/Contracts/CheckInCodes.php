<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Contracts;

use RoundlyConsulting\Events\Models\Rsvp;
use SensitiveParameter;

/**
 * Issues and reads check-in codes. The default issues random 160-bit `EV1-…` codes; a host can
 * plug a signed or offline-verifiable format behind the same seam.
 */
interface CheckInCodes
{
    /**
     * A new code for a going RSVP.
     */
    public function issue(Rsvp $rsvp): string;

    /**
     * The code inside scanner input (a URL payload, a code with a suffix), or null.
     */
    public function extract(#[SensitiveParameter] string $input): ?string;

    /**
     * The lookup hash stored for a code.
     */
    public function hash(#[SensitiveParameter] string $code): string;
}
