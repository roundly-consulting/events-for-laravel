<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Events\DataTransferObjects\CheckInResult;

/**
 * A door scan's answer: first scan or a repeat, the guests in, and who it is.
 *
 * @property CheckInResult $resource
 */
final class CheckInResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $result = $this->resource;

        return [
            'first_check_in' => $result->firstCheckIn,
            'guests' => $result->guests,
            'checked_in_at' => $result->checkedInAt->setTimezone($result->rsvp->event->timezone)->toIso8601String(),
            'method' => $result->method->value,
            'attendee' => References::attendee($result->attendee, $request),
            'rsvp' => (new RsvpResource($result->rsvp))->resolve($request),
        ];
    }
}
