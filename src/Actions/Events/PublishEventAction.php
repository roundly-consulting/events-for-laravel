<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Events;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Enums\EventVerb;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Support\EventStates;
use RoundlyConsulting\Events\Support\EventTransitions;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionResult;

/**
 * Announces a draft: it enters the Scheduled phase and auto-completes at `ends_at`. An event that already ended cannot be published.
 *
 * The transition is the configured lifecycle's for `EventVerb::Publish`.
 */
final readonly class PublishEventAction
{
    public function execute(Event $event, ?Model $actor = null, bool $system = false, bool $silent = false): TransitionResult
    {
        return EventTransitions::apply($event, EventStates::transitionFor(EventVerb::Publish), $actor, $system, null, silent: $silent);
    }
}
