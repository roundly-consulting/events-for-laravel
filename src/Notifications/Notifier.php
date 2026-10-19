<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Notifications;

use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Support\EventsConfig;
use Throwable;

/**
 * Sends one notice type — the host's class when configured, nothing when the type or all
 * notifications are off. Runs after the commit; a failing notice is reported, never thrown
 * back into the write that caused it.
 *
 * @internal
 */
final readonly class Notifier
{
    /** @var array<string, class-string<EventNotification>> */
    private const array CLASSES = [
        'invited' => EventInvitationNotification::class,
        'confirmed' => RsvpConfirmedNotification::class,
        'waitlist_promoted' => WaitlistPromotedNotification::class,
        'reminder' => EventReminderNotification::class,
        'updated' => EventUpdatedNotification::class,
        'postponed' => EventPostponedNotification::class,
        'rescheduled' => EventRescheduledNotification::class,
        'cancelled' => EventCancelledNotification::class,
        'moved_online' => EventMovedOnlineNotification::class,
    ];

    public function __construct(
        private Dispatcher $notifications,
    ) {}

    /**
     * @param  iterable<Model>  $recipients
     * @param  array<string, scalar|list<string>|null>  $data
     * @return int how many were notified
     */
    public function send(string $type, Event $event, iterable $recipients, ?Rsvp $rsvp = null, array $data = []): int
    {
        if (! EventsConfig::notificationsEnabled()) {
            return 0;
        }

        /** @var class-string<EventNotification>|null $class */
        $class = EventsConfig::notificationClass($type, self::CLASSES[$type]);

        if ($class === null) {
            return 0;
        }

        $list = [];

        foreach ($recipients as $recipient) {
            if (RecipientResolver::notifiable($recipient)) {
                $list[] = $recipient;
            }
        }

        if ($list === []) {
            return 0;
        }

        try {
            $this->notifications->send($list, new $class($event, $rsvp, $data));
        } catch (Throwable $exception) {
            report($exception);
        }

        return count($list);
    }
}
