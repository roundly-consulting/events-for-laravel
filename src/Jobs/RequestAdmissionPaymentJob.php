<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\EventsManager;
use RoundlyConsulting\Events\Support\EventModels;
use Throwable;

/**
 * Requests the payment for a hold someone else's action created — a promotion off the waitlist, a
 * manager's change — on the queue, so a failing collector never fails that action. A failure is
 * reported (and `RsvpPaymentRequestFailed` fired); the hold stays and expires on its own, and
 * `Events::rsvps()->requestPayment($rsvp)` can ask again.
 */
final class RequestAdmissionPaymentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public readonly int $rsvpId,
    ) {}

    public function handle(EventsManager $events): void
    {
        $rsvp = EventModels::rsvp()::query()->find($this->rsvpId);

        if ($rsvp === null || $rsvp->status !== RsvpStatus::Held || $rsvp->payment_reference !== null) {
            return;
        }

        try {
            $events->rsvps()->requestPayment($rsvp);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
