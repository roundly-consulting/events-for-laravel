<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Tests\Fixtures\Payments;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Events\Contracts\CollectsAdmissionPayment;
use RoundlyConsulting\Events\DataTransferObjects\PaymentRequest;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Money\Money;
use RuntimeException;

/**
 * A host's payment collector: a checkout per hold, reference `pay_<rsvp id>_<n>`. Remembers every
 * call (and whether a transaction was open — it must run after commit) and can be made to throw.
 */
final class FakePaymentCollector implements CollectsAdmissionPayment
{
    /** @var list<array{rsvp: int, amount: string, level: int}> */
    public static array $calls = [];

    public static bool $fails = false;

    public static ?CarbonImmutable $expiresAt = null;

    public static function reset(): void
    {
        self::$calls = [];
        self::$fails = false;
        self::$expiresAt = null;
    }

    public function request(Rsvp $rsvp, Money $amount): ?PaymentRequest
    {
        self::$calls[] = ['rsvp' => (int) $rsvp->getKey(), 'amount' => $amount->toDecimal().' '.$amount->currency()->code, 'level' => $rsvp->getConnection()->transactionLevel()];

        if (self::$fails) {
            throw new RuntimeException('Checkout unavailable');
        }

        return new PaymentRequest('pay_'.$rsvp->getKey().'_'.count(self::$calls), 'https://pay.example.com/'.count(self::$calls), self::$expiresAt);
    }
}
