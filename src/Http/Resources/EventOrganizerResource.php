<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * How an event's organizer renders: its key and its class **basename** — never a class name or morph type.
 * Swap it through `events.resources.organizer` for a resource that adds a name or an avatar; it receives the
 * model.
 *
 * @property Model $resource
 */
final class EventOrganizerResource extends JsonResource
{
    /**
     * @return array{id: mixed, type: string}
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->resource->getKey(), 'type' => class_basename($this->resource)];
    }
}
