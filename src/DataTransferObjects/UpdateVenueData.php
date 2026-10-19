<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\DataTransferObjects;

use RoundlyConsulting\Events\Support\NotGiven;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;

/**
 * A venue change: only the fields given change. Changing a venue never changes the timezone or
 * instants of its events.
 */
final readonly class UpdateVenueData
{
    /**
     * @param  array<string, string>|string|NotGiven  $name
     * @param  array<string, mixed>|NotGiven  $meta
     */
    public function __construct(
        public array|string|NotGiven $name = NotGiven::Value,
        public AddressData|NotGiven $address = NotGiven::Value,
        public Coordinates|NotGiven|null $coordinates = NotGiven::Value,
        public string|NotGiven $timezone = NotGiven::Value,
        public int|NotGiven|null $capacity = NotGiven::Value,
        public array|NotGiven $meta = NotGiven::Value,
    ) {}
}
