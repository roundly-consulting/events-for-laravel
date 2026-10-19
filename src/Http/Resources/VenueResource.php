<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Events\Models\Venue;

/**
 * A venue: name, address, coordinates, timezone, capacity and its owner (a reference resource).
 * Its `meta` stays private.
 *
 * @property Venue $resource
 */
final class VenueResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $venue = $this->resource;
        $coordinates = $venue->coordinates;
        $distance = $venue->getAttribute('distance_km');

        return [
            'id' => $venue->getKey(),
            'name' => $venue->name,
            'slug' => $venue->slug,
            'address' => [
                'street' => $venue->street,
                'city' => $venue->city,
                'region' => $venue->region,
                'postal_code' => $venue->postal_code,
                'country' => $venue->country_code,
                'formatted' => $venue->formattedAddress(),
            ],
            'coordinates' => $coordinates === null ? null : ['latitude' => $coordinates->latitude, 'longitude' => $coordinates->longitude],
            'timezone' => $venue->timezone,
            'capacity' => $venue->capacity,
            'owner' => References::venueOwner($venue->owner, $request),
            'distance_km' => is_numeric($distance) ? round((float) $distance, 3) : null,
            'deleted' => $venue->trashed(),
        ];
    }
}
