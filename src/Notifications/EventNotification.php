<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;
use RoundlyConsulting\Events\Contracts\EventUrls;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Support\EventsConfig;

/**
 * The base of every events notice: queued, sent only after the commit, re-checked when it is
 * delivered (the recipient may have declined, the event may have become private), written in
 * the recipient's locale with times in the event's own timezone. Never carries a class name or
 * another attendee's data.
 *
 * Swap a type through `events.notifications.types.<type>` with a subclass of the package's
 * class, e.g. to add a line to `toMail()`.
 */
abstract class EventNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, scalar|list<string>|null>  $data  plain values only (reason, offset, …)
     */
    public function __construct(
        public Event $event,
        public ?Rsvp $rsvp = null,
        public array $data = [],
    ) {
        $this->afterCommit();
        $queue = EventsConfig::notificationQueue();

        if ($queue !== null) {
            $this->onQueue($queue);
        }
    }

    /**
     * The type key: `cancelled`, `reminder`, …
     */
    abstract public function type(): string;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return EventsConfig::notificationChannels();
    }

    /**
     * Re-checked at delivery: still allowed to see the event, still in the audience.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return RecipientResolver::stillApplies($this, $notifiable);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->line('subject'))
            ->greeting($this->line('greeting'));

        foreach ($this->lines($notifiable) as $line) {
            $message->line($line);
        }

        $action = $this->action($notifiable);

        if ($action !== null) {
            $message->action($action[0], $action[1]);
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type(),
            'event' => [
                'id' => $this->event->uuid,
                'title' => $this->event->title,
                'starts_at' => $this->event->starts_at->setTimezone($this->event->timezone)->toIso8601String(),
                'timezone' => $this->event->timezone,
                'all_day' => $this->event->all_day,
            ],
            ...$this->payload(),
        ];
    }

    /**
     * The body lines, after the greeting.
     *
     * @return list<string>
     */
    protected function lines(object $notifiable): array
    {
        return [$this->line('body')];
    }

    /**
     * Type-specific values for the database channel.
     *
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [];
    }

    /**
     * The button: the event page, when the app has one.
     *
     * @return array{0: string, 1: string}|null
     */
    protected function action(object $notifiable): ?array
    {
        $url = App::make(EventUrls::class)->event($this->event);

        return $url === null ? null : [(string) __('events::notifications.view_event'), $url];
    }

    /**
     * A translated line of this type, with the event's title and local time.
     *
     * @param  array<string, string|int>  $replace
     */
    protected function line(string $key, array $replace = []): string
    {
        return (string) __('events::notifications.'.$this->type().'.'.$key, [
            'title' => (string) $this->event->title,
            'when' => $this->when(),
            ...$replace,
        ]);
    }

    /**
     * A time in the event's timezone, with its abbreviation, in the recipient's locale. Slovak
     * uses `LL LT`: Carbon's Slovak `LLL` drops the year.
     */
    protected function when(?CarbonImmutable $moment = null): string
    {
        $locale = App::getLocale();
        $local = ($moment ?? $this->event->starts_at)->setTimezone($this->event->timezone)->settings(['locale' => $locale]);

        if ($this->event->all_day) {
            return $local->isoFormat('LL');
        }

        return $local->isoFormat(str_starts_with($locale, 'sk') ? 'LL LT' : 'LLL').' '.$local->format('T');
    }
}
