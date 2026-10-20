<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Events\Models\EventInvitation;

/**
 * An invitation, for the event's managers. The token is never rendered: it lives in the
 * invitation mail only.
 *
 * @property EventInvitation $resource
 */
final class InvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $invitation = $this->resource;
        $timezone = $invitation->event->timezone;

        return [
            'id' => $invitation->getKey(),
            'status' => $invitation->status->value,
            'invitee' => References::invitee($invitation->invitee, $request),
            'invited_by' => References::actor($invitation->invitedBy, $request),
            'message' => $invitation->message,
            'expires_at' => $invitation->expiresAt()?->setTimezone($timezone)->toIso8601String(),
            'responded_at' => $invitation->responded_at?->setTimezone($timezone)->toIso8601String(),
            'sent_at' => $invitation->created_at?->setTimezone($timezone)->toIso8601String(),
        ];
    }
}
