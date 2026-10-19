<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Enums\RsvpResponse;
use RoundlyConsulting\Events\Models\AdmissionTier;

/**
 * An attendee's answer: going (with a ticket type — a model, id or key — and guests), interested
 * or not going. `going` yields going, held (a paid ticket type) or waitlisted.
 */
final readonly class RsvpRequest
{
    public function __construct(
        public Model $attendee,
        public RsvpResponse $response,
        public AdmissionTier|int|string|null $tier = null,
        public int $guests = 0,
        public ?string $note = null,
        public ?Model $actor = null,
    ) {}
}
