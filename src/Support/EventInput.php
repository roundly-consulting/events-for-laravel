<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Enums\AttendanceMode;
use RoundlyConsulting\Events\Exceptions\InvalidEventException;
use RoundlyConsulting\Translatable\Facades\Translatable;

/**
 * Validation of event input shared by create and update.
 *
 * @internal
 */
final class EventInput
{
    public const int TITLE_MAX = 255;

    public const int DESCRIPTION_MAX = 20_000;

    public const int URL_MAX = 2048;

    public const int REMINDERS_MAX = 5;

    /**
     * @param  array<string, string>|string  $title
     * @return array<string, string>
     */
    public static function title(array|string $title): array
    {
        $map = Translatable::fromInput(Text::line($title));

        if ($map === []) {
            throw InvalidEventException::titleRequired();
        }

        foreach ($map as $line) {
            if (mb_strlen($line) > self::TITLE_MAX) {
                throw InvalidEventException::titleTooLong(self::TITLE_MAX);
            }
        }

        return $map;
    }

    /**
     * @param  array<string, string>|string|null  $description
     * @return array<string, string>|null
     */
    public static function description(array|string|null $description): ?array
    {
        if ($description === null) {
            return null;
        }

        $map = Translatable::fromInput(Text::block($description));

        foreach ($map as $line) {
            if (mb_strlen($line) > self::DESCRIPTION_MAX) {
                throw InvalidEventException::descriptionTooLong(self::DESCRIPTION_MAX);
            }
        }

        return $map === [] ? null : $map;
    }

    public static function onlineUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $url = trim($url);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (strlen($url) > self::URL_MAX || ! in_array($scheme, ['http', 'https'], true)
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw InvalidEventException::invalidOnlineUrl();
        }

        return $url;
    }

    /**
     * The attendance mode the data implies (venue only: offline, link only: online, both:
     * mixed; neither: offline, the place to be announced), or the explicit one when it agrees.
     */
    public static function attendanceMode(?AttendanceMode $explicit, bool $hasVenue, bool $hasUrl): AttendanceMode
    {
        $derived = match (true) {
            $hasVenue && $hasUrl => AttendanceMode::Mixed,
            $hasUrl => AttendanceMode::Online,
            default => AttendanceMode::Offline,
        };

        if ($explicit === null || $explicit === $derived) {
            return $derived;
        }

        throw InvalidEventException::attendanceModeMismatch($explicit->value);
    }

    /**
     * Per-event reminder offsets: at most five ISO-8601 durations, each at least five minutes.
     *
     * @param  list<string>|null  $offsets
     * @return list<string>|null
     */
    public static function reminders(?array $offsets): ?array
    {
        if ($offsets === null) {
            return null;
        }

        if (count($offsets) > self::REMINDERS_MAX) {
            throw InvalidEventException::invalidReminders();
        }

        $minimum = CarbonInterval::minutes(5)->totalSeconds;
        $maximum = CarbonInterval::days(31)->totalSeconds;

        foreach ($offsets as $offset) {
            $interval = Durations::iso($offset);

            if ($interval === null || $interval->totalSeconds < $minimum || $interval->totalSeconds > $maximum) {
                throw InvalidEventException::invalidReminders();
            }
        }

        return array_values(array_unique($offsets));
    }

    public static function nonNegative(?int $value, string $field): ?int
    {
        if ($value !== null && $value < 0) {
            throw InvalidEventException::negative($field);
        }

        return $value;
    }

    /**
     * The organizer must be a model, implement `Contracts\Organizer` or be the actor itself, and
     * fit `events.key_type`.
     */
    public static function organizer(Model $organizer): Model
    {
        KeyTypes::assertMorphable($organizer);

        return $organizer;
    }
}
