<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Tests\Fixtures\Lifecycles;

/**
 * States that do not implement EventState — the package cannot read their phase.
 */
enum PlainStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
