<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\DataTransferObjects\CheckInResult;
use RoundlyConsulting\Events\Enums\CheckInMethod;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Exceptions\CheckInRefusedException;
use RoundlyConsulting\Events\Exceptions\InvalidCheckInCodeException;
use RoundlyConsulting\Events\Exceptions\InvalidGuestCountException;
use RoundlyConsulting\Events\Exceptions\RsvpNotFoundException;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use SensitiveParameter;

/**
 * The idempotent check-in write: a compare-and-swap on `checked_in_at IS NULL`, so of two
 * scanners reading the same code exactly one is first. A later scan with more guests (a friend
 * arriving late) raises `checked_in_guests` with its own compare-and-swap, never lowers it, and
 * still reports `firstCheckIn: false`.
 *
 * Both writes are also conditional on the RSVP still being going — and, for a scan, on it still
 * holding the scanned code — because the RSVP was read without a lock: a decline, a removal or a
 * code rotation that commits in between makes the write change nothing, and the re-read names
 * why. A checked-in RSVP is never one that left.
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

    /**
     * @param  string|null  $codeHash  the scanned code's hash: the write needs the RSVP to still hold it
     */
    public static function write(Rsvp $rsvp, ?int $guests, CheckInMethod $method, ?Model $by, #[SensitiveParameter] ?string $codeHash = null): CheckInResult
    {
        $guests ??= $rsvp->guests;

        if ($guests < 0 || $guests > $rsvp->guests) {
            throw InvalidGuestCountException::outOfRange(0, $rsvp->guests);
        }

        KeyTypes::assertMorphable($by);
        $query = EventModels::rsvp()::query()->whereKey($rsvp->getKey())->where('status', RsvpStatus::Going->value);

        if ($codeHash !== null) {
            $query->where('check_in_code_hash', $codeHash);
        }

        $first = (clone $query)->whereNull('checked_in_at')->toBase()->update([
            'checked_in_at' => Clock::nowUtc(),
            'checked_in_guests' => $guests,
            'check_in_method' => $method->value,
            'checked_in_by_type' => $by?->getMorphClass(),
            'checked_in_by_id' => $by?->getKey(),
        ]) === 1;

        if (! $first && (clone $query)->where('checked_in_guests', '<', $guests)->toBase()->update(['checked_in_guests' => $guests]) === 0) {
            self::assertStillHolds($rsvp, $codeHash);
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

    /**
     * Neither write changed a row: the RSVP left going, or lost the scanned code, in the meantime
     * — or it simply is checked in already (with at least as many guests).
     */
    private static function assertStillHolds(Rsvp $rsvp, ?string $codeHash): void
    {
        $current = EventModels::rsvp()::query()->whereKey($rsvp->getKey())->first();

        if ($current === null || $current->status !== RsvpStatus::Going) {
            throw CheckInRefusedException::because(CheckInRefusedException::NOT_GOING);
        }

        if ($codeHash !== null && ! hash_equals((string) $current->check_in_code_hash, $codeHash)) {
            throw InvalidCheckInCodeException::unknown();
        }
    }
}
