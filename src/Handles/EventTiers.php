<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Handles;

use Illuminate\Database\Eloquent\Collection;
use RoundlyConsulting\Events\Actions\Tiers\CreateTierAction;
use RoundlyConsulting\Events\Actions\Tiers\DeleteTierAction;
use RoundlyConsulting\Events\Actions\Tiers\ReorderTiersAction;
use RoundlyConsulting\Events\Actions\Tiers\RestoreTierAction;
use RoundlyConsulting\Events\Actions\Tiers\UpdateTierAction;
use RoundlyConsulting\Events\Builders\TierBuilder;
use RoundlyConsulting\Events\DataTransferObjects\TierData;
use RoundlyConsulting\Events\DataTransferObjects\UpdateTierData;
use RoundlyConsulting\Events\Enums\EventOperation;
use RoundlyConsulting\Events\Models\AdmissionTier;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Money\Money;

/**
 * `Events::for($event)->tiers()` — the event's ticket types. Writes check the `manageTiers`
 * ability when the handle names an actor. A ticket type of another event is never found.
 */
final readonly class EventTiers
{
    public function __construct(
        private EventHandle $handle,
    ) {}

    /**
     * A fluent builder: `->make('Early bird')->price(Money::ofMajor('12', 'EUR'))->capacity(50)->create()`.
     *
     * @param  array<string, string>|string  $name
     */
    public function make(array|string $name): TierBuilder
    {
        return new TierBuilder($this, $name);
    }

    public function create(TierData $data): AdmissionTier
    {
        $this->handle->authorize('manageTiers');

        return $this->handle->manager()->perform(
            EventOperation::CreateTier,
            CreateTierAction::class,
            fn (CreateTierAction $action): AdmissionTier => $action->execute($this->handle->event(), $data, $this->handle->actor()),
            $this->handle->context(['data' => $data]),
        );
    }

    /**
     * A ticket type in one call: free without a price; the key derives from the name unless given.
     *
     * @param  array<string, string>|string  $name
     */
    public function add(array|string $name, ?Money $price = null, ?int $capacity = null, bool $hidden = false, ?string $key = null): AdmissionTier
    {
        return $this->create(new TierData(name: $name, key: $key, price: $price, capacity: $capacity, hidden: $hidden));
    }

    public function update(AdmissionTier|int|string $tier, UpdateTierData $data): AdmissionTier
    {
        $this->handle->authorize('manageTiers');

        return $this->handle->manager()->perform(
            EventOperation::UpdateTier,
            UpdateTierAction::class,
            fn (UpdateTierAction $action): AdmissionTier => $action->execute($this->handle->event(), $tier, $data, $this->handle->actor()),
            $this->handle->context(['tier' => $tier, 'data' => $data]),
        );
    }

    /**
     * Delete a ticket type — refused while anyone is going on it, holds its seats or waits.
     */
    public function remove(AdmissionTier|int|string $tier): self
    {
        $this->handle->authorize('manageTiers');
        $this->handle->manager()->perform(
            EventOperation::DeleteTier,
            DeleteTierAction::class,
            fn (DeleteTierAction $action) => $action->execute($this->handle->event(), $tier, $this->handle->actor()),
            $this->handle->context(['tier' => $tier]),
        );

        return $this;
    }

    public function restore(AdmissionTier|int|string $tier): AdmissionTier
    {
        $this->handle->authorize('manageTiers');

        return $this->handle->manager()->perform(
            EventOperation::RestoreTier,
            RestoreTierAction::class,
            fn (RestoreTierAction $action): AdmissionTier => $action->execute($this->handle->event(), $tier, $this->handle->actor()),
            $this->handle->context(['tier' => $tier]),
        );
    }

    /**
     * @param  list<AdmissionTier|int|string>  $order  every live ticket type, first to last
     */
    public function reorder(array $order): self
    {
        $this->handle->authorize('manageTiers');
        $this->handle->manager()->perform(
            EventOperation::ReorderTiers,
            ReorderTiersAction::class,
            fn (ReorderTiersAction $action) => $action->execute($this->handle->event(), $order),
            $this->handle->context(['order' => $order]),
        );

        return $this;
    }

    /**
     * Every live ticket type, in order (hidden ones included).
     *
     * @return Collection<int, AdmissionTier>
     */
    public function all(): Collection
    {
        return EventModels::tier()::query()
            ->where('event_id', $this->handle->event()->getKey())
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /**
     * What an attendee can pick now: visible, on sale, with seats left (or a waitlist to join).
     *
     * @return Collection<int, AdmissionTier>
     */
    public function available(): Collection
    {
        $event = $this->handle->event();
        $eventHasSeats = $event->waitlist || ! $event->isFull();

        return $this->all()->filter(static fn (AdmissionTier $tier): bool => ! $tier->hidden
            && $tier->isOnSale()
            && $eventHasSeats
            && ($event->waitlist || $tier->capacity === null || $tier->seats_taken < $tier->capacity))->values();
    }
}
