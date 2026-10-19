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
use RoundlyConsulting\Events\Database\Factories\EventInvitationFactory;
use RoundlyConsulting\Events\Enums\InvitationStatus;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventsConfig;

/**
 * An invitation to one event for one invitee. On a private event it is the admission: only
 * pending or accepted invitees may RSVP. The token is a bearer secret stored as a hash only;
 * re-sending rotates it.
 *
 * Not soft-deleted (a unique row per event and invitee): `revoked` is its "deleted".
 *
 * @property int $id
 * @property int|string $event_id
 * @property string $invitee_type
 * @property int|string $invitee_id
 * @property string|null $invited_by_type
 * @property int|string|null $invited_by_id
 * @property InvitationStatus $status
 * @property string|null $message
 * @property string|null $token_hash
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $responded_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Event $event
 * @property-read Model $invitee
 * @property-read Model|null $invitedBy
 */
final class EventInvitation extends Model
{
    /** @use HasFactory<EventInvitationFactory> */
    use HasFactory;

    use StoresUtcTimestamps;

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /**
     * The plain token, set only on the instance `send()` returns — never stored, never loaded.
     */
    public ?string $plainToken = null;

    public function getTable(): string
    {
        return EventsConfig::table('invitations');
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
    public function invitee(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function invitedBy(): MorphTo
    {
        return $this->morphTo('invited_by');
    }

    public function isPending(): bool
    {
        return $this->status === InvitationStatus::Pending;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvitationStatus::class,
            'expires_at' => UtcDateTime::class,
            'responded_at' => UtcDateTime::class,
            'created_at' => UtcDateTime::class,
            'updated_at' => UtcDateTime::class,
        ];
    }

    protected static function newFactory(): EventInvitationFactory
    {
        return EventInvitationFactory::new();
    }
}
