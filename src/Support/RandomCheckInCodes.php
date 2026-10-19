<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use RoundlyConsulting\Crypto\CryptoManager;
use RoundlyConsulting\Events\Contracts\CheckInCodes;
use RoundlyConsulting\Events\Models\Rsvp;
use SensitiveParameter;

/**
 * The default check-in codes: `EV1-` + base32 (RFC 4648, upper-case, unpadded) of 20 random bytes
 * — 160 bits, unguessable without any signature, 36 characters that fit QR alphanumeric mode. The
 * lookup key is the code's SHA-256; a check-in hits the database anyway (revocation,
 * idempotency), so a signed or offline format is a seam for later, not a need.
 */
final readonly class RandomCheckInCodes implements CheckInCodes
{
    public const string PREFIX = 'EV1-';

    public function __construct(
        private CryptoManager $crypto,
    ) {}

    public function issue(Rsvp $rsvp): string
    {
        return self::PREFIX.$this->crypto->base32Encode($this->crypto->randomBytes(20));
    }

    /**
     * Trimmed, upper-cased, and the first `EV1-` code inside — so a URL payload or a scanner's
     * suffix still reads.
     */
    public function extract(#[SensitiveParameter] string $input): ?string
    {
        return preg_match('/EV1-[A-Z2-7]{32}/', strtoupper(trim($input)), $match) === 1 ? $match[0] : null;
    }

    public function hash(#[SensitiveParameter] string $code): string
    {
        return $this->crypto->digest()->hex($code);
    }
}
