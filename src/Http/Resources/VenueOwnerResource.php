<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Events\Support\MorphAliases;

/**
 * How a venue's owner renders: its key and its alias — the one `events.organizers` / `events.attendees` give its
 * class, which request bodies and `alias:key` paths take back — or, for a class in neither map,
 * its class basename. Never a class name or morph type. Swap it through `events.resources.venue_owner`
 * for a resource that adds a name or an avatar; it receives the model.
 *
 * @property Model $resource
 */
final class VenueOwnerResource extends JsonResource
{
    /**
     * @return array{id: mixed, type: string}
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->resource->getKey(), 'type' => MorphAliases::aliasOf($this->resource)];
    }
}
