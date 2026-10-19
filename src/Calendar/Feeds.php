<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Calendar;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\DataTransferObjects\EventFeedQuery;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Enums\Visibility;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Support\Clock;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventsConfig;
use RoundlyConsulting\Events\Support\EventStates;
use RoundlyConsulting\Events\Support\Ics\IcsWriter;

/**
 * Subscribable calendars: the public feed (listed public events) and an attendee's own.
 */
final readonly class Feeds
{
    public function __construct(
        private IcsWriter $writer,
    ) {}

    /**
     * Listed public events that are still ahead, plus the ones cancelled within
     * `events.feeds.keep_cancelled_for` (so subscribed calendars receive the cancellation),
     * filtered by the query and capped at `events.feeds.max_events`.
     */
    public function public(EventFeedQuery $query = new EventFeedQuery): IcsCalendar
    {
        $now = Clock::now();
        $events = EventModels::event()::query()
            ->visibleTo(null)
            ->listed()
            ->where(static function (Builder $where) use ($now): void {
                $where->where(static function (Builder $live) use ($now): void {
                    $live->inPhase(EventPhase::Scheduled, EventPhase::Postponed, EventPhase::Rescheduled)
                        ->where($live->qualifyColumn('ends_at'), '>', $now);
                })->orWhere(static function (Builder $cancelled) use ($now): void {
                    $cancelled->inPhase(EventPhase::Cancelled)
                        ->where($cancelled->qualifyColumn('cancelled_at'), '>=', $now->sub(EventsConfig::feedKeepCancelledFor()));
                });
            })
            ->when($query->category !== null, static fn (Builder $events) => $events->inCategory($query->category))
            ->when($query->label !== null, static fn (Builder $events) => $events->labelled($query->label))
            ->when($query->organizer !== null, static fn (Builder $events) => $events->organizedBy($query->organizer))
            ->when($query->venue !== null, static fn (Builder $events) => $events->atVenue($query->venue instanceof Venue ? $query->venue : (int) $query->venue))
            ->with(['venue', 'organizer', 'tags'])
            ->orderBy('starts_at')
            ->limit(EventsConfig::feedMaxEvents())
            ->get();

        return new IcsCalendar($this->writer->write($events, null, $this->headers($query->name ?? EventsConfig::feedName())), 'events.ics', inline: true);
    }

    /**
     * An attendee's going events (theirs to see, so their join links included), private ones only
     * with `$includePrivate`. PHP only: serve it behind your own token URL.
     */
    public function for(Model $attendee, bool $includePrivate = false): IcsCalendar
    {
        $events = EventModels::event()::query()
            ->attendedBy($attendee)
            ->whereIn('status', EventStates::valuesIn(EventPhase::Scheduled, EventPhase::Postponed, EventPhase::Rescheduled, EventPhase::Cancelled, EventPhase::Completed))
            ->when(! $includePrivate, static fn (Builder $events) => $events->where('visibility', '!=', Visibility::Private->value))
            ->where('ends_at', '>', Clock::now()->sub(EventsConfig::feedKeepCancelledFor()))
            ->with(['venue', 'organizer', 'tags'])
            ->orderBy('starts_at')
            ->limit(EventsConfig::feedMaxEvents())
            ->get();

        return new IcsCalendar($this->writer->write($events, $attendee, $this->headers((string) __('events::feeds.personal_name', ['app' => EventsConfig::feedName()]))), 'my-events.ics', inline: true);
    }

    /**
     * @return list<string>
     */
    private function headers(string $name): array
    {
        return [
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->writer->text($name),
            'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
            'X-PUBLISHED-TTL:PT1H',
        ];
    }
}
