<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Events;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Events\EventDeleted;
use RoundlyConsulting\Events\Exceptions\InvalidEventException;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\LockedEvent;

/**
 * Deletes an event — refused while an announced event that is not over has people going or
 * holding seats (postpone or cancel it, so they are told). A soft delete keeps everything for a
 * restore; a force delete also removes the poster (its files go after commit) — media-library
 * removes nothing on its own when the owner goes.
 */
final readonly class DeleteEventAction
{
    public function __construct(
        private Dispatcher $events,
    ) {}

    public function execute(Event $event, ?Model $actor = null, bool $force = false): void
    {
        EventTransaction::run($event, function () use ($event, $actor, $force): void {
            LockedEvent::lock($event, allowTrashed: $force);

            $phase = $event->phase;

            if ($phase->isPublished() && ! $phase->isTerminal() && $this->hasAttendees($event)) {
                throw InvalidEventException::hasAttendees();
            }

            // A force delete takes the poster with it (the model's forceDeleting hook).
            $force ? $event->forceDelete() : $event->delete();

            $this->events->dispatch(new EventDeleted($event, $actor, $force));
        });
    }

    private function hasAttendees(Event $event): bool
    {
        return EventModels::rsvp()::query()
            ->where('event_id', $event->getKey())
            ->whereIn('status', [RsvpStatus::Going->value, RsvpStatus::Held->value])
            ->exists();
    }
}
