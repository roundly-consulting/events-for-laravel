<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Events\DataTransferObjects\PaymentRequest;
use RoundlyConsulting\Events\Models\Rsvp;

/**
 * The collector returned a checkout, but the hold changed while it was being created (released,
 * declined, refreshed or given another checkout): the package kept nothing of it, announced
 * nothing, and `requestPayment()` returned null. Void it at your provider (expire the session) so
 * nobody pays for a seat that is gone.
 */
final readonly class RsvpPaymentRequestDiscarded implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Rsvp $rsvp,
        public PaymentRequest $paymentRequest,
    ) {}
}
