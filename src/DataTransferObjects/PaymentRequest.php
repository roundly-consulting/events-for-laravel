<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\DataTransferObjects;

use Carbon\CarbonInterface;

/**
 * A checkout your payment collector opened for a held RSVP: the reference your webhook confirms
 * with (`Events::rsvps()->confirmPayment($reference)`), where to send the attendee, and when the
 * checkout expires (an earlier expiry shortens the hold).
 */
final readonly class PaymentRequest
{
    public function __construct(
        public string $reference,
        public ?string $url = null,
        public ?CarbonInterface $expiresAt = null,
    ) {}
}
