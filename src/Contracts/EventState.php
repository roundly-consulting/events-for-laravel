<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Contracts;

use RoundlyConsulting\Events\Enums\EventPhase;

/**
 * Implemented by the backed enum of an event lifecycle's states. Every state maps to one fixed
 * phase, which is all the package logic ever reads, so a host lifecycle with its own states
 * (an approval step, say) runs everything else unchanged.
 */
interface EventState
{
    public function phase(): EventPhase;
}
