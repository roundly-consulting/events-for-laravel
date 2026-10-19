<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use Carbon\CarbonInterval;
use RoundlyConsulting\Events\Contracts\EventLifecycle as EventLifecycleContract;
use RoundlyConsulting\Events\Exceptions\InvalidEventLifecycleException;
use RoundlyConsulting\Events\Exceptions\InvalidEventsConfigurationException;
use RoundlyConsulting\Events\Lifecycle\EventLifecycle;
use RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition;
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
    public static function duration(string $key, string $default): CarbonInterval
    {
        $value = Config::string($key, $default);

        return Durations::iso($value) ?? throw InvalidEventsConfigurationException::invalidDuration($key, $value);
    }

    /**
     * A nullable IANA timezone from config; blank means "not set".
     */
    public static function timezone(string $key): ?string
    {
        $value = config($key);

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

        return self::timezone('events.defaults.timezone')
            ?? (is_string($app) && Timezones::isValid($app) ? $app : 'UTC');
    }
}
