<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Enums\InvitationStatus;
use RoundlyConsulting\Events\Enums\RsvpResponse;
use RoundlyConsulting\Events\Enums\Visibility;
use RoundlyConsulting\Events\Exceptions\InvalidGuestCountException;
use RoundlyConsulting\Events\Exceptions\InvitationRequiredException;
use RoundlyConsulting\Events\Exceptions\RsvpClosedException;
use RoundlyConsulting\Events\Exceptions\TierNotFoundException;
use RoundlyConsulting\Events\Exceptions\TierNotOnSaleException;
use RoundlyConsulting\Events\Exceptions\TierRequiredException;
use RoundlyConsulting\Events\Models\AdmissionTier;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\EventInvitation;
use RoundlyConsulting\Events\Models\Rsvp;

/**
 * The RSVP rules, read under the event's lock.
 *
 * @internal
 */
final class RsvpRules
{
    /**
     * When an attendee may answer:
     *
     *  - a draft takes test answers from its managers only;
     *  - cancelled and completed events take nothing;
     *  - a postponed event takes `interested` and `not going` only (its date is open);
     *  - a scheduled or rescheduled event freezes at its start (attendance is a record from
     *    then on), and takes `going` only inside its RSVP window (opening at `rsvp_opens_at`,
     *    closing at `rsvp_closes_at`, else the start).
     *
     * Managers' own verbs (`attendees()`) are not answers and skip these rules.
     */
    public static function assertOpen(Event $event, RsvpResponse $response, bool $manager): void
    {
        $phase = $event->phase;

        if ($phase === EventPhase::Draft) {
            if (! $manager) {
                throw RsvpClosedException::closed();
            }

            return;
        }

        if ($phase->isTerminal()) {
            throw RsvpClosedException::closed();
        }

        if ($phase === EventPhase::Postponed) {
            if ($response === RsvpResponse::Going) {
                throw RsvpClosedException::closed();
            }

            return;
        }

        $now = Clock::now();

        if ($now->greaterThanOrEqualTo($event->starts_at)) {
            throw RsvpClosedException::started();
        }

        if ($response !== RsvpResponse::Going) {
            return;
        }

        if ($event->rsvp_opens_at !== null && $now->lessThan($event->rsvp_opens_at)) {
            throw RsvpClosedException::notOpenYet();
        }

        if ($now->greaterThanOrEqualTo($event->rsvp_closes_at ?? $event->starts_at)) {
            throw RsvpClosedException::closed();
        }
    }

    /**
     * A private event admits pending and accepted invitees (and managers) only — for going and
     * interested; leaving is always allowed.
     */
    public static function assertInvited(Event $event, Model $attendee, RsvpResponse $response, bool $manager): ?EventInvitation
    {
        if ($event->visibility !== Visibility::Private || $response === RsvpResponse::NotGoing) {
            return null;
        }

        $invitation = EventInvitation::query()
            ->where('event_id', $event->getKey())
            ->where('invitee_type', $attendee->getMorphClass())
            ->where('invitee_id', $attendee->getKey())
            ->whereIn('status', [InvitationStatus::Pending->value, InvitationStatus::Accepted->value])
            ->lockForUpdate()
            ->first();

        if ($invitation === null && ! $manager) {
            throw InvitationRequiredException::missing();
        }

        return $invitation;
    }

    /**
     * The ticket type an answer takes: the one named (a model, id or key), or — when it names
     * none — the single visible one on sale. An event without ticket types admits for free.
     * Hidden ticket types are assignable by managers only, and look unknown to everyone else.
     */
    public static function tier(SeatBook $book, AdmissionTier|int|string|null $tier, bool $manager, bool $keepNone = false): ?AdmissionTier
    {
        // An RSVP from before the event had ticket types keeps holding none.
        if ($keepNone) {
            return null;
        }

        if (! $book->hasTiers()) {
            if ($tier !== null) {
                throw TierNotFoundException::missing();
            }

            return null;
        }

        if ($tier === null) {
            $candidates = array_values(array_filter($book->tiers(), static fn (AdmissionTier $candidate): bool => ! $candidate->hidden && $candidate->isOnSale()));

            return count($candidates) === 1 ? $candidates[0] : throw TierRequiredException::missing();
        }

        $found = match (true) {
            $tier instanceof AdmissionTier => $book->tier((int) $tier->getKey()),
            is_int($tier) => $book->tier($tier),
            default => array_values(array_filter($book->tiers(), static fn (AdmissionTier $candidate): bool => $candidate->key === $tier))[0] ?? null,
        };

        if ($found === null || ($found->hidden && ! $manager)) {
            throw TierNotFoundException::missing();
        }

        if (! $manager && ! $found->isOnSale()) {
            throw TierNotOnSaleException::closed();
        }

        return $found;
    }

    /**
     * The party (the attendee plus guests) lies within the ticket type's limits — its maximum
     * replaces the event's `max_guests` — or, without a ticket type, `1 + max_guests`.
     */
    public static function assertParty(Event $event, ?AdmissionTier $tier, int $guests): void
    {
        $min = $tier === null ? 1 : $tier->min_party;
        $max = ($tier === null ? null : $tier->max_party) ?? 1 + $event->max_guests;
        $party = 1 + $guests;

        if ($guests < 0 || $party < $min || $party > $max) {
            throw InvalidGuestCountException::outOfRange($min, $max);
        }
    }

    /**
     * An RSVP of this event — a model (checked) or the attendee's own (locked).
     */
    public static function rsvpOf(Event $event, Rsvp|Model $who): ?Rsvp
    {
        $query = EventModels::rsvp()::query()->where('event_id', $event->getKey())->lockForUpdate();

        return $who instanceof Rsvp
            ? $query->whereKey($who->getKey())->first()
            : $query->where('attendee_type', $who->getMorphClass())->where('attendee_id', $who->getKey())->first();
    }
}
