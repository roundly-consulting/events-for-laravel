<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;

/**
 * A new venue. `name` is a locale map or a string (the current locale). Without a timezone the
 * venue takes `events.venues.default_timezone`, else the app's.
 */
final readonly class VenueData
{
    /**
     * @param  array<string, string>|string  $name
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public array|string $name,
        public AddressData $address = new AddressData,
        public ?Coordinates $coordinates = null,
        public ?string $timezone = null,
        public ?int $capacity = null,
        public ?Model $owner = null,
        public bool $geocode = false,
        public array $meta = [],
        public ?Model $actor = null,
    ) {}
}
