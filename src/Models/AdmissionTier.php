<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Events\Casts\UtcDateTime;
use RoundlyConsulting\Events\Concerns\StoresUtcTimestamps;
use RoundlyConsulting\Events\Database\Factories\AdmissionTierFactory;
use RoundlyConsulting\Events\Support\Clock;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventsConfig;
use RoundlyConsulting\Money\Casts\AsMoney;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Translatable\Concerns\HasTranslations;
use RoundlyConsulting\Translatable\Contracts\Translatable;

/**
 * A ticket type of one event: a translatable name, a price in Money (null = free), its own
 * capacity on top of the event's, a sale window and party-size limits. Its `key` is how code
 * names it (`going(tier: 'vip')`): unique per event, trashed tiers included, never suffixed.
 *
 * Swappable through `events.models.tier`.
 *
 * @property int $id
 * @property int|string $event_id
 * @property string $key
 * @property string|null $name
 * @property string|null $description
 * @property Money|null $price
 * @property int|null $capacity
 * @property int $seats_taken
 * @property CarbonImmutable|null $sale_starts_at
 * @property CarbonImmutable|null $sale_ends_at
 * @property int $min_party
 * @property int|null $max_party
 * @property bool $hidden
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Event $event
 */
class AdmissionTier extends Model implements Translatable
{
    /** @use HasFactory<AdmissionTierFactory> */
    use HasFactory;

    use HasTranslations;
    use SoftDeletes;
    use StoresUtcTimestamps;

    protected $guarded = [];

    /** @var list<string> */
    public array $translatable = ['name', 'description'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'seats_taken' => 0,
        'min_party' => 1,
        'hidden' => false,
        'position' => 0,
    ];

    public function getTable(): string
    {
        return EventsConfig::table('tiers');
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(EventModels::event(), 'event_id')->withTrashed();
    }

    public function isFree(): bool
    {
        return $this->price === null || $this->price->isZero();
    }

    /**
     * Inside the sale window right now.
     */
    public function isOnSale(): bool
    {
        $now = Clock::now();

        return ($this->sale_starts_at === null || $this->sale_starts_at->lessThanOrEqualTo($now))
            && ($this->sale_ends_at === null || $this->sale_ends_at->greaterThan($now));
    }

    /**
     * Seats left under the ticket type's own capacity, or null when it has none.
     */
    public function seatsLeft(): ?int
    {
        return $this->capacity === null ? null : max(0, $this->capacity - $this->seats_taken);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => AsMoney::class,
            'capacity' => 'integer',
            'seats_taken' => 'integer',
            'sale_starts_at' => UtcDateTime::class,
            'sale_ends_at' => UtcDateTime::class,
            'min_party' => 'integer',
            'max_party' => 'integer',
            'hidden' => 'boolean',
            'position' => 'integer',
            'created_at' => UtcDateTime::class,
            'updated_at' => UtcDateTime::class,
            'deleted_at' => UtcDateTime::class,
        ];
    }

    protected static function newFactory(): AdmissionTierFactory
    {
        return AdmissionTierFactory::new();
    }
}
