<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Hosts;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Actions\Internal\WriteCoHostAction;
use RoundlyConsulting\Events\Contracts\Organizer;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\LockedEvent;

/**
 * Adds a co-host — another organizer (a user, a team) whose approved users manage the event.
 * Idempotent. Only the organizer's users (and admins) add co-hosts: co-hosts manage everything
 * else, never the co-host list.
 */
final readonly class AddCoHostAction
{
    public function __construct(
        private WriteCoHostAction $hosts,
    ) {}

    public function execute(Event $event, Model&Organizer $host, ?Model $actor = null): bool
    {
        return EventTransaction::run($event, function () use ($event, $host, $actor): bool {
            LockedEvent::lock($event);

            return $this->hosts->execute($event, $host, $actor);
        });
    }
}
