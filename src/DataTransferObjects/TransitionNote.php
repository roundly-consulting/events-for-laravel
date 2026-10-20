<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\DataTransferObjects;

use Carbon\CarbonImmutable;

/**
 * What an events action adds to a status change: whether it was silent (no notices), the start a
 * reschedule moved away from, and the RSVPs whose holds a cancellation released (they are told).
 *
 * @internal
 */
final readonly class TransitionNote
{
    /**
     * @param  list<int|string>  $releasedHolds
     */
    public function __construct(
        public bool $silent = false,
        public ?CarbonImmutable $previousStartsAt = null,
        public bool $reschedule = false,
        public array $releasedHolds = [],
    ) {}
}
