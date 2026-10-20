<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Notifications;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Enums\InvitationStatus;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\EventInvitation;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Support\Clock;
use RoundlyConsulting\Events\Support\EventsConfig;
use RoundlyConsulting\Events\Support\EventVisibility;
use RoundlyConsulting\Events\Support\InvitationTokens;
use RoundlyConsulting\Events\Support\ReminderSchedule;

/**
 * Who hears about an event-wide change: the attendees whose RSVP status is in the type's
 * audience (`events.notifications.audiences`), each once, the actor left out (unless
 * `notify_actor`), only those who may still see the event and can be notified. Re-checked when
 * each notice is delivered: someone who declined a minute later gets no reminder.
 */
final class RecipientResolver
{
    /** @var array<string, list<RsvpStatus>> */
    private const array AUDIENCES = [
        'reminder' => [RsvpStatus::Going],
        'updated' => [RsvpStatus::Going, RsvpStatus::Held],
        'postponed' => [RsvpStatus::Going, RsvpStatus::Held, RsvpStatus::Waitlisted, RsvpStatus::Interested],
        'rescheduled' => [RsvpStatus::Going, RsvpStatus::Held, RsvpStatus::Waitlisted, RsvpStatus::Interested],
        'cancelled' => [RsvpStatus::Going, RsvpStatus::Held, RsvpStatus::Waitlisted, RsvpStatus::Interested],
        'moved_online' => [RsvpStatus::Going, RsvpStatus::Held],
    ];

    /**
     * @return list<RsvpStatus>
     */
    public static function statuses(string $type): array
    {
        return EventsConfig::audience($type, self::AUDIENCES[$type] ?? [RsvpStatus::Going]);
    }

    /**
     * @param  list<int|string>  $released  the RSVPs whose holds a cancellation released
     * @return Collection<int, Model>
     */
    public static function audience(string $type, Event $event, ?Model $actor = null, array $released = []): Collection
    {
        $statuses = array_map(static fn (RsvpStatus $status): string => $status->value, self::statuses($type));

        $rsvps = $event->rsvps()
            ->with('attendee')
            ->where(static function (Builder $query) use ($statuses, $type, $released): void {
                $query->whereIn('status', $statuses);

                // A cancellation released the holds just before: those people are told too.
                if ($type === 'cancelled' && in_array(RsvpStatus::Held->value, $statuses, true) && $released !== []) {
                    $query->orWhere(static fn (Builder $expired): Builder => $expired->where('status', RsvpStatus::Expired->value)
                        ->whereKey($released));
                }
            })
            ->orderBy('id')
            ->get();

        return $rsvps
            ->map(static fn (Rsvp $rsvp): ?Model => $rsvp->attendee)
            ->filter(static fn (?Model $attendee): bool => $attendee !== null && self::notifiable($attendee)
                && ($actor === null || EventsConfig::notifyActor() || ! $attendee->is($actor))
                && EventVisibility::canView($event, $attendee))
            ->unique(static fn (Model $attendee): string => $attendee->getMorphClass().':'.$attendee->getKey())
            ->values();
    }

    /**
     * The invitation is still open — pending, or accepted and sent again (the invitee asked for
     * the link anew) — and still holds the notice's token: a link a later send or an answer made
     * dead is never mailed.
     */
    private static function invitationStillOpen(EventNotification $notification, Event $event, Model $notifiable): bool
    {
        $token = $notification->data['token'] ?? null;
        $query = EventInvitation::query()
            ->where('event_id', $event->getKey())
            ->where('invitee_type', $notifiable->getMorphClass())
            ->where('invitee_id', $notifiable->getKey());

        if (! is_string($token)) {
            // A notice without a link (a host's own): the old rule, pending only.
            return $query->where('status', InvitationStatus::Pending->value)->exists();
        }

        $hash = Container::getInstance()->make(InvitationTokens::class)->hash($token);

        return $hash !== null && $query
            ->whereIn('status', [InvitationStatus::Pending->value, InvitationStatus::Accepted->value])
            ->where('token_hash', $hash)
            ->exists();
    }

    public static function notifiable(object $model): bool
    {
        return method_exists($model, 'notify') && method_exists($model, 'routeNotificationFor');
    }

    /**
     * At delivery: the event still exists and is visible to the recipient, and the recipient's
     * RSVP still puts them in the audience.
     */
    public static function stillApplies(EventNotification $notification, object $notifiable): bool
    {
        if (! $notifiable instanceof Model) {
            return true;
        }

        $event = $notification->event->newQueryWithoutScopes()->whereKey($notification->event->getKey())->whereNull('deleted_at')->first();

        if (! $event instanceof Event || ! EventVisibility::canView($event, $notifiable)) {
            return false;
        }

        $type = $notification->type();

        if ($type === 'invited') {
            return self::invitationStillOpen($notification, $event, $notifiable);
        }

        $rsvp = $event->rsvps()->where('attendee_type', $notifiable->getMorphClass())->where('attendee_id', $notifiable->getKey())->first();
        $status = $rsvp?->status;

        return match ($type) {
            'confirmed' => $status === RsvpStatus::Going,
            'waitlist_promoted' => in_array($status, [RsvpStatus::Going, RsvpStatus::Held], true),
            'reminder' => $status === RsvpStatus::Going
                && in_array($event->phase, [EventPhase::Scheduled, EventPhase::Rescheduled], true)
                && ReminderSchedule::anchor($event)->greaterThan(Clock::now()),
            'cancelled' => $status !== null && ($status === RsvpStatus::Expired || in_array($status, self::statuses($type), true)),
            default => $status !== null && in_array($status, self::statuses($type), true),
        };
    }
}
