<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Internal;

use Illuminate\Contracts\Bus\Dispatcher as Bus;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Jobs\RequestAdmissionPaymentJob;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Support\Clock;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventsConfig;
use RoundlyConsulting\Events\Support\SeatBook;

/**
 * Moves the waitlist up into free seats: candidates in queue order (`waitlisted_at`, then id),
 * first fit — a party that does not fit is skipped and keeps its place, the next one is tried.
 * A free ticket type goes straight to going; a paid one is held for `payments.promotion_hold`
 * (never past the start) and its payment request is queued after commit, so a failing payment
 * collector never fails someone else's decline or the sweep.
 *
 * Automatic promotion runs only when the event allows it, while the event is on, and until
 * `waitlist.stop_promoting_before` the start; a manual promotion (`waitlist()->promote()`) ignores
 * the cutoff. Promotion ignores the ticket type's sale window: the place was taken while on sale.
 *
 * @internal
 */
final readonly class AutoPromoteAction
{
    public function __construct(
        private ApplySeatChangesAction $seats,
        private Bus $bus,
    ) {}

    /**
     * @return list<Rsvp>
     */
    public function execute(SeatBook $book, ?int $limit = null, bool $manual = false): array
    {
        $event = $book->event;
        $phase = $event->phase;

        if ($manual ? ($phase === EventPhase::Draft || $phase->isTerminal()) : ! $this->automatic($book)) {
            return [];
        }

        $candidates = EventModels::rsvp()::query()
            ->where('event_id', $event->getKey())
            ->where('status', RsvpStatus::Waitlisted->value)
            ->orderBy('waitlisted_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $promoted = [];

        foreach ($candidates as $candidate) {
            if ($limit !== null && count($promoted) >= $limit) {
                break;
            }

            if ($book->eventSeatsLeft() === 0) {
                break;
            }

            $tierId = $candidate->tier_id === null ? null : (int) $candidate->tier_id;

            if (! $book->fits($candidate->partySize(), $tierId)) {
                continue;
            }

            $tier = $book->tier($tierId);
            $to = $tier !== null && ! $tier->isFree() ? RsvpStatus::Held : RsvpStatus::Going;

            $this->seats->execute($book, $candidate, $to, $candidate->guests, $tierId, promotion: true);

            if ($to === RsvpStatus::Held) {
                // The job requests the checkout, then tells the attendee with its link.
                $this->bus->dispatch((new RequestAdmissionPaymentJob((int) $candidate->getKey(), promotion: true))->afterCommit());
            }

            $promoted[] = $candidate;
        }

        return $promoted;
    }

    private function automatic(SeatBook $book): bool
    {
        $event = $book->event;

        return $event->waitlist_auto_promote
            && $event->phase->isActive()
            && Clock::now()->lessThan($event->starts_at->sub(EventsConfig::stopPromotingBefore()));
    }
}
