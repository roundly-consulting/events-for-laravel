<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Events\DataTransferObjects\PaymentRequest;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Money\Money;

/**
 * Seats were held for a paid ticket type while the payment is pending. `paymentRequest` is the collector's checkout, or null (listen here to collect another way).
 */
final readonly class RsvpHeld implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Rsvp $rsvp,
        public Money $amount,
        public ?PaymentRequest $paymentRequest,
    ) {}
}
