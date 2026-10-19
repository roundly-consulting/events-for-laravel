<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use RoundlyConsulting\Events\DataTransferObjects\AddressData;
use RoundlyConsulting\Events\Exceptions\InvalidEventScheduleException;
use RoundlyConsulting\Events\Exceptions\InvalidVenueException;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Translatable\Facades\Translatable;

/**
 * Validation of venue input shared by create and update.
 *
 * @internal
 */
final class VenueInput
{
    /**
     * @param  array<string, string>|string  $name
     * @return array<string, string>
     */
    public static function name(array|string $name): array
    {
        $map = Translatable::fromInput(Text::line($name));

        if ($map === []) {
            throw InvalidVenueException::nameRequired();
        }

        return $map;
    }

    /**
     * Writes the address columns (country upper-cased, blanks as null).
     */
    public static function applyAddress(Venue $venue, AddressData $address): void
    {
        $country = $address->country === null || trim($address->country) === '' ? null : strtoupper(trim($address->country));

        if ($country !== null && preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw InvalidVenueException::invalidCountry($country);
        }

        $venue->street = self::text($address->street);
        $venue->city = self::text($address->city);
        $venue->region = self::text($address->region);
        $venue->postal_code = self::text($address->postalCode);
        $venue->country_code = $country;
        $venue->formatted_address = self::text($address->formatted);
    }

    public static function timezone(?string $timezone): string
    {
        $timezone ??= EventsConfig::venueDefaultTimezone() ?? EventsConfig::defaultTimezone();

        if (! Timezones::isValid($timezone)) {
            throw InvalidEventScheduleException::invalidTimezone($timezone);
        }

        return $timezone;
    }

    public static function capacity(?int $capacity): ?int
    {
        if ($capacity !== null && $capacity < 0) {
            throw InvalidVenueException::negativeCapacity();
        }

        return $capacity;
    }

    private static function text(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
