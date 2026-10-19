<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Events;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Support\EventTransitions;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionResult;

/**
 * Applies any transition of the configured lifecycle by name — the generic path for a host
 * lifecycle's own steps (an approval, say).
 */
final readonly class TransitionEventAction
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function execute(Event $event, string $transition, ?Model $actor = null, bool $system = false, ?string $reason = null, array $payload = [], bool $silent = false): TransitionResult
    {
        return EventTransitions::apply($event, $transition, $actor, $system, $reason, $payload, $silent);
    }
}
