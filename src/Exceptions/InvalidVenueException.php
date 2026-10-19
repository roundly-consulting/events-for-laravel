<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Exceptions;

/**
 * A venue write the rules refuse.
 */
final class InvalidVenueException extends EventsException
{
    public static function nameRequired(): self
    {
        return self::make('invalid_venue', 422, [], 'venue_name_required');
    }

    public static function invalidCoordinates(): self
    {
        return self::make('invalid_venue', 422, [], 'venue_invalid_coordinates');
    }

    public static function partialCoordinates(): self
    {
        return self::make('invalid_venue', 422, [], 'venue_partial_coordinates');
    }

    public static function invalidCountry(string $country): self
    {
        return self::make('invalid_venue', 422, ['country' => $country], 'venue_invalid_country');
    }

    public static function inUse(): self
    {
        return self::make('venue_in_use', 422, [], 'venue_in_use');
    }

    public static function invalidRadius(string $max): self
    {
        return self::make('invalid_venue', 422, ['max' => $max], 'venue_invalid_radius');
    }

    public static function negativeCapacity(): self
    {
        return self::make('invalid_venue', 422, [], 'venue_negative_capacity');
    }
}
