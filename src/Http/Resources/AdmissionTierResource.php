<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Events\Models\AdmissionTier;

/**
 * A ticket type. `hidden` is shown to managers only (the only ones who ever see hidden types).
 *
 * @property AdmissionTier $resource
 */
final class AdmissionTierResource extends JsonResource
{
    public bool $forManager = false;

    public function forManager(bool $manager = true): self
    {
        $this->forManager = $manager;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tier = $this->resource;
        $timezone = $tier->event->timezone;

        return [
            'id' => $tier->getKey(),
            'key' => $tier->key,
            'name' => $tier->name,
            'description' => $tier->description,
            'price' => $tier->price === null ? null : (new MoneyResource($tier->price))->resolve($request),
            'free' => $tier->isFree(),
            'capacity' => $tier->capacity,
            'seats_left' => $tier->seatsLeft(),
            'on_sale' => $tier->isOnSale(),
            'sale_starts_at' => $tier->sale_starts_at?->setTimezone($timezone)->toIso8601String(),
            'sale_ends_at' => $tier->sale_ends_at?->setTimezone($timezone)->toIso8601String(),
            'min_party' => $tier->min_party,
            'max_party' => $tier->max_party,
            'position' => $tier->position,
            ...($this->forManager ? ['hidden' => $tier->hidden] : []),
        ];
    }
}
