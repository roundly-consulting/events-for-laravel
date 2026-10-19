<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RoundlyConsulting\Events\Casts\UtcDateTime;
use RoundlyConsulting\Events\Concerns\StoresUtcTimestamps;
use RoundlyConsulting\Events\Database\Factories\EventHostFactory;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventsConfig;

/**
 * A co-host of one event: an extra organizer (a user, a team) with management rights on it.
 * Not soft-deleted (a unique row per event and host): removing a co-host deletes the row.
 *
 * @property int $id
 * @property int|string $event_id
 * @property string $host_type
 * @property int|string $host_id
 * @property string|null $added_by_type
 * @property int|string|null $added_by_id
 * @property CarbonImmutable|null $created_at
 * @property-read Event $event
 * @property-read Model $host
 * @property-read Model|null $addedBy
 */
final class EventHost extends Model
{
    /** @use HasFactory<EventHostFactory> */
    use HasFactory;

    use StoresUtcTimestamps;

    public const null UPDATED_AT = null;

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['host_type', 'added_by_type'];

    public function getTable(): string
    {
        return EventsConfig::table('hosts');
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(EventModels::event(), 'event_id')->withTrashed();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function host(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function addedBy(): MorphTo
    {
        return $this->morphTo('added_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => UtcDateTime::class,
        ];
    }

    protected static function newFactory(): EventHostFactory
    {
        return EventHostFactory::new();
    }
}
