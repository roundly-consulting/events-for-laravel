<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RoundlyConsulting\Events\Casts\UtcDateTime;
use RoundlyConsulting\Events\Database\Factories\ReminderDeliveryFactory;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventsConfig;

/**
 * The claim that one reminder offset of one event, for one start time, went out — exactly once,
 * whatever the number of concurrent sweeps (the insert is the claim). A reschedule changes the
 * start time, so its reminders re-arm.
 *
 * @property int $id
 * @property int|string $event_id
 * @property string $offset
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable|null $dispatched_at
 * @property int $recipients
 * @property-read Event $event
 */
final class ReminderDelivery extends Model
{
    /** @use HasFactory<ReminderDeliveryFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    public function getTable(): string
    {
        return EventsConfig::table('reminder_deliveries');
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(EventModels::event(), 'event_id')->withTrashed();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => UtcDateTime::class,
            'dispatched_at' => UtcDateTime::class,
            'recipients' => 'integer',
        ];
    }

    protected static function newFactory(): ReminderDeliveryFactory
    {
        return ReminderDeliveryFactory::new();
    }
}
