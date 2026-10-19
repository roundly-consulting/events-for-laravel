<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use DateTimeZone;

/**
 * IANA timezone validation. Backward-compatible links (`Europe/Kiev`) are accepted and stored as
 * given.
 *
 * @internal
 */
final class Timezones
{
    public static function isValid(string $timezone): bool
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);
    }
}
