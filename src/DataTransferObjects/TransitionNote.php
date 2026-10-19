<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\DataTransferObjects;

use Carbon\CarbonImmutable;

/**
 * What an events action adds to a status change: whether it was silent (no notices), and the
 * start a reschedule moved away from.
 *
 * @internal
 */
final readonly class TransitionNote
{
    public function __construct(
        public bool $silent = false,
        public ?CarbonImmutable $previousStartsAt = null,
    ) {}
}
