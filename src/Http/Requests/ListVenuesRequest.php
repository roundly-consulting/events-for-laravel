<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Requests;

use RoundlyConsulting\Events\Support\EventsConfig;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;
use RoundlyConsulting\Geolocation\Rules\CoordinatesRule;

/**
 * `GET /event-venues?mine=1&near=lat,lng&radius=&per_page=`.
 *
 * @internal
 */
final class ListVenuesRequest extends EventsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mine' => ['nullable', 'boolean'],
            'near' => ['nullable', 'string', new CoordinatesRule],
            'radius' => ['nullable', 'numeric', 'gt:0', 'max:'.EventsConfig::maxRadiusKm()],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.EventsConfig::maxPerPage()],
        ];
    }

    public function mine(): bool
    {
        return (bool) $this->validated('mine', false);
    }

    public function near(): ?Coordinates
    {
        return self::coordinates($this->validated('near'));
    }

    public function radius(): float
    {
        $radius = $this->validated('radius');

        return is_numeric($radius) ? (float) $radius : EventsConfig::defaultRadiusKm();
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 25);
    }
}
