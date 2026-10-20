<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use RoundlyConsulting\Events\Contracts\EventState;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Events\EventCancelled;
use RoundlyConsulting\Events\Events\EventCompleted;
use RoundlyConsulting\Events\Events\EventPostponed;
use RoundlyConsulting\Events\Events\EventPublished;
use RoundlyConsulting\Events\Events\EventRescheduled;
use RoundlyConsulting\Events\Events\EventStatusChanged;
use RoundlyConsulting\Events\Events\EventUnpublished;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\TransitionNotes;
use RoundlyConsulting\Lifecycle\Enums\TransitionKind;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitioned;
use RoundlyConsulting\Lifecycle\Support\TransitionModel;

/**
 * The bridge from lifecycle to the package's own events, after commit, once per history row:
 * `EventStatusChanged`, then the phase event — `EventPublished`, `EventUnpublished`,
 * `EventPostponed`, `EventRescheduled`, `EventCancelled`, `EventCompleted`. Lifecycle fires
 * nothing for the initial row and carries no reason, so the reason is read from the history row.
 * Rollbacks and adoptions fire `EventStatusChanged` only.
 *
 * @internal
 */
final readonly class OnEventTransitioned
{
    public function __construct(
        private Dispatcher $events,
        private TransitionNotes $notes,
    ) {}

    public function handle(LifecycleTransitioned $transitioned): void
    {
        $event = $transitioned->subject;

        if (! $event instanceof Event || ! is_a($event, EventModels::event()) || $transitioned->lifecycle !== 'status'
            || $transitioned->kind === TransitionKind::Initial) {
            return;
        }

        $note = $this->notes->pull($event);
        $actor = $transitioned->actor;

        $this->events->dispatch(new EventStatusChanged($event, $transitioned->transition, $transitioned->from, $transitioned->to, $actor, $note->silent));

        if (! in_array($transitioned->kind, [TransitionKind::Transition, TransitionKind::Expiry, TransitionKind::Scheduled], true)
            || ! $transitioned->to instanceof EventState) {
            return;
        }

        $from = $transitioned->from instanceof EventState ? $transitioned->from->phase() : null;
        $to = $transitioned->to->phase();

        $phaseEvent = match ($to) {
            EventPhase::Scheduled => $from === EventPhase::Draft ? new EventPublished($event, $actor) : null,
            EventPhase::Draft => $from !== null && $from !== EventPhase::Draft ? new EventUnpublished($event, $actor) : null,
            EventPhase::Postponed => new EventPostponed($event, $this->reason($transitioned), $actor, $note->silent),
            EventPhase::Rescheduled => new EventRescheduled(
                $event,
                $note->previousStartsAt ?? $event->previous_starts_at ?? $event->starts_at,
                $this->reason($transitioned),
                $actor,
                $note->silent,
            ),
            EventPhase::Cancelled => new EventCancelled($event, $this->reason($transitioned), $actor, $note->silent, $note->releasedHolds),
            EventPhase::Completed => new EventCompleted($event, $actor),
        };

        if ($phaseEvent !== null) {
            $this->events->dispatch($phaseEvent);
        }
    }

    private function reason(LifecycleTransitioned $transitioned): ?string
    {
        $reason = TransitionModel::class()::query()->whereKey($transitioned->historyId)->value('reason');

        return is_string($reason) && $reason !== '' ? $reason : null;
    }
}
