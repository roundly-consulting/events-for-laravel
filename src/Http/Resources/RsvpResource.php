<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;
use RoundlyConsulting\Events\Models\Rsvp;

/**
 * An RSVP in one of two views. The owner's own view carries the check-in code and the QR URL;
 * the managers' view carries the attendee (a reference resource) and the check-in record, and
 * **never** the code.
 *
 * @property Rsvp $resource
 */
final class RsvpResource extends JsonResource
{
    private bool $owner = false;

    public function asOwner(): self
    {
        $this->owner = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $rsvp = $this->resource;
        $event = $rsvp->event;
        $timezone = $event->timezone;
        $tier = $rsvp->tier;

        $base = [
            'id' => $rsvp->getKey(),
            'status' => $rsvp->status->value,
            'guests' => $rsvp->guests,
            'party_size' => $rsvp->partySize(),
            'tier' => $tier === null ? null : ['id' => $tier->getKey(), 'key' => $tier->key, 'name' => $tier->name],
            'note' => $rsvp->note,
            'waitlist_position' => $rsvp->isWaitlisted() ? $rsvp->waitlistPosition() : null,
            'held_until' => $rsvp->held_until?->setTimezone($timezone)->toIso8601String(),
            'amount' => $rsvp->amount === null ? null : (new MoneyResource($rsvp->amount))->resolve($request),
            'checked_in_at' => $rsvp->checked_in_at?->setTimezone($timezone)->toIso8601String(),
            'checked_in_guests' => $rsvp->checked_in_guests,
            'responded_at' => $rsvp->responded_at?->setTimezone($timezone)->toIso8601String(),
        ];

        if ($this->owner) {
            return [
                ...$base,
                'payment_url' => $rsvp->isHeld() ? $rsvp->payment_url : null,
                'check_in_code' => $rsvp->checkInCode(),
                'qr_url' => $rsvp->checkInCode() !== null && Route::has('events.rsvp.qr') ? route('events.rsvp.qr', ['event' => $event->uuid]) : null,
            ];
        }

        return [
            ...$base,
            'attendee' => References::attendee($rsvp->attendee, $request),
            'check_in_method' => $rsvp->check_in_method?->value,
            'checked_in_by' => References::actor($rsvp->checkedInBy, $request),
        ];
    }
}
