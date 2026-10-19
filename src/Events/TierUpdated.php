<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Models\AdmissionTier;

/**
 * A ticket type changed. `changed` lists the attributes that did.
 */
final readonly class TierUpdated implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<string>  $changed
     */
    public function __construct(
        public AdmissionTier $tier,
        public array $changed,
        public ?Model $actor,
    ) {}
}
