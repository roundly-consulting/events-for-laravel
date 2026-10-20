<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use RoundlyConsulting\Events\DataTransferObjects\TransitionNote;
use RoundlyConsulting\Events\Models\Event;

/**
 * What an events action knows about the transition it applies and lifecycle's after-commit event
 * does not carry: the start before a reschedule, whether the call was silent, and whether it is
 * events' own reschedule (read by the `RescheduledThroughEvents` guard). Held in a scoped
 * container binding (one per request or job), keyed by event, and pulled by the bridge.
 *
 * @internal
 */
final class TransitionNotes
{
    /** @var array<string, TransitionNote> */
    private array $notes = [];

    public function put(Event $event, TransitionNote $note): void
    {
        $this->notes[(string) $event->getKey()] = $note;
    }

    /**
     * The note for `$event`, left in place — for guards that run during the transition.
     */
    public function peek(Event $event): ?TransitionNote
    {
        return $this->notes[(string) $event->getKey()] ?? null;
    }

    /**
     * Keep the note for the after-commit bridge, but no longer as a pass for the reschedule
     * guard: it applied to one transition only.
     */
    public function settle(Event $event): void
    {
        $note = $this->peek($event);

        if ($note !== null && $note->reschedule) {
            $this->put($event, new TransitionNote($note->silent, $note->previousStartsAt, releasedHolds: $note->releasedHolds));
        }
    }

    /**
     * Remember the RSVPs whose holds the transition released — also for a transition applied
     * through lifecycle directly, which put no note.
     *
     * @param  list<int|string>  $ids
     */
    public function released(Event $event, array $ids): void
    {
        $note = $this->peek($event) ?? new TransitionNote;

        $this->put($event, new TransitionNote($note->silent, $note->previousStartsAt, $note->reschedule, [...$note->releasedHolds, ...$ids]));
    }

    public function pull(Event $event): TransitionNote
    {
        $key = (string) $event->getKey();
        $note = $this->notes[$key] ?? new TransitionNote;
        unset($this->notes[$key]);

        return $note;
    }
}
