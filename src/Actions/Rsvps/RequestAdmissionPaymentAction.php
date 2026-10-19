<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Rsvps;

use Illuminate\Contracts\Events\Dispatcher;
use RoundlyConsulting\Events\Contracts\CollectsAdmissionPayment;
use RoundlyConsulting\Events\DataTransferObjects\PaymentRequest;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Events\RsvpHeld;
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

        try {
            $request = $this->collector->request($rsvp, $amount);
        } catch (Throwable $exception) {
            $this->events->dispatch(new RsvpPaymentRequestFailed($rsvp, $exception));

            throw $exception;
        }

        if ($request !== null) {
            $this->store($rsvp, $request);
            $given->setRawAttributes($rsvp->getAttributes(), true);
        }

        $this->events->dispatch(new RsvpHeld($rsvp, $amount, $request));

        return $request;
    }

    private function store(Rsvp $rsvp, PaymentRequest $request): void
    {
        $event = $rsvp->event;

        EventTransaction::run($event, static function () use ($event, $rsvp, $request): void {
            LockedEvent::lock($event, allowTrashed: true);
            $current = EventModels::rsvp()::query()->whereKey($rsvp->getKey())->lockForUpdate()->first();

            // The hold changed meanwhile (released, refreshed): this checkout belongs to nothing.
            if ($current === null || $current->status !== RsvpStatus::Held || $current->payment_reference !== null) {
                return;
            }

            $current->payment_reference = $request->reference;
            $current->payment_url = $request->url;

            if ($request->expiresAt !== null && $current->held_until !== null && $request->expiresAt->lessThan($current->held_until)) {
                $current->held_until = $request->expiresAt->toImmutable();
            }

            $current->save();
            $rsvp->setRawAttributes($current->getAttributes(), true);
        });
    }
}
