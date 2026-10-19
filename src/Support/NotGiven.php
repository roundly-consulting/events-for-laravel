<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

/**
 * "Leave this field alone" in an update DTO, so `null` can still mean "clear it".
 */
enum NotGiven
{
    case Value;
}
