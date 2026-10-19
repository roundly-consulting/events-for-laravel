<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\DataTransferObjects\CheckInResult;
use RoundlyConsulting\Events\Enums\CheckInMethod;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Exceptions\CheckInRefusedException;
use RoundlyConsulting\Events\Exceptions\InvalidGuestCountException;
use RoundlyConsulting\Events\Exceptions\RsvpNotFoundException;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;

/**
 * The idempotent check-in write: a compare-and-swap on `checked_in_at IS NULL`, so of two
 * scanners reading the same code exactly one is first. A later scan with more guests (a friend
 * arriving late) raises `checked_in_guests` with its own compare-and-swap, never lowers it, and
 * still reports `firstCheckIn: false`.
 *
 * @internal
 */
final class CheckInWriter
{
    /**
     * Cancelled, postponed and draft events take no check-in. A completed one still does: it
     * completes at its end, and the door stays open for `check_in.closes_after`.
     */
    public static function assertActive(Event $event): void
    {
        if ($event->trashed() || in_array($event->phase, [EventPhase::Draft, EventPhase::Postponed, EventPhase::Cancelled], true)) {
            throw CheckInRefusedException::because(CheckInRefusedException::EVENT_NOT_ACTIVE);
        }
    }

    public static function assertWindow(Event $event): void
    {
        $now = Clock::now();
        $opens = $event->starts_at->sub(EventsConfig::checkInOpensBefore());
        $closes = $event->ends_at->add(EventsConfig::checkInClosesAfter());

        if ($now->lessThan($opens) || $now->greaterThan($closes)) {
            throw CheckInRefusedException::because(CheckInRefusedException::OUTSIDE_WINDOW);
        }
    }

    public static function write(Rsvp $rsvp, ?int $guests, CheckInMethod $method, ?Model $by): CheckInResult
    {
        $guests ??= $rsvp->guests;

        if ($guests < 0 || $guests > $rsvp->guests) {
            throw InvalidGuestCountException::outOfRange(0, $rsvp->guests);
        }

        KeyTypes::assertMorphable($by);
        $query = EventModels::rsvp()::query()->whereKey($rsvp->getKey());

        $first = (clone $query)->whereNull('checked_in_at')->toBase()->update([
            'checked_in_at' => Clock::nowUtc(),
            'checked_in_guests' => $guests,
            'check_in_method' => $method->value,
            'checked_in_by_type' => $by?->getMorphClass(),
            'checked_in_by_id' => $by?->getKey(),
        ]) === 1;

        if (! $first) {
            (clone $query)->where('checked_in_guests', '<', $guests)->toBase()->update(['checked_in_guests' => $guests]);
        }

        $rsvp->refresh();

        return new CheckInResult(
            $rsvp,
            $rsvp->attendee ?? throw RsvpNotFoundException::missing(),
            $first,
            (int) $rsvp->checked_in_guests,
            $rsvp->checked_in_at ?? Clock::now(),
            $rsvp->check_in_method ?? $method,
        );
    }
}
