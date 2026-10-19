<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use Carbon\CarbonInterval;
use Throwable;

/**
 * Durations: strict ISO-8601 for config and stored offsets, lenient (anything
 * `CarbonInterval::make()` reads, `'3 hours'` included) for builder input.
 *
 * @internal
 */
final class Durations
{
    private const string ISO = '/^P(?!$)(\d+Y)?(\d+M)?(\d+W)?(\d+D)?(T(?=\d)(\d+H)?(\d+M)?(\d+S)?)?$/';

    /**
     * A strict ISO-8601 duration, or null when `$value` is not one.
     */
    public static function iso(string $value): ?CarbonInterval
    {
        if (preg_match(self::ISO, $value) !== 1) {
            return null;
        }

        try {
            return new CarbonInterval($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A lenient duration (`PT3H`, `3 hours`, a CarbonInterval), or null when unreadable or not
     * positive.
     */
    public static function lenient(CarbonInterval|string $value): ?CarbonInterval
    {
        if ($value instanceof CarbonInterval) {
            return $value->totalSeconds > 0 ? $value : null;
        }

        try {
            $interval = self::iso($value) ?? CarbonInterval::make($value);
        } catch (Throwable) {
            return null;
        }

        return $interval instanceof CarbonInterval && $interval->totalSeconds > 0 ? $interval : null;
    }
}
