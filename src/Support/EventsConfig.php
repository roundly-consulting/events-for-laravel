<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use ReflectionClass;
use RoundlyConsulting\Events\Contracts\EventLifecycle as EventLifecycleContract;
use RoundlyConsulting\Events\DataTransferObjects\PosterVariant;
use RoundlyConsulting\Events\Enums\GuestList;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Enums\Visibility;
use RoundlyConsulting\Events\Exceptions\InvalidEventLifecycleException;
use RoundlyConsulting\Events\Exceptions\InvalidEventsConfigurationException;
use RoundlyConsulting\Events\Http\Resources\EventActorResource;
use RoundlyConsulting\Events\Http\Resources\EventAttendeeResource;
use RoundlyConsulting\Events\Http\Resources\EventHostResource;
use RoundlyConsulting\Events\Http\Resources\EventInviteeResource;
use RoundlyConsulting\Events\Http\Resources\EventOrganizerResource;
use RoundlyConsulting\Events\Http\Resources\VenueOwnerResource;
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

    /**
     * The radius near-me searches use when none is given (`events.geo.default_radius_km`).
     */
    public static function defaultRadiusKm(): float
    {
        return Config::float('events.geo.default_radius_km', 25.0, min: 0.001, max: self::maxRadiusKm());
    }

    /**
     * Whose label catalogue an event's labels live in (`events.tags.scope`): null (one global
     * catalogue) or `organizer`.
     */
    public static function tagsScopedByOrganizer(): bool
    {
        $value = config('events.tags.scope');

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return false;
        }

        if ($value === 'organizer') {
            return true;
        }

        throw InvalidEventsConfigurationException::invalidChoice('events.tags.scope', $value, 'null, organizer');
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
     * The Gate ability that creates and manages shared venues (no owner), besides admins; null =
     * admins only.
     */
    public static function sharedVenuesAbility(): ?string
    {
        return self::nullableString(config('events.authorization.abilities.shared_venues'), 'events.authorization.abilities.shared_venues');
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

    public static function stopPromotingBefore(): CarbonInterval
    {
        return self::duration(Config::string('events.waitlist.stop_promoting_before', 'PT1H'), 'events.waitlist.stop_promoting_before');
    }

    public static function holdFor(): CarbonInterval
    {
        return self::duration(Config::string('events.payments.hold_for', 'PT30M'), 'events.payments.hold_for');
    }

    public static function promotionHold(): CarbonInterval
    {
        return self::duration(Config::string('events.payments.promotion_hold', 'PT24H'), 'events.payments.promotion_hold');
    }

    public static function checkInOpensBefore(): CarbonInterval
    {
        return self::duration(Config::string('events.check_in.opens_before', 'PT3H'), 'events.check_in.opens_before');
    }

    public static function checkInClosesAfter(): CarbonInterval
    {
        return self::duration(Config::string('events.check_in.closes_after', 'PT2H'), 'events.check_in.closes_after');
    }

    /**
     * The QR payload template (`…/{code}`), or null for the bare code.
     */
    public static function checkInUrl(): ?string
    {
        $url = self::nullableString(config('events.check_in.url'), 'events.check_in.url');

        if ($url !== null && (! str_contains($url, '{code}') || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true))) {
            throw InvalidEventsConfigurationException::invalidUrlTemplate('events.check_in.url');
        }

        return $url;
    }

    /**
     * The event page template (`events.urls.event`), or null.
     */
    public static function eventUrl(): ?string
    {
        return self::urlTemplate(config('events.urls.event'), 'events.urls.event');
    }

    /**
     * The uuid-only page template for unlisted and private events (`events.urls.event_link`).
     */
    public static function eventLinkUrl(): ?string
    {
        return self::urlTemplate(config('events.urls.event_link'), 'events.urls.event_link');
    }

    public static function invitationUrl(): ?string
    {
        return self::urlTemplate(config('events.urls.invitation'), 'events.urls.invitation');
    }

    public static function rsvpUrl(): ?string
    {
        return self::urlTemplate(config('events.urls.rsvp'), 'events.urls.rsvp');
    }

    public static function jsonLdType(): string
    {
        $type = Config::string('events.seo.json_ld.type', 'Event');

        if (preg_match('/^[A-Za-z]+$/', $type) !== 1) {
            throw InvalidEventsConfigurationException::invalidChoice('events.seo.json_ld.type', $type, 'a schema.org type such as Event or MusicEvent');
        }

        return $type;
    }

    public static function jsonLdForUnlisted(): bool
    {
        return Config::boolean('events.seo.json_ld.unlisted', false);
    }

    public static function jsonLdExposesOnlineUrl(): bool
    {
        return Config::boolean('events.seo.json_ld.expose_online_url', false);
    }

    public static function jsonLdOrganizerType(): string
    {
        $type = Config::string('events.seo.json_ld.organizer_type', 'Organization');

        if (! in_array($type, ['Organization', 'Person'], true)) {
            throw InvalidEventsConfigurationException::invalidChoice('events.seo.json_ld.organizer_type', $type, 'Organization, Person');
        }

        return $type;
    }

    public static function feedKeepCancelledFor(): CarbonInterval
    {
        return self::duration(Config::string('events.feeds.keep_cancelled_for', 'P30D'), 'events.feeds.keep_cancelled_for');
    }

    public static function feedMaxEvents(): int
    {
        return Config::integer('events.feeds.max_events', 500, min: 1, max: 10_000);
    }

    public static function feedName(): string
    {
        return self::nullableString(config('events.feeds.name'), 'events.feeds.name') ?? 'Events';
    }

    private static function urlTemplate(mixed $value, string $key): ?string
    {
        $url = self::nullableString($value, $key);

        if ($url !== null && ! in_array(strtolower((string) parse_url(str_replace(['{', '}'], '', $url), PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw InvalidEventsConfigurationException::invalidUrlTemplate($key);
        }

        return $url;
    }

    public static function routesEnabled(): bool
    {
        return Config::boolean('events.routes.enabled', false);
    }

    public static function routesPrefix(): string
    {
        return trim(Config::string('events.routes.prefix', 'api'), '/');
    }

    /**
     * @return list<string>
     */
    public static function routesMiddleware(): array
    {
        return self::stringList(config('events.routes.middleware'), 'events.routes.middleware', ['api', 'throttle:events-api']);
    }

    /**
     * @return list<string>
     */
    public static function authMiddleware(): array
    {
        return self::stringList(config('events.routes.auth_middleware'), 'events.routes.auth_middleware', ['auth']);
    }

    public static function maxPerPage(): int
    {
        return Config::integer('events.routes.max_per_page', 100, min: 1, max: 1000);
    }

    public static function apiRateLimit(): int
    {
        return Config::integer('events.routes.rate_limits.api', 120, min: 1);
    }

    public static function rsvpRateLimit(): int
    {
        return Config::integer('events.routes.rate_limits.rsvp', 20, min: 1);
    }

    public static function checkInRateLimit(): int
    {
        return Config::integer('events.routes.rate_limits.check_in', 300, min: 1);
    }

    public static function feedsRateLimit(): int
    {
        return Config::integer('events.routes.rate_limits.feeds', 60, min: 1);
    }

    /**
     * @return class-string<JsonResource>
     */
    public static function organizerResource(): string
    {
        return self::resource(config('events.resources.organizer'), 'events.resources.organizer', EventOrganizerResource::class);
    }

    /**
     * @return class-string<JsonResource>
     */
    public static function hostResource(): string
    {
        return self::resource(config('events.resources.host'), 'events.resources.host', EventHostResource::class);
    }

    /**
     * @return class-string<JsonResource>
     */
    public static function attendeeResource(): string
    {
        return self::resource(config('events.resources.attendee'), 'events.resources.attendee', EventAttendeeResource::class);
    }

    /**
     * @return class-string<JsonResource>
     */
    public static function inviteeResource(): string
    {
        return self::resource(config('events.resources.invitee'), 'events.resources.invitee', EventInviteeResource::class);
    }

    /**
     * @return class-string<JsonResource>
     */
    public static function actorResource(): string
    {
        return self::resource(config('events.resources.actor'), 'events.resources.actor', EventActorResource::class);
    }

    /**
     * @return class-string<JsonResource>
     */
    public static function venueOwnerResource(): string
    {
        return self::resource(config('events.resources.venue_owner'), 'events.resources.venue_owner', VenueOwnerResource::class);
    }

    /**
     * A blank value is the default; anything but an instantiable JsonResource subclass throws.
     *
     * @param  class-string<JsonResource>  $default
     * @return class-string<JsonResource>
     */
    private static function resource(mixed $value, string $key, string $default): string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return $default;
        }

        if (! is_string($value) || ! class_exists($value) || ! is_subclass_of($value, JsonResource::class) || ! (new ReflectionClass($value))->isInstantiable()) {
            throw InvalidConfigurationException::notAnImplementation($key, JsonResource::class, $value);
        }

        return $value;
    }

    /**
     * @param  list<string>  $default
     * @return list<string>
     */
    private static function stringList(mixed $value, string $key, array $default): array
    {
        if ($value === null) {
            return $default;
        }

        if (! is_array($value) || array_filter($value, static fn (mixed $item): bool => ! is_string($item) || trim($item) === '') !== []) {
            throw InvalidEventsConfigurationException::invalidChoice($key, $value, 'a list of middleware names');
        }

        return array_values($value);
    }

    public static function notificationsEnabled(): bool
    {
        return Config::boolean('events.notifications.enabled', true);
    }

    /**
     * @return list<string>
     */
    public static function notificationChannels(): array
    {
        return self::stringList(config('events.notifications.channels'), 'events.notifications.channels', ['mail']);
    }

    public static function notificationQueue(): ?string
    {
        return self::nullableString(config('events.notifications.queue'), 'events.notifications.queue');
    }

    public static function notifyActor(): bool
    {
        return Config::boolean('events.notifications.notify_actor', false);
    }

    /**
     * The notification class for a type: the host's (a subclass of the package's), the package's
     * when blank, or null when turned off with `false`.
     *
     * @param  class-string  $default
     * @return class-string|null
     */
    public static function notificationClass(string $type, string $default): ?string
    {
        $types = config('events.notifications.types');
        $value = is_array($types) && array_key_exists($type, $types) ? $types[$type] : null;

        if ($value === false) {
            return null;
        }

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return $default;
        }

        if (! is_string($value) || ! class_exists($value) || ! is_a($value, $default, true)) {
            throw InvalidConfigurationException::notAnImplementation('events.notifications.types.'.$type, $default, $value);
        }

        return $value;
    }

    /**
     * Who hears about an event-wide change of this type, by RSVP status.
     *
     * @param  list<RsvpStatus>  $default
     * @return list<RsvpStatus>
     */
    public static function audience(string $type, array $default): array
    {
        $audiences = config('events.notifications.audiences');
        $value = is_array($audiences) && array_key_exists($type, $audiences) ? $audiences[$type] : null;

        if ($value === null) {
            return $default;
        }

        if (! is_array($value)) {
            throw InvalidEventsConfigurationException::invalidChoice('events.notifications.audiences.'.$type, $value, 'a list of RSVP statuses');
        }

        return array_values(array_map(static fn (mixed $status): RsvpStatus => is_string($status) && RsvpStatus::tryFrom($status) !== null
            ? RsvpStatus::from($status)
            : throw InvalidEventsConfigurationException::invalidChoice('events.notifications.audiences.'.$type, $status, 'going, held, waitlisted, interested'), $value));
    }

    /**
     * @return list<string>
     */
    public static function reminderOffsets(): array
    {
        $offsets = config('events.reminders.offsets');

        if ($offsets === null) {
            return [];
        }

        if (! is_array($offsets)) {
            throw InvalidEventsConfigurationException::invalidDuration('events.reminders.offsets', $offsets);
        }

        $list = [];
        // The per-event bounds (EventInput::reminders()): the sweep looks 32 days ahead, and a
        // reminder minutes before the start reaches nobody in time.
        $minimum = CarbonInterval::minutes(5)->totalSeconds;
        $maximum = CarbonInterval::days(31)->totalSeconds;

        foreach ($offsets as $offset) {
            if (! is_string($offset)) {
                throw InvalidEventsConfigurationException::invalidDuration('events.reminders.offsets', $offset);
            }

            $seconds = self::duration($offset, 'events.reminders.offsets')->totalSeconds;

            if ($seconds < $minimum || $seconds > $maximum) {
                throw InvalidEventsConfigurationException::outOfRange('events.reminders.offsets', $offset, 'PT5M and P31D');
            }

            $list[] = $offset;
        }

        $list = array_values(array_unique($list));

        if (count($list) > EventInput::REMINDERS_MAX) {
            throw InvalidEventsConfigurationException::tooMany('events.reminders.offsets', EventInput::REMINDERS_MAX);
        }

        return $list;
    }

    public static function reminderMaxLateness(): CarbonInterval
    {
        return self::duration(Config::string('events.reminders.max_lateness', 'PT1H'), 'events.reminders.max_lateness');
    }

    /**
     * The local time all-day events are reminded at (`events.reminders.all_day_at`): `[hour, minute]`.
     *
     * @return array{0: int, 1: int}
     */
    public static function allDayReminderAt(): array
    {
        $value = config('events.reminders.all_day_at');
        $value = $value === null || $value === '' ? '09:00' : $value;

        if (! is_string($value) || preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value, $match) !== 1) {
            throw InvalidEventsConfigurationException::invalidTime('events.reminders.all_day_at', $value);
        }

        return [(int) $match[1], (int) $match[2]];
    }
}
