<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use RoundlyConsulting\Events\Casts\UtcDateTime;
use RoundlyConsulting\Events\Concerns\HasConfigurableKey;
use RoundlyConsulting\Events\Concerns\StoresUtcTimestamps;
use RoundlyConsulting\Events\Database\Factories\EventFactory;
use RoundlyConsulting\Events\Enums\AttendanceMode;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Enums\GuestList;
use RoundlyConsulting\Events\Enums\Visibility;
use RoundlyConsulting\Events\Support\Clock;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventsConfig;
use RoundlyConsulting\Events\Support\EventSlugs;
use RoundlyConsulting\Events\Support\EventStates;
use RoundlyConsulting\Events\Support\WallClock;
use RoundlyConsulting\Lifecycle\Casts\AsLifecycleState;
use RoundlyConsulting\Lifecycle\Concerns\HasLifecycle;
use RoundlyConsulting\Lifecycle\Contracts\LifecycleSubject;
use RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition;
use RoundlyConsulting\MediaLibrary\Concerns\InteractsWithMedia;
use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;
use RoundlyConsulting\Sluggable\Concerns\HasSlug;
use RoundlyConsulting\Sluggable\Contracts\Sluggable;
use RoundlyConsulting\Sluggable\Definitions\SlugOptions;
use RoundlyConsulting\Translatable\Concerns\HasTranslations;
use RoundlyConsulting\Translatable\Contracts\Translatable;

/**
 * One dated real-world event (timed, all-day or multi-day). Instants are stored in UTC with the
 * event's own IANA timezone; `status` is owned by lifecycle-for-laravel and never written
 * directly; title and description are translatable, the slug is per locale.
 *
 * The model carries reads, relations and scopes. Every write goes through `Events::for($event)`
 * (or `Events::make()`), which keeps seats, counters, notices and the fake right.
 *
 * Swappable through `events.models.event`: extend this class and name the subclass there.
 *
 * @property int|string $id
 * @property string $uuid
 * @property string|null $title
 * @property string|null $description
 * @property string|null $slug
 * @property string $organizer_type
 * @property int|string $organizer_id
 * @property int|null $venue_id
 * @property string|null $online_url
 * @property \BackedEnum|string|null $status the configured lifecycle's state (implements EventState)
 * @property Visibility $visibility
 * @property AttendanceMode $attendance_mode
 * @property string $timezone
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property CarbonImmutable|null $doors_open_at
 * @property CarbonImmutable|null $previous_starts_at
 * @property string|null $starts_on
 * @property string|null $ends_on
 * @property bool $all_day
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $postponed_at
 * @property CarbonImmutable|null $rescheduled_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $moved_online_at
 * @property int|null $capacity
 * @property int $max_guests
 * @property bool $waitlist
 * @property bool $waitlist_auto_promote
 * @property CarbonImmutable|null $rsvp_opens_at
 * @property CarbonImmutable|null $rsvp_closes_at
 * @property GuestList $guest_list
 * @property bool $show_counts
 * @property list<string>|null $reminders
 * @property int $seats_taken
 * @property int $going_count
 * @property int $interested_count
 * @property int $waitlist_count
 * @property int $sequence
 * @property array<string, mixed>|null $meta
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read EventPhase $phase
 * @property-read Venue|null $venue
 * @property-read Model $organizer
 * @property-read Collection<int, AdmissionTier> $tiers
 * @property-read Collection<int, Rsvp> $rsvps
 * @property-read Collection<int, EventHost> $hosts
 * @property-read Collection<int, EventInvitation> $invitations
 */
class Event extends Model implements HasMedia, LifecycleSubject, Sluggable, Translatable
{
    // Both define resolveRouteBindingQuery(); this class overrides it for the uuid route key.
    use HasConfigurableKey, HasSlug {
        HasSlug::resolveRouteBindingQuery insteadof HasConfigurableKey;
        HasSlug::resolveRouteBindingQuery as sluggableRouteBindingQuery;
    }

    /** @use HasFactory<EventFactory> */
    use HasFactory;

    use HasLifecycle;
    use HasTranslations;
    use InteractsWithMedia;
    use SoftDeletes;
    use StoresUtcTimestamps;

    protected $guarded = [];

    /** @var list<string> */
    public array $translatable = ['title', 'description', 'slug'];

    /** @var list<string> */
    protected $hidden = ['online_url', 'meta'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'seats_taken' => 0,
        'going_count' => 0,
        'interested_count' => 0,
        'waitlist_count' => 0,
        'sequence' => 0,
    ];

    protected static function booted(): void
    {
        static::creating(static function (Event $event): void {
            if (blank($event->getAttributes()['uuid'] ?? null)) {
                $event->setAttribute('uuid', (string) Str::uuid7());
            }
        });

        // The local calendar dates are derived, so scopes can match "on this day" in each event's
        // own calendar without database timezone tables. ends_on is the inclusive last day.
        static::saving(static function (Event $event): void {
            $attributes = $event->getAttributes();

            if (! isset($attributes['starts_at'], $attributes['ends_at'])) {
                return;
            }

            $timezone = $event->timezone;
            $event->setAttribute('starts_on', $event->starts_at->setTimezone($timezone)->format('Y-m-d'));
            $event->setAttribute('ends_on', $event->ends_at->subSecond()->setTimezone($timezone)->max($event->starts_at->setTimezone($timezone))->format('Y-m-d'));
        });
    }

    public function getTable(): string
    {
        return EventsConfig::table('events');
    }

    /**
     * @return array<string, class-string<LifecycleDefinition>>
     */
    public function lifecycleDefinitions(): array
    {
        return ['status' => EventsConfig::lifecycle()];
    }

    /**
     * Per-locale slugs from the title: stable once set (a renamed event keeps its links), unique
     * per locale with trashed events included, a sequential suffix on collision.
     */
    public function slugOptions(): SlugOptions
    {
        return SlugOptions::make(EventSlugs::definition());
    }

    /**
     * Events bind by their uuid — never the sequential id or the slug — so an unlisted event
     * stays link-only. Binding applies no visibility rule: authorize `view` in the route.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * A malformed uuid is a 404 without a query (a PostgreSQL uuid column would answer 500).
     *
     * @param  Builder<static>|Relation<static, Model, mixed>  $query
     * @param  mixed  $value
     * @param  string|null  $field
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        if (($field ?? $this->getRouteKeyName()) === 'uuid' && ! (is_string($value) && Str::isUuid($value))) {
            throw (new ModelNotFoundException)->setModel(static::class, [$value]);
        }

        return $this->sluggableRouteBindingQuery($query, $value, $field);
    }

    // Relations ------------------------------------------------------------------------------

    /**
     * The venue — trashed ones included, so past events keep their place in calendars and
     * structured data after the venue is retired.
     *
     * @return BelongsTo<Venue, $this>
     */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(EventModels::venue(), 'venue_id')->withTrashed();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function organizer(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<AdmissionTier, $this>
     */
    public function tiers(): HasMany
    {
        return $this->hasMany(EventModels::tier(), 'event_id')
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * @return HasMany<Rsvp, $this>
     */
    public function rsvps(): HasMany
    {
        return $this->hasMany(EventModels::rsvp(), 'event_id');
    }

    /**
     * @return HasMany<EventHost, $this>
     */
    public function hosts(): HasMany
    {
        return $this->hasMany(EventHost::class, 'event_id');
    }

    /**
     * @return HasMany<EventInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(EventInvitation::class, 'event_id');
    }

    // Reads ----------------------------------------------------------------------------------

    /**
     * The phase of the current state — what every package rule reads.
     *
     * @return Attribute<EventPhase, never>
     */
    protected function phase(): Attribute
    {
        return Attribute::get(fn (): EventPhase => EventStates::phaseOf($this));
    }

    public function startsAtLocal(): CarbonImmutable
    {
        return $this->starts_at->setTimezone($this->timezone);
    }

    public function endsAtLocal(): CarbonImmutable
    {
        return $this->ends_at->setTimezone($this->timezone);
    }

    public function isUpcoming(): bool
    {
        return $this->starts_at->greaterThan(Clock::now());
    }

    public function isOngoing(): bool
    {
        $now = Clock::now();

        return $this->starts_at->lessThanOrEqualTo($now) && $this->ends_at->greaterThan($now);
    }

    public function isPast(): bool
    {
        return $this->ends_at->lessThanOrEqualTo(Clock::now());
    }

    /**
     * Seats left under the event's capacity, or null when the capacity is unlimited.
     */
    public function seatsLeft(): ?int
    {
        return $this->capacity === null ? null : max(0, $this->capacity - $this->seats_taken);
    }

    public function isFull(): bool
    {
        return $this->capacity !== null && $this->seats_taken >= $this->capacity;
    }

    // Time scopes: every comparison binds a UTC string (Clock), never an app-timezone Carbon.

    /**
     * Not started yet.
     *
     * @param  Builder<static>  $query
     */
    public function scopeUpcoming(Builder $query): void
    {
        $query->where($query->qualifyColumn('starts_at'), '>', Clock::nowUtc());
    }

    /**
     * Started and not ended.
     *
     * @param  Builder<static>  $query
     */
    public function scopeOngoing(Builder $query): void
    {
        $now = Clock::nowUtc();

        $query->where($query->qualifyColumn('starts_at'), '<=', $now)
            ->where($query->qualifyColumn('ends_at'), '>', $now);
    }

    /**
     * Ended.
     *
     * @param  Builder<static>  $query
     */
    public function scopePast(Builder $query): void
    {
        $query->where($query->qualifyColumn('ends_at'), '<=', Clock::nowUtc());
    }

    /**
     * Overlapping the range. A Carbon is an instant; a naked string is read in
     * `events.defaults.timezone` (else `app.timezone`).
     *
     * @param  Builder<static>  $query
     */
    public function scopeBetween(Builder $query, CarbonInterface|string $from, CarbonInterface|string $to): void
    {
        $timezone = EventsConfig::defaultTimezone();

        $query->where($query->qualifyColumn('starts_at'), '<', Clock::format(WallClock::toUtc($to, $timezone)))
            ->where($query->qualifyColumn('ends_at'), '>', Clock::format(WallClock::toUtc($from, $timezone)));
    }

    /**
     * Starting inside the range (from inclusive, to exclusive).
     *
     * @param  Builder<static>  $query
     */
    public function scopeStartingBetween(Builder $query, CarbonInterface|string $from, CarbonInterface|string $to): void
    {
        $timezone = EventsConfig::defaultTimezone();

        $query->where($query->qualifyColumn('starts_at'), '>=', Clock::format(WallClock::toUtc($from, $timezone)))
            ->where($query->qualifyColumn('starts_at'), '<', Clock::format(WallClock::toUtc($to, $timezone)));
    }

    /**
     * Taking place on a calendar date — each event on its own local calendar.
     *
     * @param  Builder<static>  $query
     */
    public function scopeOnLocalDate(Builder $query, CarbonInterface|string $date): void
    {
        $day = $date instanceof CarbonInterface ? $date->format('Y-m-d') : CarbonImmutable::parse($date)->format('Y-m-d');

        $query->where($query->qualifyColumn('starts_on'), '<=', $day)
            ->where($query->qualifyColumn('ends_on'), '>=', $day);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AsLifecycleState::class,
            'visibility' => Visibility::class,
            'attendance_mode' => AttendanceMode::class,
            'guest_list' => GuestList::class,
            'online_url' => 'encrypted',
            'starts_at' => UtcDateTime::class,
            'ends_at' => UtcDateTime::class,
            'doors_open_at' => UtcDateTime::class,
            'previous_starts_at' => UtcDateTime::class,
            'published_at' => UtcDateTime::class,
            'postponed_at' => UtcDateTime::class,
            'rescheduled_at' => UtcDateTime::class,
            'cancelled_at' => UtcDateTime::class,
            'completed_at' => UtcDateTime::class,
            'moved_online_at' => UtcDateTime::class,
            'rsvp_opens_at' => UtcDateTime::class,
            'rsvp_closes_at' => UtcDateTime::class,
            'created_at' => UtcDateTime::class,
            'updated_at' => UtcDateTime::class,
            'deleted_at' => UtcDateTime::class,
            'all_day' => 'boolean',
            'capacity' => 'integer',
            'max_guests' => 'integer',
            'waitlist' => 'boolean',
            'waitlist_auto_promote' => 'boolean',
            'show_counts' => 'boolean',
            'reminders' => 'array',
            'seats_taken' => 'integer',
            'going_count' => 'integer',
            'interested_count' => 'integer',
            'waitlist_count' => 'integer',
            'sequence' => 'integer',
            'meta' => 'array',
        ];
    }

    protected static function newFactory(): EventFactory
    {
        return EventFactory::new();
    }
}
