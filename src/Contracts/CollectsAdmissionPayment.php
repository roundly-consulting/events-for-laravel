<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Contracts;

use RoundlyConsulting\Events\DataTransferObjects\PaymentRequest;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Money\Money;

/**
 * The payment seam. When an RSVP for a paid ticket type is put on hold, the package asks this
 * for a checkout: return a {@see PaymentRequest} (a unique reference and the URL to pay at), or
 * null to collect some other way (listen to `RsvpHeld`). Confirm from your webhook with
 * `Events::rsvps()->confirmPayment($reference)`.
 *
 * Bind your implementation in a service provider, or name it in `events.payments.collector`.
 */
interface CollectsAdmissionPayment
{
    public function request(Rsvp $rsvp, Money $amount): ?PaymentRequest;
}
