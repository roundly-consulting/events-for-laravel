<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Accessors;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Actions\Venues\CreateVenueAction;
use RoundlyConsulting\Events\Actions\Venues\DeleteVenueAction;
use RoundlyConsulting\Events\Actions\Venues\GeocodeVenueAction;
use RoundlyConsulting\Events\Actions\Venues\RestoreVenueAction;
use RoundlyConsulting\Events\Actions\Venues\UpdateVenueAction;
use RoundlyConsulting\Events\Builders\VenueBuilder;
use RoundlyConsulting\Events\DataTransferObjects\UpdateVenueData;
use RoundlyConsulting\Events\DataTransferObjects\VenueData;
use RoundlyConsulting\Events\Enums\EventOperation;
use RoundlyConsulting\Events\EventsManager;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;

/**
 * `Events::venues()` — reusable places. With `by($actor)` every write checks the venue policy.
 */
final readonly class Venues
{
    public function __construct(
        private EventsManager $manager,
        private ?Model $actor = null,
    ) {}

    /**
     * Attribute the following writes to `$actor` and check the venue policy for them.
     */
    public function by(?Model $actor): self
    {
        return new self($this->manager, $actor);
    }

    /**
     * A fluent builder: `->make('Blue Note')->address(...)->timezone('Europe/Bratislava')->create()`.
     *
     * @param  array<string, string>|string  $name
     */
    public function make(array|string $name): VenueBuilder
    {
        return new VenueBuilder($this, $name, $this->actor);
    }

    /**
     * With `by($actor)` the venue policy is checked for that actor — a DTO naming someone else is
     * refused.
     */
    public function create(VenueData $data): Venue
    {
        if ($this->actor !== null) {
            if ($data->actor !== null && ! $data->actor->is($this->actor)) {
                throw new AuthorizationException;
            }

            $data = new VenueData(
                name: $data->name,
                address: $data->address,
                coordinates: $data->coordinates,
                timezone: $data->timezone,
                capacity: $data->capacity,
                owner: $data->owner,
                geocode: $data->geocode,
                meta: $data->meta,
                actor: $this->actor,
            );
        }

        return $this->manager->perform(
            EventOperation::CreateVenue,
            CreateVenueAction::class,
            static fn (CreateVenueAction $action): Venue => $action->execute($data),
            ['data' => $data, 'actor' => $data->actor],
        );
    }

    public function update(Venue $venue, UpdateVenueData $data): Venue
    {
        return $this->manager->perform(
            EventOperation::UpdateVenue,
            UpdateVenueAction::class,
            fn (UpdateVenueAction $action): Venue => $action->execute($venue, $data, $this->actor),
            ['venue' => $venue, 'data' => $data, 'actor' => $this->actor],
        );
    }

    /**
     * Soft-delete a venue — refused while an event that is not over uses it.
     */
    public function delete(Venue $venue): void
    {
        $this->manager->perform(
            EventOperation::DeleteVenue,
            DeleteVenueAction::class,
            fn (DeleteVenueAction $action) => $action->execute($venue, $this->actor),
            ['venue' => $venue, 'actor' => $this->actor],
        );
    }

    public function restore(Venue $venue): Venue
    {
        return $this->manager->perform(
            EventOperation::RestoreVenue,
            RestoreVenueAction::class,
            fn (RestoreVenueAction $action): Venue => $action->execute($venue, $this->actor),
            ['venue' => $venue, 'actor' => $this->actor],
        );
    }

    /**
     * Look the coordinates up through your geolocation pipeline — only when missing, unless forced.
     */
    public function geocode(Venue $venue, bool $force = false): Venue
    {
        return $this->manager->perform(
            EventOperation::GeocodeVenue,
            GeocodeVenueAction::class,
            fn (GeocodeVenueAction $action): Venue => $action->execute($venue, $force, $this->actor),
            ['venue' => $venue, 'force' => $force, 'actor' => $this->actor],
        );
    }

    public function find(int $id): ?Venue
    {
        return EventModels::venue()::query()->find($id);
    }

    /**
     * An organizer's private venue list.
     *
     * @return Builder<Venue>
     */
    public function ownedBy(Model $owner): Builder
    {
        return EventModels::venue()::query()->ownedBy($owner);
    }

    /**
     * Venues within `$km` of a point (the exact great-circle distance).
     *
     * @return Builder<Venue>
     */
    public function near(Coordinates $point, float $km): Builder
    {
        return EventModels::venue()::query()->near($point, $km);
    }
}
