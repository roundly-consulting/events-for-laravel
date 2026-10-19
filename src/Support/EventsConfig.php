<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Contracts\EventLifecycle as EventLifecycleContract;
use RoundlyConsulting\Events\DataTransferObjects\PosterVariant;
use RoundlyConsulting\Events\Enums\GuestList;
use RoundlyConsulting\Events\Enums\Visibility;
use RoundlyConsulting\Events\Exceptions\InvalidEventLifecycleException;
use RoundlyConsulting\Events\Exceptions\InvalidEventsConfigurationException;
use RoundlyConsulting\Events\Lifecycle\EventLifecycle;
use RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Every `events.*` setting is read here, strictly: a typo throws naming the key, and a blank
 * value means the shipped default.
 *
 * @internal
 */
final class EventsConfig
{
    /**
     * A configured table name (`events.tables.<name>`), validated so it can be interpolated
     * into index names and raw distance SQL.
     */
    public static function table(string $name): string
    {
        [$key, $table] = match ($name) {
            'events' => ['events.tables.events', Config::string('events.tables.events', 'events')],
            'venues' => ['events.tables.venues', Config::string('events.tables.venues', 'event_venues')],
            'tiers' => ['events.tables.tiers', Config::string('events.tables.tiers', 'event_admission_tiers')],
            'rsvps' => ['events.tables.rsvps', Config::string('events.tables.rsvps', 'event_rsvps')],
            'hosts' => ['events.tables.hosts', Config::string('events.tables.hosts', 'event_hosts')],
            'invitations' => ['events.tables.invitations', Config::string('events.tables.invitations', 'event_invitations')],
            'reminder_deliveries' => ['events.tables.reminder_deliveries', Config::string('events.tables.reminder_deliveries', 'event_reminder_deliveries')],
            default => throw InvalidEventsConfigurationException::invalidTable('events.tables.'.$name, $name),
        };

        if (preg_match('/^[a-z_][a-z0-9_]{0,63}$/', $table) !== 1) {
            throw InvalidEventsConfigurationException::invalidTable($key, $table);
        }

        return $table;
    }

    /**
     * The event lifecycle definition (`events.lifecycle`): a `LifecycleDefinition` implementing
     * `Contracts\EventLifecycle`.
     *
     * @return class-string<LifecycleDefinition&EventLifecycleContract>
     */
    public static function lifecycle(): string
    {
        $value = config('events.lifecycle');

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return EventLifecycle::class;
        }

        if (! is_string($value)
            || ! is_subclass_of($value, LifecycleDefinition::class)
            || ! is_subclass_of($value, EventLifecycleContract::class)) {
            throw InvalidEventLifecycleException::notADefinition($value);
        }

        return $value;
    }

    /**
     * Whether event slugs keep a history and redirect from old ones (needs sluggable's
     * `slug_history` table).
     */
    public static function slugHistory(): bool
    {
        return Config::boolean('events.slugs.history', false);
    }

    public static function slugMaxLength(): int
    {
        return Config::integer('events.slugs.max_length', 120, min: 16, max: 255);
    }

    /**
     * A strict ISO-8601 duration from config (`PT2H`, `P1D`).
     */
    public static function duration(string $value, string $key): CarbonInterval
    {
        return Durations::iso($value) ?? throw InvalidEventsConfigurationException::invalidDuration($key, $value);
    }

    /**
     * A nullable IANA timezone from config; blank means "not set".
     */
    public static function timezone(mixed $value, string $key): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        if (! is_string($value) || ! Timezones::isValid($value)) {
            throw InvalidEventsConfigurationException::invalidTimezone($key, $value);
        }

        return $value;
    }

    /**
     * The timezone the package falls back to: `events.defaults.timezone`, else `app.timezone`.
     */
    public static function defaultTimezone(): string
    {
        $app = config('app.timezone');

        return self::timezone(config('events.defaults.timezone'), 'events.defaults.timezone')
            ?? (is_string($app) && Timezones::isValid($app) ? $app : 'UTC');
    }

    public static function defaultDuration(): CarbonInterval
    {
        return self::duration(Config::string('events.defaults.duration', 'PT2H'), 'events.defaults.duration');
    }

    public static function maxDuration(): CarbonInterval
    {
        return self::duration(Config::string('events.schedule.max_duration', 'P31D'), 'events.schedule.max_duration');
    }

    public static function defaultVisibility(): Visibility
    {
        return Config::enum('events.defaults.visibility', Visibility::class, Visibility::Public);
    }

    public static function defaultMaxGuests(): int
    {
        return Config::integer('events.defaults.max_guests', 0, min: 0);
    }

    public static function defaultGuestList(): GuestList
    {
        return Config::enum('events.defaults.guest_list', GuestList::class, GuestList::Hidden);
    }

    public static function defaultShowCounts(): bool
    {
        return Config::boolean('events.defaults.show_counts', true);
    }

    public static function defaultWaitlist(): bool
    {
        return Config::boolean('events.defaults.waitlist', true);
    }

    public static function geocodeOnCreate(): bool
    {
        return Config::boolean('events.venues.geocode_on_create', false);
    }

    public static function venueDefaultTimezone(): ?string
    {
        return self::timezone(config('events.venues.default_timezone'), 'events.venues.default_timezone');
    }

    public static function maxRadiusKm(): float
    {
        return Config::float('events.geo.max_radius_km', 500.0, min: 0.001, max: 20_038.0);
    }

    public static function posterBucket(): string
    {
        return Config::string('events.poster.bucket', 'poster');
    }

    public static function posterDisk(): ?string
    {
        return self::nullableString(config('events.poster.disk'), 'events.poster.disk');
    }

    public static function posterMaxKilobytes(): int
    {
        return Config::integer('events.poster.max_kb', 10240, min: 1);
    }

    public static function posterMinWidth(): int
    {
        return Config::integer('events.poster.min_width', 600, min: 1);
    }

    public static function posterMinHeight(): int
    {
        return Config::integer('events.poster.min_height', 315, min: 1);
    }

    public static function posterFallbackUrl(): ?string
    {
        return self::nullableString(config('events.poster.fallback_url'), 'events.poster.fallback_url');
    }

    /**
     * The poster variants: name => [width, height], each cover-fitted to webp.
     *
     * @return list<PosterVariant>
     */
    public static function posterVariants(): array
    {
        $key = 'events.poster.variants';
        $value = config($key);

        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            throw InvalidConfigurationException::notAList($key, $value);
        }

        $variants = [];

        foreach ($value as $name => $size) {
            if (! is_string($name) || ! is_array($size) || count($size) !== 2
                || ! is_int($size[0] ?? null) || ! is_int($size[1] ?? null) || $size[0] < 1 || $size[1] < 1) {
                throw InvalidConfigurationException::notAValidListItem($key, is_string($name) ? $name : get_debug_type($name));
            }

            $variants[] = new PosterVariant($name, $size[0], $size[1]);
        }

        return $variants;
    }

    public static function registerPolicy(): bool
    {
        return Config::boolean('events.authorization.register_policy', true);
    }

    public static function policy(): ?string
    {
        return self::nullableString(config('events.authorization.policy'), 'events.authorization.policy');
    }

    public static function venuePolicy(): ?string
    {
        return self::nullableString(config('events.authorization.venue_policy'), 'events.authorization.venue_policy');
    }

    /**
     * The Gate ability that may create events, or null for any authenticated user.
     */
    public static function createAbility(): ?string
    {
        return self::nullableString(config('events.authorization.abilities.create'), 'events.authorization.abilities.create');
    }

    /**
     * The Gate ability that manages every event. Undefined in the Gate = nobody.
     */
    public static function adminAbility(): ?string
    {
        return self::nullableString(config('events.authorization.abilities.admin'), 'events.authorization.abilities.admin');
    }

    /**
     * The Gate ability that may create venues, or null for any authenticated user.
     */
    public static function venuesAbility(): ?string
    {
        return self::nullableString(config('events.authorization.abilities.venues'), 'events.authorization.abilities.venues');
    }

    /**
     * The organizer classes the host listed (`events.organizers`, alias => class).
     *
     * @return array<string, class-string<Model>>
     */
    public static function organizers(): array
    {
        return self::aliasMap('events.organizers');
    }

    /**
     * The attendee classes the host listed (`events.attendees`, alias => class).
     *
     * @return array<string, class-string<Model>>
     */
    public static function attendees(): array
    {
        return self::aliasMap('events.attendees');
    }

    /**
     * @return array<string, class-string<Model>>
     */
    private static function aliasMap(string $key): array
    {
        $value = config($key);

        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            throw InvalidConfigurationException::notAList($key, $value);
        }

        $map = [];

        foreach ($value as $alias => $class) {
            // An entry naming a class the host does not have (the shipped App\Models\User in an
            // app without it) is skipped, never fatal.
            if (is_string($alias) && is_string($class) && is_subclass_of($class, Model::class)) {
                $map[$alias] = $class;
            }
        }

        return $map;
    }

    private static function nullableString(mixed $value, string $key): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        if (! is_string($value)) {
            throw InvalidConfigurationException::notAString($key, $value);
        }

        return $value;
    }
}
