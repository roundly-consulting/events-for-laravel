<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use RoundlyConsulting\Events\DataTransferObjects\TransitionNote;
use RoundlyConsulting\Events\Models\Event;

/**
 * What an events action knows about the transition it applies and lifecycle's after-commit event
 * does not carry: the start before a reschedule, and whether the call was silent. Held in a
 * scoped container binding (one per request or job), keyed by event, and pulled by the bridge.
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

    public function pull(Event $event): TransitionNote
    {
        $key = (string) $event->getKey();
        $note = $this->notes[$key] ?? new TransitionNote;
        unset($this->notes[$key]);

        return $note;
    }
}
