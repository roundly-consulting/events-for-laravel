<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Tests\Fixtures\Payments;

use Closure;
use RoundlyConsulting\Events\Contracts\CollectsAdmissionPayment;
use RoundlyConsulting\Events\DataTransferObjects\PaymentRequest;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Money\Money;

/**
 * A collector whose provider call takes a while: `$during` runs once inside the call (the
 * attendee declines, the host asks again), and the reference is the RSVP's own — what an
 * idempotent provider returns for a repeated request.
 */
final class SlowCollector implements CollectsAdmissionPayment
{
    public static ?Closure $during = null;

    public static bool $returnsNull = false;

    /** @var list<string|null> the idempotency key each call passed on */
    public static array $keys = [];

    public static function reset(): void
    {
        self::$during = null;
        self::$returnsNull = false;
        self::$keys = [];
    }

    public function request(Rsvp $rsvp, Money $amount): ?PaymentRequest
    {
        self::$keys[] = $rsvp->paymentIdempotencyKey();

        if (self::$during !== null) {
            $during = self::$during;
            self::$during = null;
            $during();
        }

        if (self::$returnsNull) {
            return null;
        }

        return new PaymentRequest('cs_'.$rsvp->getKey(), 'https://pay.example.com/cs_'.$rsvp->getKey());
    }
}
