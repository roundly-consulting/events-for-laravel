<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Models\AdmissionTier;

/**
 * A ticket type was deleted.
 */
final readonly class TierDeleted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public AdmissionTier $tier,
        public ?Model $actor,
    ) {}
}
