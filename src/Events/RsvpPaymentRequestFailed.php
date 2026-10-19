<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Events\Models\Rsvp;
use Throwable;

/**
 * The payment collector threw for a held RSVP. The hold stays and expires on its own; retry with `Events::rsvps()->requestPayment($rsvp)`.
 */
final readonly class RsvpPaymentRequestFailed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Rsvp $rsvp,
        public Throwable $exception,
    ) {}
}
