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
use RoundlyConsulting\Events\Database\Factories\RsvpFactory;
use RoundlyConsulting\Events\Enums\CheckInMethod;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventsConfig;
use RoundlyConsulting\Money\Casts\AsMoney;
use RoundlyConsulting\Money\Money;

/**
 * One attendee's answer to one event: going, held (seats reserved while a payment is pending),
 * waitlisted, interested, not going or expired (a hold that lapsed). A party is `1 + guests`
 * seats. A going RSVP carries a check-in code (encrypted; `checkInCode()` / `checkInQr()`).
 *
 * Not soft-deleted (one row per attendee and event): `not_going` is its "deleted".
 * Swappable through `events.models.rsvp`.
 *
 * @property int $id
 * @property int|string $event_id
 * @property string $attendee_type
 * @property int|string $attendee_id
 * @property int|null $tier_id
 * @property RsvpStatus $status
 * @property int $guests
 * @property string|null $note
 * @property CarbonImmutable|null $waitlisted_at
 * @property CarbonImmutable|null $held_until
 * @property string|null $payment_reference
 * @property string|null $payment_url
 * @property Money|null $amount
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $responded_at
 * @property string|null $check_in_code
 * @property string|null $check_in_code_hash
 * @property CarbonImmutable|null $checked_in_at
 * @property int|null $checked_in_guests
 * @property CheckInMethod|null $check_in_method
 * @property string|null $checked_in_by_type
 * @property int|string|null $checked_in_by_id
 * @property string|null $added_by_type
 * @property int|string|null $added_by_id
 * @property string|null $removed_by_type
 * @property int|string|null $removed_by_id
 * @property array<string, mixed>|null $meta
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Event $event
 * @property-read Model $attendee
 * @property-read AdmissionTier|null $tier
 */
class Rsvp extends Model
{
    /** @use HasFactory<RsvpFactory> */
    use HasFactory;

    use StoresUtcTimestamps;

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = [
        'attendee_type', 'checked_in_by_type', 'added_by_type', 'removed_by_type',
        'check_in_code', 'check_in_code_hash', 'payment_url', 'meta',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'guests' => 0,
    ];

    public function getTable(): string
    {
        return EventsConfig::table('rsvps');
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
    public function attendee(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<AdmissionTier, $this>
     */
    public function tier(): BelongsTo
    {
        return $this->belongsTo(EventModels::tier(), 'tier_id')->withTrashed();
    }

    public function isGoing(): bool
    {
        return $this->status === RsvpStatus::Going;
    }

    public function isHeld(): bool
    {
        return $this->status === RsvpStatus::Held;
    }

    public function isWaitlisted(): bool
    {
        return $this->status === RsvpStatus::Waitlisted;
    }

    public function isInterested(): bool
    {
        return $this->status === RsvpStatus::Interested;
    }

    public function isCheckedIn(): bool
    {
        return $this->checked_in_at !== null;
    }

    /**
     * The seats the RSVP's party takes: the attendee and their guests.
     */
    public function partySize(): int
    {
        return 1 + $this->guests;
    }

    /**
     * The 1-based place on the waitlist, or null when not waitlisted.
     */
    public function waitlistPosition(): ?int
    {
        if (! $this->isWaitlisted() || $this->waitlisted_at === null) {
            return null;
        }

        $ahead = EventModels::rsvp()::query()
            ->where('event_id', $this->event_id)
            ->where('status', RsvpStatus::Waitlisted->value)
            ->where(fn ($query) => $query
                ->where('waitlisted_at', '<', $this->getRawOriginal('waitlisted_at') ?? $this->getAttributes()['waitlisted_at'])
                ->orWhere(fn ($same) => $same
                    ->where('waitlisted_at', $this->getRawOriginal('waitlisted_at') ?? $this->getAttributes()['waitlisted_at'])
                    ->where('id', '<', $this->getKey())))
            ->count();

        return $ahead + 1;
    }

    /**
     * The check-in code as text (decrypted), or null when the RSVP is not going.
     */
    public function checkInCode(): ?string
    {
        return $this->check_in_code;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RsvpStatus::class,
            'guests' => 'integer',
            'waitlisted_at' => UtcDateTime::class,
            'held_until' => UtcDateTime::class,
            'amount' => AsMoney::class,
            'confirmed_at' => UtcDateTime::class,
            'responded_at' => UtcDateTime::class,
            'check_in_code' => 'encrypted',
            'checked_in_at' => UtcDateTime::class,
            'checked_in_guests' => 'integer',
            'check_in_method' => CheckInMethod::class,
            'created_at' => UtcDateTime::class,
            'updated_at' => UtcDateTime::class,
            'meta' => 'array',
        ];
    }

    protected static function newFactory(): RsvpFactory
    {
        return RsvpFactory::new();
    }
}
