<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Policies;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Support\EventManagers;
use RoundlyConsulting\Events\Support\EventsConfig;

/**
 * Who creates and manages venues. Anyone sees a venue. An owned venue (an organizer's private
 * list) is managed by the users its owner allows; a shared one by whoever may create venues
 * (`events.authorization.abilities.venues`, null = any authenticated user). Admins manage all.
 *
 * Not final on purpose: a host policy extends it.
 */
class VenuePolicy
{
    public function viewAny(?Model $user): bool
    {
        return true;
    }

    public function view(?Model $user, Venue $venue): bool
    {
        return true;
    }

    public function create(?Model $user): bool
    {
        if ($user === null) {
            return false;
        }

        $ability = EventsConfig::venuesAbility();

        return $ability === null
            || ($user instanceof Authenticatable && App::make(Gate::class)->forUser($user)->allows($ability));
    }

    public function update(?Model $user, Venue $venue): bool
    {
        return $this->manages($user, $venue);
    }

    public function delete(?Model $user, Venue $venue): bool
    {
        return $this->manages($user, $venue);
    }

    public function restore(?Model $user, Venue $venue): bool
    {
        return $this->manages($user, $venue);
    }

    public function geocode(?Model $user, Venue $venue): bool
    {
        return $this->manages($user, $venue);
    }

    protected function manages(?Model $user, Venue $venue): bool
    {
        if ($user === null) {
            return false;
        }

        if (EventManagers::isAdmin($user)) {
            return true;
        }

        $owner = $venue->owner;

        return $owner === null ? $this->create($user) : EventManagers::modelAllows($owner, $user);
    }
}
