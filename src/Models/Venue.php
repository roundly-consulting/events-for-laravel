<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Events\Casts\UtcDateTime;
use RoundlyConsulting\Events\Concerns\StoresUtcTimestamps;
use RoundlyConsulting\Events\Database\Factories\VenueFactory;
use RoundlyConsulting\Events\Exceptions\InvalidVenueException;
use RoundlyConsulting\Events\Support\DistanceExpression;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventsConfig;
use RoundlyConsulting\Events\Support\VenueSlugs;
use RoundlyConsulting\Geolocation\Concerns\HasLocation;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;
use RoundlyConsulting\PackageToolkit\Support\RawExpression;
use RoundlyConsulting\Sluggable\Concerns\HasSlug;
use RoundlyConsulting\Sluggable\Contracts\Sluggable;
use RoundlyConsulting\Sluggable\Definitions\SlugOptions;
use RoundlyConsulting\Translatable\Concerns\HasTranslations;
use RoundlyConsulting\Translatable\Contracts\Translatable;

/**
 * A reusable place: a translatable name, one address, optional coordinates (read through
 * geolocation's `CoordinatesCast` as `coordinates`) and an IANA timezone that decides how every
 * wall-clock time of its events is read. Optionally owned by an organizer (a private venue list).
 *
 * Swappable through `events.models.venue`.
 *
 * @property int $id
 * @property string|null $name
 * @property string $slug
 * @property string|null $owner_type
 * @property int|string|null $owner_id
 * @property string|null $street
 * @property string|null $city
 * @property string|null $region
 * @property string|null $postal_code
 * @property string|null $country_code
 * @property string|null $formatted_address
 * @property float|string|null $latitude
 * @property float|string|null $longitude
 * @property Coordinates|null $coordinates
 * @property string $timezone
 * @property int|null $capacity
 * @property CarbonImmutable|null $geocoded_at
 * @property array<string, mixed>|null $meta
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Model|null $owner
 */
class Venue extends Model implements Sluggable, Translatable
{
    /** @use HasFactory<VenueFactory> */
    use HasFactory;

    use HasLocation;
    use HasSlug;
    use HasTranslations;
    use SoftDeletes;
    use StoresUtcTimestamps;

    protected $guarded = [];

    /** @var list<string> */
    public array $translatable = ['name'];

    /** @var list<string> */
    protected $hidden = ['owner_type', 'meta'];

    public function getTable(): string
    {
        return EventsConfig::table('venues');
    }

    public function slugOptions(): SlugOptions
    {
        return SlugOptions::make(VenueSlugs::definition());
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(EventModels::event(), 'venue_id');
    }

    /**
     * The address on one line: the stored `formatted_address`, else its parts joined.
     */
    public function formattedAddress(): string
    {
        if (filled($this->formatted_address)) {
            return (string) $this->formatted_address;
        }

        $locality = trim(implode(' ', array_filter([$this->postal_code, $this->city], filled(...))));

        return implode(', ', array_filter([$this->street, $locality, $this->region, $this->country_code], filled(...)));
    }

    /**
     * Venues owned by `$owner` (an organizer's private list).
     *
     * @param  Builder<static>  $query
     */
    public function scopeOwnedBy(Builder $query, Model $owner): void
    {
        $query->where('owner_type', $owner->getMorphClass())->where('owner_id', $owner->getKey());
    }

    /**
     * Within `$km` of a point: geolocation's bounding box pre-filters on the coordinates index,
     * then the exact great-circle distance decides. Venues without coordinates never match.
     *
     * @param  Builder<static>  $query
     */
    public function scopeNear(Builder $query, Coordinates $point, float $km): void
    {
        $max = EventsConfig::maxRadiusKm();

        if ($km <= 0 || $km > $max) {
            throw InvalidVenueException::invalidRadius((string) $max);
        }

        $distance = DistanceExpression::for($this->getConnection(), $query->qualifyColumn('latitude'), $query->qualifyColumn('longitude'), $point);

        $this->scopeWithinRadius($query, $point, $km);
        $query->whereRaw(new RawExpression($distance->sql.' <= ?'), [...$distance->bindings, $km]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'geocoded_at' => UtcDateTime::class,
            'created_at' => UtcDateTime::class,
            'updated_at' => UtcDateTime::class,
            'deleted_at' => UtcDateTime::class,
            'meta' => 'array',
        ];
    }

    protected static function newFactory(): VenueFactory
    {
        return VenueFactory::new();
    }
}
