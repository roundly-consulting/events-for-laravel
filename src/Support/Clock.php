<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The package's one notion of "now" and of a stored instant: UTC, `Y-m-d H:i:s`. Scopes bind
 * {@see self::nowUtc()} strings, never an app-timezone Carbon, so a host on
 * `America/New_York` compares the same instants as one on UTC.
 *
 * @internal
 */
final class Clock
{
    public const string FORMAT = 'Y-m-d H:i:s';

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    public static function nowUtc(): string
    {
        return self::now()->format(self::FORMAT);
    }

    public static function format(DateTimeInterface $instant): string
    {
        return CarbonImmutable::instance($instant)->utc()->format(self::FORMAT);
    }
}
