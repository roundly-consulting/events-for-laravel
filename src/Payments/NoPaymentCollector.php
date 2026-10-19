<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Payments;

use RoundlyConsulting\Events\Contracts\CollectsAdmissionPayment;
use RoundlyConsulting\Events\DataTransferObjects\PaymentRequest;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Money\Money;

/**
 * The default collector: opens no checkout. Listen to `RsvpHeld` to collect another way, or bind
 * your own `CollectsAdmissionPayment` (`events.payments.collector`).
 */
final readonly class NoPaymentCollector implements CollectsAdmissionPayment
{
    public function request(Rsvp $rsvp, Money $amount): ?PaymentRequest
    {
        return null;
    }
}
