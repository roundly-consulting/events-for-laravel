<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Rsvps;

use Illuminate\Contracts\Events\Dispatcher;
use RoundlyConsulting\Events\Contracts\CollectsAdmissionPayment;
use RoundlyConsulting\Events\DataTransferObjects\PaymentRequest;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Events\RsvpHeld;
use RoundlyConsulting\Events\Events\RsvpPaymentRequestDiscarded;
use RoundlyConsulting\Events\Events\RsvpPaymentRequestFailed;
use RoundlyConsulting\Events\Exceptions\InvalidRsvpTransitionException;
use RoundlyConsulting\Events\Exceptions\RsvpNotFoundException;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\LockedEvent;
use Throwable;

/**
 * Asks the payment collector for a checkout for a held RSVP — once per hold: a hold that already
 * has its request gets the same one back. The reference (unique) and URL are stored, and an
 * earlier expiry shortens the hold. Fires `RsvpHeld` with the request (or null: collect another
 * way). A failing collector fires `RsvpPaymentRequestFailed` and throws; the hold stays and
 * expires on its own.
 *
 * A checkout for a hold that changed during the collector call (released, declined, refreshed —
 * a new party or ticket type gives it a new `hold_key` and amount — or given another checkout
 * meanwhile) is kept nowhere: nothing is announced, null is returned and
 * `RsvpPaymentRequestDiscarded` reports it so the host can void it. The same reference stored
 * meanwhile for the same hold (an idempotent collector answering a repeated request) counts as
 * stored — collectors pass `$rsvp->paymentIdempotencyKey()` to the provider for exactly that.
 */
final readonly class RequestAdmissionPaymentAction
{
    public function __construct(
        private CollectsAdmissionPayment $collector,
        private Dispatcher $events,
    ) {}

    public function execute(Rsvp $given): ?PaymentRequest
    {
        $rsvp = EventModels::rsvp()::query()->whereKey($given->getKey())->first() ?? throw RsvpNotFoundException::missing();
        $given->setRawAttributes($rsvp->getAttributes(), true);

        if ($rsvp->status !== RsvpStatus::Held) {
            throw InvalidRsvpTransitionException::notHeld();
        }

        if ($rsvp->payment_reference !== null) {
            return new PaymentRequest($rsvp->payment_reference, $rsvp->payment_url, $rsvp->held_until);
        }

        $amount = $rsvp->amount;

        if ($amount === null) {
            return null;
        }

        // The hold this checkout is for: a refresh during the call gives the RSVP another one.
        $holdKey = $rsvp->hold_key;

        try {
            $request = $this->collector->request($rsvp, $amount);
        } catch (Throwable $exception) {
            $this->events->dispatch(new RsvpPaymentRequestFailed($rsvp, $exception));

            throw $exception;
        }

        if ($request !== null) {
            $stored = $this->store($rsvp, $request, $holdKey);
            $given->setRawAttributes($rsvp->getAttributes(), true);

            if (! $stored) {
                $this->events->dispatch(new RsvpPaymentRequestDiscarded($rsvp, $request));

                return null;
            }
        }

        $this->events->dispatch(new RsvpHeld($rsvp, $amount, $request));

        return $request;
    }

    /**
     * @return bool whether the hold now carries this checkout
     */
    private function store(Rsvp $rsvp, PaymentRequest $request, ?string $holdKey): bool
    {
        $event = $rsvp->event;

        return EventTransaction::run($event, static function () use ($event, $rsvp, $request, $holdKey): bool {
            LockedEvent::lock($event, allowTrashed: true);
            $current = EventModels::rsvp()::query()->whereKey($rsvp->getKey())->lockForUpdate()->first();

            if ($current !== null) {
                $rsvp->setRawAttributes($current->getAttributes(), true);
            }

            // The hold changed meanwhile (released, refreshed for another party or ticket type,
            // given another checkout): this checkout belongs to nothing.
            if ($current === null || $current->status !== RsvpStatus::Held || $current->hold_key !== $holdKey) {
                return false;
            }

            if ($current->payment_reference !== null) {
                return $current->payment_reference === $request->reference;
            }

            $current->payment_reference = $request->reference;
            $current->payment_url = $request->url;

            if ($request->expiresAt !== null && $current->held_until !== null && $request->expiresAt->lessThan($current->held_until)) {
                $current->held_until = $request->expiresAt->toImmutable();
            }

            $current->save();
            $rsvp->setRawAttributes($current->getAttributes(), true);

            return true;
        });
    }
}
