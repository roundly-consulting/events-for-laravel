<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Builders;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Accessors\Venues;
use RoundlyConsulting\Events\DataTransferObjects\AddressData;
use RoundlyConsulting\Events\DataTransferObjects\VenueData;
use RoundlyConsulting\Events\Exceptions\InvalidEventScheduleException;
use RoundlyConsulting\Events\Exceptions\InvalidVenueException;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Support\Timezones;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;
use RoundlyConsulting\Geolocation\Exceptions\InvalidCoordinatesException;

/**
 * A fluent builder for a venue; `create()` runs the same code as `Events::venues()->create()`.
 * Setters validate eagerly where they can (coordinates, timezone).
 */
final class VenueBuilder
{
    private AddressData $address;

    private ?Coordinates $coordinates = null;

    private ?string $timezone = null;

    private ?int $capacity = null;

    private ?Model $owner = null;

    private bool $geocode = false;

    /** @var array<string, mixed> */
    private array $meta = [];

    /**
     * @param  array<string, string>|string  $name
     */
    public function __construct(
        private readonly Venues $venues,
        private readonly array|string $name,
        private ?Model $actor = null,
    ) {
        $this->address = new AddressData;
    }

    public function address(
        ?string $street = null,
        ?string $city = null,
        ?string $region = null,
        ?string $postalCode = null,
        ?string $country = null,
    ): self {
        $this->address = new AddressData($street, $city, $region, $postalCode, $country, $this->address->formatted);

        return $this;
    }

    /**
     * The address on one line, when it should read differently from the parts joined.
     */
    public function formattedAddress(string $formatted): self
    {
        $a = $this->address;
        $this->address = new AddressData($a->street, $a->city, $a->region, $a->postalCode, $a->country, $formatted);

        return $this;
    }

    public function coordinates(Coordinates|float $latitude, ?float $longitude = null): self
    {
        if ($latitude instanceof Coordinates) {
            $this->coordinates = $latitude;

            return $this;
        }

        if ($longitude === null) {
            throw InvalidVenueException::partialCoordinates();
        }

        try {
            $this->coordinates = new Coordinates($latitude, $longitude);
        } catch (InvalidCoordinatesException) {
            throw InvalidVenueException::invalidCoordinates();
        }

        return $this;
    }

    /**
     * The IANA timezone every wall-clock time of the venue's events is read in.
     */
    public function timezone(string $timezone): self
    {
        if (! Timezones::isValid($timezone)) {
            throw InvalidEventScheduleException::invalidTimezone($timezone);
        }

        $this->timezone = $timezone;

        return $this;
    }

    public function capacity(?int $capacity): self
    {
        if ($capacity !== null && $capacity < 0) {
            throw InvalidVenueException::negativeCapacity();
        }

        $this->capacity = $capacity;

        return $this;
    }

    /**
     * Put the venue on an organizer's private list.
     */
    public function ownedBy(Model $owner): self
    {
        $this->owner = $owner;

        return $this;
    }

    /**
     * Look the coordinates up from the address when the venue is created (one lookup through your
     * geolocation pipeline; opt-in).
     */
    public function geocode(bool $on = true): self
    {
        $this->geocode = $on;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function meta(array $meta): self
    {
        $this->meta = $meta;

        return $this;
    }

    public function by(?Model $actor): self
    {
        $this->actor = $actor;

        return $this;
    }

    public function toData(): VenueData
    {
        return new VenueData(
            name: $this->name,
            address: $this->address,
            coordinates: $this->coordinates,
            timezone: $this->timezone,
            capacity: $this->capacity,
            owner: $this->owner,
            geocode: $this->geocode,
            meta: $this->meta,
            actor: $this->actor,
        );
    }

    public function create(): Venue
    {
        return $this->venues->create($this->toData());
    }
}
