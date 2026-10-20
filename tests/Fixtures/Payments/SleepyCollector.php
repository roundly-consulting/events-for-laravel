<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Tests\Fixtures\Payments;

use RoundlyConsulting\Events\Contracts\CollectsAdmissionPayment;
use RoundlyConsulting\Events\DataTransferObjects\PaymentRequest;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Money\Money;

/**
 * A provider that answers a 15.00 checkout after 1.5 s and anything else after 3 s — for racing
 * answers in separate sessions. The reference names the amount it was opened for.
 */
final class SleepyCollector implements CollectsAdmissionPayment
{
    public function request(Rsvp $rsvp, Money $amount): ?PaymentRequest
    {
        usleep($amount->toDecimal() === '15.00' ? 1_500_000 : 3_000_000);

        return new PaymentRequest('race_'.$amount->toDecimal(), 'https://pay.example.com/'.$amount->toDecimal());
    }
}
