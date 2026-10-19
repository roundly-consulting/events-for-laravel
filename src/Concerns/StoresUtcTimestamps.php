<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

/**
 * Every package model's "now" is UTC: Eloquent's timestamps and soft deletes, and lifecycle's
 * `stamps()` — which lifecycle writes with the model's own `freshTimestampString()`, in the
 * model's timezone. Read back through the UTC cast, a host on `America/New_York` stores and
 * reads the same instants as one on UTC.
 */
trait StoresUtcTimestamps
{
    public function freshTimestamp(): Carbon
    {
        return Date::now('UTC');
    }
}
