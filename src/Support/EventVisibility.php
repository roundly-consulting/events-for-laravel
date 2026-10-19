<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Enums\InvitationStatus;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Enums\Visibility;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\EventInvitation;

/**
 * Who may see an event — one source of truth for `EventPolicy::view()` and the `visibleTo()`
 * scope:
 *
 *  - managers see every event they manage, drafts included;
 *  - a draft is seen by managers only;
 *  - public and unlisted events by anyone (an unlisted one is reached by its uuid link only — it
 *    is never listed, searched or slug-resolved for non-managers);
 *  - private events by managers, pending or accepted invitees, and anyone with an active RSVP.
 *
 * @internal
 */
final class EventVisibility
{
    public static function canView(Event $event, ?Model $viewer): bool
    {
        if (EventManagers::allows($event, $viewer)) {
            return true;
        }

        if ($event->phase === EventPhase::Draft) {
            return false;
        }

        return $event->visibility !== Visibility::Private || self::isInvitedOrAttending($event, $viewer);
    }

    /**
     * Without the uuid link: an unlisted event is found only by managers, invitees and attendees.
     */
    public static function canViewWithoutLink(Event $event, ?Model $viewer): bool
    {
        if (EventManagers::allows($event, $viewer)) {
            return true;
        }

        if ($event->phase === EventPhase::Draft) {
            return false;
        }

        return $event->visibility === Visibility::Public || self::isInvitedOrAttending($event, $viewer);
    }

    public static function isInvitedOrAttending(Event $event, ?Model $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        return EventInvitation::query()
            ->where('event_id', $event->getKey())
            ->where('invitee_type', $viewer->getMorphClass())
            ->where('invitee_id', $viewer->getKey())
            ->whereIn('status', [InvitationStatus::Pending->value, InvitationStatus::Accepted->value])
            ->exists()
            || EventModels::rsvp()::query()
                ->where('event_id', $event->getKey())
                ->where('attendee_type', $viewer->getMorphClass())
                ->where('attendee_id', $viewer->getKey())
                ->whereIn('status', self::activeRsvpStatuses())
                ->exists();
    }

    /**
     * Narrow an event query to what `$viewer` may see (null = a guest) — the SQL form of
     * {@see self::canView()}.
     *
     * @template TEvent of Event
     *
     * @param  Builder<TEvent>  $query
     */
    public static function scope(Builder $query, ?Model $viewer): void
    {
        if (EventManagers::isAdmin($viewer)) {
            return;
        }

        $events = $query->getModel();
        $published = EventStates::valuesIn(...array_values(array_filter(EventPhase::cases(), static fn (EventPhase $phase): bool => $phase !== EventPhase::Draft)));

        $query->where(static function (Builder $visible) use ($events, $published, $viewer): void {
            $visible->where(static fn (Builder $open): Builder => $open
                ->whereIn($events->qualifyColumn('status'), $published)
                ->whereIn($events->qualifyColumn('visibility'), [Visibility::Public->value, Visibility::Unlisted->value]));

            if ($viewer === null) {
                return;
            }

            $visible->orWhere(static fn (Builder $private): Builder => $private
                ->whereIn($events->qualifyColumn('status'), $published)
                ->where($events->qualifyColumn('visibility'), Visibility::Private->value)
                ->where(static fn (Builder $admitted): Builder => $admitted
                    ->whereExists(self::invitationOf($events, $viewer))
                    ->orWhereExists(self::rsvpOf($events, $viewer))));

            $visible->orWhere(static fn (Builder $managed) => EventManagers::scope($managed, $viewer));
        });
    }

    /**
     * @return list<string>
     */
    public static function activeRsvpStatuses(): array
    {
        return [RsvpStatus::Going->value, RsvpStatus::Held->value, RsvpStatus::Waitlisted->value, RsvpStatus::Interested->value];
    }

    /**
     * @return \Closure(QueryBuilder): void
     */
    private static function invitationOf(Model $events, Model $viewer): \Closure
    {
        $table = (new EventInvitation)->getTable();

        return static function (QueryBuilder $invitation) use ($events, $viewer, $table): void {
            $invitation->selectRaw('1')->from($table)
                ->whereColumn($table.'.event_id', $events->getQualifiedKeyName())
                ->where($table.'.invitee_type', $viewer->getMorphClass())
                ->where($table.'.invitee_id', $viewer->getKey())
                ->whereIn($table.'.status', [InvitationStatus::Pending->value, InvitationStatus::Accepted->value]);
        };
    }

    /**
     * @return \Closure(QueryBuilder): void
     */
    private static function rsvpOf(Model $events, Model $viewer): \Closure
    {
        $rsvps = EventModels::rsvp();
        $table = (new $rsvps)->getTable();

        return static function (QueryBuilder $rsvp) use ($events, $viewer, $table): void {
            $rsvp->selectRaw('1')->from($table)
                ->whereColumn($table.'.event_id', $events->getQualifiedKeyName())
                ->where($table.'.attendee_type', $viewer->getMorphClass())
                ->where($table.'.attendee_id', $viewer->getKey())
                ->whereIn($table.'.status', self::activeRsvpStatuses());
        };
    }
}
