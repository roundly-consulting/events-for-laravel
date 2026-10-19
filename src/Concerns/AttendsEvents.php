<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use RoundlyConsulting\Events\Builders\PendingRsvp;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\EventsManager;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventsConfig;

/**
 * Lets a host model RSVP: `$user->rsvp($event)->going()`, `$user->isGoingTo($event)`. Every write
 * goes through the `Events` manager, so the fake sees it.
 *
 * When the model is force-deleted (or deleted without soft deletes) its RSVPs give their seats
 * back and are removed — a deleted account never holds seats. A soft delete keeps them. A mass
 * delete fires no model events: release those with `events:repair --orphans`.
 *
 * @phpstan-require-extends Model
 */
trait AttendsEvents
{
    public static function bootAttendsEvents(): void
    {
        static::deleted(static function (Model $attendee): void {
            $soft = method_exists($attendee, 'isForceDeleting') && ! $attendee->isForceDeleting();

            if (! $soft) {
                app(EventsManager::class)->forgetAttendee($attendee);
            }
        });
    }

    /**
     * @return MorphMany<Rsvp, $this>
     */
    public function eventRsvps(): MorphMany
    {
        return $this->morphMany(EventModels::rsvp(), 'attendee');
    }

    /**
     * The events this model is going to.
     *
     * @return MorphToMany<Event, $this>
     */
    public function attendingEvents(): MorphToMany
    {
        return $this->eventsWithRsvp(RsvpStatus::Going);
    }

    /**
     * The events this model is interested in.
     *
     * @return MorphToMany<Event, $this>
     */
    public function interestingEvents(): MorphToMany
    {
        return $this->eventsWithRsvp(RsvpStatus::Interested);
    }

    public function rsvp(Event $event): PendingRsvp
    {
        return app(EventsManager::class)->for($event)->rsvp($this);
    }

    public function isGoingTo(Event $event): bool
    {
        return $this->rsvpFor($event)?->isGoing() ?? false;
    }

    public function rsvpFor(Event $event): ?Rsvp
    {
        return $this->eventRsvps()->where('event_id', $event->getKey())->first();
    }

    /**
     * @return MorphToMany<Event, $this>
     */
    private function eventsWithRsvp(RsvpStatus $status): MorphToMany
    {
        return $this->morphToMany(EventModels::event(), 'attendee', EventsConfig::table('rsvps'), 'attendee_id', 'event_id')
            ->wherePivot('status', $status->value);
    }
}
