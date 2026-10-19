<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RoundlyConsulting\Events\Exceptions\InvalidEventScheduleException;
use Throwable;

/**
 * Reads time input the way an organizer means it (appointments' `startingAt()` rule):
 *
 *  - a `CarbonInterface`, or a string with an offset or `Z` (`2026-11-14T19:00:00Z`, what
 *    JavaScript's `toISOString()` sends), is an absolute instant;
 *  - a naked string (`2026-11-14 20:00`) is a wall-clock time in the given timezone.
 *
 * A wall-clock time inside a spring-forward gap does not exist and is refused; one inside the
 * fall-back overlap takes the earlier occurrence (PHP alone would take the later one).
 *
 * @internal
 */
final class WallClock
{
    /**
     * The UTC instant `$input` names, reading a naked string in `$timezone`.
     */
    public static function toUtc(CarbonInterface|string $input, string $timezone): CarbonImmutable
    {
        if ($input instanceof CarbonInterface) {
            return CarbonImmutable::instance($input)->utc();
        }

        $input = trim($input);
        $parsed = date_parse($input);

        if ($input === '' || $parsed['error_count'] > 0) {
            throw InvalidEventScheduleException::invalidDateTime($input);
        }

        if (($parsed['is_localtime'] ?? false) === true) {
            return self::parse($input, null)->utc();
        }

        if (isset($parsed['relative']) || $parsed['year'] === false || $parsed['month'] === false || $parsed['day'] === false) {
            return self::parse($input, $timezone)->utc();
        }

        return self::local(
            (int) $parsed['year'],
            (int) $parsed['month'],
            (int) $parsed['day'],
            (int) $parsed['hour'],
            (int) $parsed['minute'],
            (int) $parsed['second'],
            $timezone,
        );
    }

    /**
     * The UTC instant of a local wall-clock time. Refuses a time a DST gap skips; takes the
     * earlier of two occurrences in a fall-back overlap.
     */
    public static function local(int $year, int $month, int $day, int $hour, int $minute, int $second, string $timezone): CarbonImmutable
    {
        $wanted = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);

        if (! checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            throw InvalidEventScheduleException::invalidDateTime($wanted);
        }

        $local = CarbonImmutable::create($year, $month, $day, $hour, $minute, $second, $timezone);

        if (! $local instanceof CarbonImmutable || $local->format(Clock::FORMAT) !== $wanted) {
            throw InvalidEventScheduleException::nonexistentLocalTime(substr($wanted, 0, 16), $timezone);
        }

        // PHP resolves an ambiguous time to the later offset; an earlier instant with the same
        // wall clock (a 30- or 60-minute fall-back) is the first occurrence.
        foreach ([3600, 1800] as $seconds) {
            $earlier = $local->utc()->subSeconds($seconds)->setTimezone($timezone);

            if ($earlier->format(Clock::FORMAT) === $wanted) {
                return $earlier->utc();
            }
        }

        return $local->utc();
    }

    /**
     * The first instant of a local calendar day (`Y-m-d`), as UTC. Carbon's start of day, never a
     * literal 00:00: in zones whose DST switch happens at midnight that day has no 00:00.
     */
    public static function startOfDay(string $date, string $timezone): CarbonImmutable
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', trim($date), $timezone);

        if (! $day instanceof CarbonImmutable || $day->format('Y-m-d') !== trim($date)) {
            throw InvalidEventScheduleException::invalidDateTime($date);
        }

        return $day->startOfDay()->utc();
    }

    private static function parse(string $input, ?string $timezone): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($input, $timezone);
        } catch (Throwable) {
            throw InvalidEventScheduleException::invalidDateTime($input);
        }
    }
}
