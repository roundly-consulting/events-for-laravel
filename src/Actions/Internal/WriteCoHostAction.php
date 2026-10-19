<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Internal;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Events\CoHostAdded;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\EventHost;
use RoundlyConsulting\Events\Support\Clock;
use RoundlyConsulting\Events\Support\KeyTypes;

/**
 * Writes one co-host row, idempotently (the unique index decides): true when it was added.
 * The organizer itself is never its own co-host.
 *
 * @internal
 */
final readonly class WriteCoHostAction
{
    public function __construct(
        private Dispatcher $events,
    ) {}

    public function execute(Event $event, Model $host, ?Model $actor = null): bool
    {
        KeyTypes::assertMorphable($host);
        KeyTypes::assertMorphable($actor);

        if ($event->organizer_type === $host->getMorphClass() && (string) $event->organizer_id === (string) $host->getKey()) {
            return false;
        }

        $inserted = EventHost::query()->insertOrIgnore([
            'event_id' => $event->getKey(),
            'host_type' => $host->getMorphClass(),
            'host_id' => $host->getKey(),
            'added_by_type' => $actor?->getMorphClass(),
            'added_by_id' => $actor?->getKey(),
            'created_at' => Clock::nowUtc(),
        ]) > 0;

        if ($inserted) {
            $event->unsetRelation('hosts');
            $this->events->dispatch(new CoHostAdded($event, $host, $actor));
        }

        return $inserted;
    }
}
