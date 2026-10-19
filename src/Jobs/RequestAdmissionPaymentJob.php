<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\EventsManager;
use RoundlyConsulting\Events\Notifications\Notifier;
use RoundlyConsulting\Events\Support\EventModels;
use Throwable;

/**
 * Requests the payment for a hold someone else's action created — a promotion off the waitlist, a
 * manager's change — on the queue, so a failing collector never fails that action. A failure is
 * reported (and `RsvpPaymentRequestFailed` fired); the hold stays and expires on its own, and
 * `Events::rsvps()->requestPayment($rsvp)` can ask again.
 *
 * For a promotion the job also sends the `waitlist_promoted` notice — after the request, so it
 * carries the pay link (the event link when the collector gave none or failed). A hold released
 * before the job runs is told nothing.
 */
final class RequestAdmissionPaymentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public readonly int $rsvpId,
        public readonly bool $promotion = false,
    ) {}

    public function handle(EventsManager $events, Notifier $notifier): void
    {
        $rsvp = EventModels::rsvp()::query()->find($this->rsvpId);

        if ($rsvp === null || $rsvp->status !== RsvpStatus::Held) {
            return;
        }

        // A reference means someone (the host) asked meanwhile: no second checkout.
        if ($rsvp->payment_reference === null) {
            try {
                $events->rsvps()->requestPayment($rsvp);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        if ($this->promotion) {
            $this->tellPromoted($notifier);
        }
    }

    private function tellPromoted(Notifier $notifier): void
    {
        $rsvp = EventModels::rsvp()::query()->find($this->rsvpId);
        $attendee = $rsvp?->attendee;

        if ($rsvp !== null && $rsvp->status === RsvpStatus::Held && $attendee instanceof Model) {
            $notifier->send('waitlist_promoted', $rsvp->event, [$attendee], $rsvp);
        }
    }
}
