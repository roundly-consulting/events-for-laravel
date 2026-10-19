<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use RoundlyConsulting\Events\DataTransferObjects\CounterSnapshot;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Models\Event;

/**
 * The denormalised counters as stored, and as recounted from the RSVPs — what `events:repair
 * --counts` compares and the property tests pin.
 *
 * @internal
 */
final class EventCounters
{
    public static function stored(Event $event): CounterSnapshot
    {
        $fresh = $event->newQueryWithoutScopes()->whereKey($event->getKey())->firstOrFail();
        $tiers = [];

        foreach (EventModels::tier()::withTrashed()->where('event_id', $event->getKey())->orderBy('id')->get() as $tier) {
            $tiers[(int) $tier->getKey()] = $tier->seats_taken;
        }

        return new CounterSnapshot($fresh->seats_taken, $fresh->going_count, $fresh->interested_count, $fresh->waitlist_count, $tiers);
    }

    public static function recount(Event $event): CounterSnapshot
    {
        $seats = 0;
        $going = 0;
        $interested = 0;
        $waitlist = 0;
        $tiers = [];

        foreach (EventModels::tier()::withTrashed()->where('event_id', $event->getKey())->orderBy('id')->pluck('id') as $id) {
            $tiers[(int) $id] = 0;
        }

        foreach (EventModels::rsvp()::query()->where('event_id', $event->getKey())->get() as $rsvp) {
            $party = $rsvp->partySize();

            if ($rsvp->status->takesSeats()) {
                $seats += $party;

                if ($rsvp->tier_id !== null) {
                    $tiers[(int) $rsvp->tier_id] = ($tiers[(int) $rsvp->tier_id] ?? 0) + $party;
                }
            }

            $going += $rsvp->status === RsvpStatus::Going ? $party : 0;
            $interested += $rsvp->status === RsvpStatus::Interested ? 1 : 0;
            $waitlist += $rsvp->status === RsvpStatus::Waitlisted ? $party : 0;
        }

        return new CounterSnapshot($seats, $going, $interested, $waitlist, $tiers);
    }
}
