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
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;
use RoundlyConsulting\Events\Casts\UtcDateTime;
use RoundlyConsulting\Events\Concerns\HasConfigurableKey;
use RoundlyConsulting\Events\Concerns\StoresUtcTimestamps;
use RoundlyConsulting\Events\Database\Factories\EventFactory;
use RoundlyConsulting\Events\Enums\AttendanceMode;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Enums\EventTagType;
use RoundlyConsulting\Events\Enums\GuestList;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Enums\Visibility;
use RoundlyConsulting\Events\Exceptions\InvalidEventException;
use RoundlyConsulting\Events\Support\Clock;
use RoundlyConsulting\Events\Support\DistanceExpression;
use RoundlyConsulting\Events\Support\EventManagers;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventsConfig;
use RoundlyConsulting\Events\Support\EventSlugs;
use RoundlyConsulting\Events\Support\EventStates;
use RoundlyConsulting\Events\Support\EventVisibility;
use RoundlyConsulting\Events\Support\Posters;
use RoundlyConsulting\Events\Support\WallClock;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;
use RoundlyConsulting\Lifecycle\Casts\AsLifecycleState;
use RoundlyConsulting\Lifecycle\Concerns\HasLifecycle;
use RoundlyConsulting\Lifecycle\Contracts\LifecycleSubject;
use RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition;
use RoundlyConsulting\MediaLibrary\Concerns\InteractsWithMedia;
use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Variants\VariantRegistrar;
use RoundlyConsulting\PackageToolkit\Support\RawExpression;
use RoundlyConsulting\Sluggable\Concerns\HasSlug;
use RoundlyConsulting\Sluggable\Contracts\Sluggable;
use RoundlyConsulting\Sluggable\Definitions\SlugOptions;
use RoundlyConsulting\Tags\Concerns\HasTags;
use RoundlyConsulting\Tags\Contracts\Taggable;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Translatable\Concerns\HasTranslations;
use RoundlyConsulting\Translatable\Contracts\Translatable;
use RoundlyConsulting\Translatable\DataTransferObjects\TranslationSearch;
use RoundlyConsulting\Translatable\Facades\Translatable as Translations;

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
 * @property-read Tag|null $category the event's category (in memory when `tags` is loaded)
 * @property-read Collection<int, Tag> $labels the event's labels (in memory when `tags` is loaded)
 * @property-read float|null $distance_km set by `withDistance()`; null for an event without a venue
 * @property-read Media|null $poster
 * @property-read Venue|null $venue
 * @property-read Model|null $organizer the organizer (null once its row is gone)
 * @property-read Collection<int, AdmissionTier> $tiers
 * @property-read Collection<int, Rsvp> $rsvps
 * @property-read Collection<int, EventHost> $hosts
 * @property-read Collection<int, EventInvitation> $invitations
 */
class Event extends Model implements HasMedia, LifecycleSubject, Sluggable, Taggable, Translatable
{
    // Both define resolveRouteBindingQuery(); this class overrides it for the uuid route key.
    use HasConfigurableKey, HasSlug {
        HasSlug::resolveRouteBindingQuery insteadof HasConfigurableKey;
        HasSlug::resolveRouteBindingQuery as sluggableRouteBindingQuery;
    }

    /** @use HasFactory<EventFactory> */
    use HasFactory;

    use HasLifecycle;
    use HasTags;
    use HasTranslations;
    use InteractsWithMedia;
    use SoftDeletes;
    use StoresUtcTimestamps;

    protected $guarded = [];

    /** @var list<string> */
    public array $translatable = ['title', 'description', 'slug'];

    /** @var list<string> */
    protected $hidden = ['organizer_type', 'online_url', 'meta'];

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

        // media-library removes nothing when its owner goes: a force delete takes the poster with it
        // (a soft delete keeps it for a restore).
        static::forceDeleting(static fn (Event $event) => Posters::clear($event));

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

    /**
     * The event's category, or null (`events:category`, one per event).
     *
     * @return Attribute<Tag|null, never>
     */
    protected function category(): Attribute
    {
        return Attribute::get(fn (): ?Tag => $this->tagOf(EventTagType::Category));
    }

    /**
     * The event's free-form labels (`events:label`).
     *
     * @return Attribute<Collection<int, Tag>, never>
     */
    protected function labels(): Attribute
    {
        return Attribute::get(fn (): Collection => $this->tagsOf(EventTagType::Label));
    }

    /**
     * Whose label catalogue the event's labels live in: the global one, or the organizer's own
     * with `events.tags.scope = organizer`. Categories are global whatever this says.
     */
    public function tagScope(): ?Model
    {
        if (! EventsConfig::tagsScopedByOrganizer()) {
            return null;
        }

        $organizer = $this->organizer;

        return $organizer instanceof Model ? $organizer : null;
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

    /**
     * The poster bucket: one image (jpeg, png, webp or avif) within the size and dimension
     * limits, public by nature, with its queued webp variants (`events.poster.*`).
     */
    public function registerMediaBuckets(): void
    {
        $bucket = $this->addMediaBucket(EventsConfig::posterBucket())
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/avif'])
            ->maxFileSize(EventsConfig::posterMaxKilobytes() * 1024)
            ->minDimensions(EventsConfig::posterMinWidth(), EventsConfig::posterMinHeight())
            ->public();

        $disk = EventsConfig::posterDisk();

        if ($disk !== null) {
            $bucket->useDisk($disk);
        }

        $variants = EventsConfig::posterVariants();

        if ($variants !== []) {
            $bucket->registerVariants(static function (VariantRegistrar $registrar) use ($variants): void {
                foreach ($variants as $variant) {
                    $registrar->add($variant->name)
                        ->width($variant->width)
                        ->height($variant->height)
                        ->fit('cover')
                        ->format('webp')
                        ->queued();
                }
            });
        }
    }

    /**
     * The poster, or null.
     *
     * @return Attribute<Media|null, never>
     */
    protected function poster(): Attribute
    {
        return Attribute::get(fn (): ?Media => Posters::of($this));
    }

    /**
     * The poster's URL — a variant once generated, the original until then, and
     * `events.poster.fallback_url` (or null) without a poster. Never throws.
     */
    public function posterUrl(string $variant = ''): ?string
    {
        return Posters::url($this, $variant);
    }

    // Discovery scopes. The API filters are built on them, so PHP and HTTP filter identically.

    /**
     * What `$viewer` may see (null = a guest): the policy's `view` as a query. Managers see
     * their drafts and private events; a guest sees announced public and unlisted events.
     *
     * @param  Builder<static>  $query
     */
    public function scopeVisibleTo(Builder $query, ?Model $viewer): void
    {
        EventVisibility::scope($query, $viewer);
    }

    /**
     * Listed: public events (unlisted and private ones never appear in lists).
     *
     * @param  Builder<static>  $query
     */
    public function scopeListed(Builder $query): void
    {
        $query->where($query->qualifyColumn('visibility'), Visibility::Public->value);
    }

    /**
     * In any of the phases — mapped to the states of the configured lifecycle.
     *
     * @param  Builder<static>  $query
     */
    public function scopeInPhase(Builder $query, EventPhase ...$phases): void
    {
        $query->whereIn($query->qualifyColumn('status'), EventStates::valuesIn(...$phases));
    }

    /**
     * Announced: every phase but Draft.
     *
     * @param  Builder<static>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $this->scopeInPhase($query, EventPhase::Scheduled, EventPhase::Postponed, EventPhase::Rescheduled, EventPhase::Cancelled, EventPhase::Completed);
    }

    /**
     * On: Scheduled or Rescheduled.
     *
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $this->scopeInPhase($query, EventPhase::Scheduled, EventPhase::Rescheduled);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeAtVenue(Builder $query, Venue|int ...$venues): void
    {
        $query->whereIn($query->qualifyColumn('venue_id'), array_map(static fn (Venue|int $venue): int => $venue instanceof Venue ? $venue->getKey() : $venue, $venues));
    }

    /**
     * Online only.
     *
     * @param  Builder<static>  $query
     */
    public function scopeOnline(Builder $query): void
    {
        $this->scopeWithAttendanceMode($query, AttendanceMode::Online);
    }

    /**
     * Attended in person: at a venue, hybrid included.
     *
     * @param  Builder<static>  $query
     */
    public function scopeInPerson(Builder $query): void
    {
        $this->scopeWithAttendanceMode($query, AttendanceMode::Offline, AttendanceMode::Mixed);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeWithAttendanceMode(Builder $query, AttendanceMode ...$modes): void
    {
        $query->whereIn($query->qualifyColumn('attendance_mode'), array_map(static fn (AttendanceMode $mode): string => $mode->value, $modes));
    }

    /**
     * Organized by `$organizer` (co-hosted events excluded).
     *
     * @param  Builder<static>  $query
     */
    public function scopeOrganizedBy(Builder $query, Model $organizer): void
    {
        $query->where($query->qualifyColumn('organizer_type'), $organizer->getMorphClass())
            ->where($query->qualifyColumn('organizer_id'), $organizer->getKey());
    }

    /**
     * The events `$user` manages (as the organizer's or a co-host's approved user, or an admin) —
     * the SQL form of the policy, drafts included.
     *
     * @param  Builder<static>  $query
     */
    public function scopeManagedBy(Builder $query, Model $user): void
    {
        EventManagers::scope($query, $user);
    }

    /**
     * Events `$attendee` is going to.
     *
     * @param  Builder<static>  $query
     */
    public function scopeAttendedBy(Builder $query, Model $attendee): void
    {
        $this->constrainToRsvpOf($query, $attendee, RsvpStatus::Going);
    }

    /**
     * Events `$attendee` is interested in.
     *
     * @param  Builder<static>  $query
     */
    public function scopeInterestedBy(Builder $query, Model $attendee): void
    {
        $this->constrainToRsvpOf($query, $attendee, RsvpStatus::Interested);
    }

    /**
     * Free to attend: no ticket type with a price.
     *
     * @param  Builder<static>  $query
     */
    public function scopeFree(Builder $query): void
    {
        $query->whereDoesntHave('tiers', static fn (Builder $tiers): Builder => $tiers->whereNotNull('price'));
    }

    /**
     * Seats left under the event's capacity (or no capacity).
     *
     * @param  Builder<static>  $query
     */
    public function scopeWithSeatsAvailable(Builder $query): void
    {
        $query->where(static fn (Builder $seats): Builder => $seats
            ->whereNull($seats->qualifyColumn('capacity'))
            ->orWhereColumn($seats->qualifyColumn('seats_taken'), '<', $seats->qualifyColumn('capacity')));
    }

    /**
     * Title or description contains the term, in any locale, case-insensitively — LIKE wildcards
     * escaped, driver-correct. A blank term adds no constraint.
     *
     * @param  Builder<static>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        if (trim($term) === '') {
            return;
        }

        Translations::search($query, new TranslationSearch(['title', 'description'], trim($term)));
    }

    /**
     * Most people going first, then the soonest.
     *
     * @param  Builder<static>  $query
     */
    public function scopePopular(Builder $query): void
    {
        $query->orderByDesc($query->qualifyColumn('going_count'))->orderBy($query->qualifyColumn('starts_at'));
    }

    /**
     * @param  Builder<static>  $query
     */
    private function constrainToRsvpOf(Builder $query, Model $attendee, RsvpStatus $status): void
    {
        $query->whereHas('rsvps', static fn (Builder $rsvps): Builder => $rsvps
            ->where('attendee_type', $attendee->getMorphClass())
            ->where('attendee_id', $attendee->getKey())
            ->where('status', $status->value));
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

    // Category & labels ------------------------------------------------------------------------

    /**
     * In any of these categories (slugs, names, keys or tags) — their sub-categories included
     * unless `descendants: false`.
     *
     * @param  Builder<static>  $query
     */
    public function scopeInCategory(Builder $query, mixed $categories, bool $descendants = true): void
    {
        $query->withAnyTags($categories, EventTagType::Category, $descendants);
    }

    /**
     * Carrying any of these labels — or every one with `all: true`.
     *
     * @param  Builder<static>  $query
     */
    public function scopeLabelled(Builder $query, mixed $labels, bool $all = false): void
    {
        $all
            ? $query->withAllTags($labels, EventTagType::Label)
            : $query->withAnyTags($labels, EventTagType::Label);
    }

    /**
     * Without a category.
     *
     * @param  Builder<static>  $query
     */
    public function scopeUncategorized(Builder $query): void
    {
        $query->withoutTags([], EventTagType::Category);
    }

    // Near me ----------------------------------------------------------------------------------

    /**
     * At a venue within `$km` of the point (a bounding box on the venue's indexed coordinates,
     * then the exact great-circle distance). Online-only events never match. `$km` must be in
     * (0, `events.geo.max_radius_km`].
     *
     * @param  Builder<static>  $query
     */
    public function scopeNear(Builder $query, Coordinates $point, ?float $km = null): void
    {
        $km ??= EventsConfig::defaultRadiusKm();

        $query->whereHas('venue', static function (Builder $venues) use ($point, $km): void {
            $venues->near($point, $km);
        });
    }

    /**
     * Adds `distance_km` (float, null for an event without a venue) to the selected columns,
     * keeping the existing select (`events.*` when there is none).
     *
     * @param  Builder<static>  $query
     */
    public function scopeWithDistance(Builder $query, Coordinates $point): void
    {
        if ($query->getQuery()->columns === null) {
            $query->select($query->qualifyColumn('*'));
        }

        $query->selectSub($this->distanceSubquery($query, $point), 'distance_km');
    }

    /**
     * Nearest first (or `desc`), events without a venue last in either direction.
     *
     * @param  Builder<static>  $query
     */
    public function scopeOrderByDistance(Builder $query, Coordinates $point, string $direction = 'asc'): void
    {
        $direction = strtolower($direction);

        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw InvalidEventException::invalidSortDirection($direction);
        }

        $distance = $this->distanceSubquery($query, $point);
        $sql = '('.$distance->toSql().')';

        // orderByRaw() by hand: NULLS LAST portably (MySQL has no NULLS LAST), bindings in the
        // order bucket so a paginator's count query drops them with the sort.
        $base = $query->getQuery();
        $base->orders[] = ['type' => 'Raw', 'sql' => "CASE WHEN {$sql} IS NULL THEN 1 ELSE 0 END, {$sql} {$direction}"];
        $base->addBinding([...$distance->getBindings(), ...$distance->getBindings()], 'order');
    }

    /**
     * `(select <distance> from venues where venues.id = events.venue_id)` — trashed venues
     * included, like the `venue` relation.
     *
     * @param  Builder<static>  $query
     */
    private function distanceSubquery(Builder $query, Coordinates $point): QueryBuilder
    {
        $venue = EventModels::venue();
        $table = (new $venue)->getTable();
        $alias = 'ev_distance_venue';
        $distance = DistanceExpression::for($this->getConnection(), $alias.'.latitude', $alias.'.longitude', $point);

        return $this->getConnection()->table($table.' as '.$alias)
            ->select(new RawExpression($distance->sql))
            ->addBinding($distance->bindings, 'select')
            ->whereColumn($alias.'.id', $query->qualifyColumn('venue_id'))
            ->limit(1);
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
