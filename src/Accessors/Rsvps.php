<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Accessors;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Actions\Rsvps\ConfirmPaymentAction;
use RoundlyConsulting\Events\Actions\Rsvps\ConfirmRsvpAction;
use RoundlyConsulting\Events\Actions\Rsvps\ReleaseHoldAction;
use RoundlyConsulting\Events\Actions\Rsvps\RequestAdmissionPaymentAction;
use RoundlyConsulting\Events\DataTransferObjects\PaymentRequest;
use RoundlyConsulting\Events\Enums\EventOperation;
use RoundlyConsulting\Events\EventsManager;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Support\EventAuthorization;
use SensitiveParameter;

/**
 * `Events::rsvps()` — RSVP operations that start from the RSVP (or its payment) rather than the
 * event: your payment webhook's `confirmPayment()`, a hold's payment request, its release.
 * There is no route for any of them: confirmation is server-side PHP.
 */
final readonly class Rsvps
{
    public function __construct(
        private EventsManager $manager,
        private ?Model $actor = null,
    ) {}

    /**
     * Attribute the following calls to `$actor` and check `manageAttendees` for them.
     */
    public function by(?Model $actor): self
    {
        return new self($this->manager, $actor);
    }

    /**
     * Your payment webhook: held → going for the RSVP holding this checkout reference. Idempotent.
     */
    public function confirmPayment(#[SensitiveParameter] string $reference): Rsvp
    {
        return $this->manager->perform(
            EventOperation::ConfirmPayment,
            ConfirmPaymentAction::class,
            static fn (ConfirmPaymentAction $action): Rsvp => $action->execute($reference),
            ['reference' => $reference],
        );
    }

    /**
     * Confirm a held RSVP by hand, with the payment reference when given.
     */
    public function confirm(Rsvp $rsvp, ?string $reference = null): Rsvp
    {
        EventAuthorization::authorize($this->actor, 'manageAttendees', $rsvp->event);

        return $this->manager->perform(
            EventOperation::ConfirmRsvp,
            ConfirmRsvpAction::class,
            fn (ConfirmRsvpAction $action): Rsvp => $action->execute($rsvp->event, $rsvp, $reference, $this->actor),
            ['event' => $rsvp->event, 'rsvp' => $rsvp, 'reference' => $reference, 'actor' => $this->actor],
        );
    }

    /**
     * Release a hold now (the payment failed); the waitlist moves up.
     */
    public function release(Rsvp $rsvp): Rsvp
    {
        EventAuthorization::authorize($this->actor, 'manageAttendees', $rsvp->event);

        return $this->manager->perform(
            EventOperation::ReleaseHold,
            ReleaseHoldAction::class,
            fn (ReleaseHoldAction $action): Rsvp => $action->execute($rsvp->event, $rsvp, $this->actor),
            ['event' => $rsvp->event, 'rsvp' => $rsvp, 'actor' => $this->actor],
        );
    }

    /**
     * Ask the payment collector (again) for a held RSVP's checkout — once per hold.
     */
    public function requestPayment(Rsvp $rsvp): ?PaymentRequest
    {
        return $this->manager->perform(
            EventOperation::RequestPayment,
            RequestAdmissionPaymentAction::class,
            static fn (RequestAdmissionPaymentAction $action): ?PaymentRequest => $action->execute($rsvp),
            ['event' => $rsvp->event, 'rsvp' => $rsvp],
        );
    }
}
