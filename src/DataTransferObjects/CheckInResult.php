<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\DataTransferObjects;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Enums\CheckInMethod;
use RoundlyConsulting\Events\Models\Rsvp;

/**
 * The outcome of a check-in. `firstCheckIn` is false for a code already used (the door app shows
 * "already in since 20:04"); `guests` is how many of the party's guests are checked in.
 */
final readonly class CheckInResult
{
    public function __construct(
        public Rsvp $rsvp,
        public Model $attendee,
        public bool $firstCheckIn,
        public int $guests,
        public CarbonImmutable $checkedInAt,
        public CheckInMethod $method,
    ) {}
}
