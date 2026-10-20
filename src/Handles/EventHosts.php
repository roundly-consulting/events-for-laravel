<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Handles;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Actions\Hosts\AddCoHostAction;
use RoundlyConsulting\Events\Actions\Hosts\RemoveCoHostAction;
use RoundlyConsulting\Events\Contracts\Organizer;
use RoundlyConsulting\Events\Enums\EventOperation;
use RoundlyConsulting\Events\Models\EventHost;

/**
 * `Events::for($event)->hosts()` — co-hosts. Adding and removing check `manageHosts` when the
 * handle names an actor: the organizer's users decide, never co-hosts.
 */
final readonly class EventHosts
{
    public function __construct(
        private EventHandle $handle,
    ) {}

    public function add(Model&Organizer $host): self
    {
        $this->handle->authorize('manageHosts');
        $this->handle->manager()->perform(
            EventOperation::AddCoHost,
            AddCoHostAction::class,
            fn (AddCoHostAction $action): bool => $action->execute($this->handle->event(), $host, $this->handle->actor()),
            $this->handle->context(['host' => $host]),
        );

        return $this;
    }

    public function remove(Model $host): self
    {
        $this->handle->authorize('manageHosts');
        $this->handle->manager()->perform(
            EventOperation::RemoveCoHost,
            RemoveCoHostAction::class,
            fn (RemoveCoHostAction $action): bool => $action->execute($this->handle->event(), $host, $this->handle->actor()),
            $this->handle->context(['host' => $host]),
        );

        return $this;
    }

    /**
     * The co-hosts (`view` with an actor: they show on the event page).
     *
     * @return Collection<int, EventHost>
     */
    public function all(): Collection
    {
        $this->handle->authorize('view');

        return EventHost::query()
            ->where('event_id', $this->handle->event()->getKey())
            ->with('host')
            ->orderBy('id')
            ->get();
    }

    public function has(Model $host): bool
    {
        $this->handle->authorize('view');

        return EventHost::query()
            ->where('event_id', $this->handle->event()->getKey())
            ->where('host_type', $host->getMorphClass())
            ->where('host_id', $host->getKey())
            ->exists();
    }
}
