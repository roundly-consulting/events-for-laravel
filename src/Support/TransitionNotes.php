<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use RoundlyConsulting\Events\DataTransferObjects\TransitionNote;
use RoundlyConsulting\Events\Models\Event;

/**
 * What an events action knows about the transition it applies and lifecycle's after-commit event
 * does not carry: the start before a reschedule, whether the call was silent, and whether it is
 * events' own reschedule (read by the `RescheduledThroughEvents` guard). Held in a scoped
 * container binding (one per request or job).
 *
 * The note of the transition under way is keyed by event; once applied it is kept by the
 * transition's history row, so two transitions of one event in one outer transaction each reach
 * the bridge with their own note.
 *
 * @internal
 */
final class TransitionNotes
{
    /** @var array<string, TransitionNote> the transition under way, by event */
    private array $current = [];

    /** @var array<int, TransitionNote> applied transitions awaiting the bridge, by history row */
    private array $applied = [];

    public function put(Event $event, TransitionNote $note): void
    {
        $this->current[(string) $event->getKey()] = $note;
    }

    /**
     * The note of the transition under way for `$event` — for guards that run during it.
     */
    public function peek(Event $event): ?TransitionNote
    {
        return $this->current[(string) $event->getKey()] ?? null;
    }

    /**
     * The transition went through as history row `$historyId`: keep its note for the
     * after-commit bridge, but no longer as a pass for the reschedule guard — it applied to this
     * one transition only.
     */
    public function applied(Event $event, int $historyId): void
    {
        $key = (string) $event->getKey();
        $note = $this->current[$key] ?? null;
        unset($this->current[$key]);

        if ($note !== null) {
            $this->applied[$historyId] = new TransitionNote($note->silent, $note->previousStartsAt, releasedHolds: $note->releasedHolds);
        }
    }

    /**
     * Drop the note of a transition that did not go through (denied, failed).
     */
    public function forget(Event $event): void
    {
        unset($this->current[(string) $event->getKey()]);
    }

    /**
     * Remember the RSVPs whose holds the transition under way released — also for a transition
     * applied through lifecycle directly, which put no note.
     *
     * @param  list<int|string>  $ids
     */
    public function released(Event $event, array $ids): void
    {
        $note = $this->peek($event) ?? new TransitionNote;

        $this->put($event, new TransitionNote($note->silent, $note->previousStartsAt, $note->reschedule, [...$note->releasedHolds, ...$ids]));
    }

    /**
     * The note of history row `$historyId`; for a transition applied through lifecycle directly
     * (no note of its own), what was noted for `$event` meanwhile — or an empty note.
     */
    public function pull(Event $event, ?int $historyId = null): TransitionNote
    {
        if ($historyId !== null && isset($this->applied[$historyId])) {
            $note = $this->applied[$historyId];
            unset($this->applied[$historyId]);

            return $note;
        }

        $key = (string) $event->getKey();
        $note = $this->current[$key] ?? new TransitionNote;
        unset($this->current[$key]);

        return $note;
    }
}
