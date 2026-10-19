<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Enums;

/**
 * How bad an `events:doctor` finding is: an error breaks the package (non-zero exit), a warning
 * is worth a look.
 */
enum FindingSeverity: string
{
    case Error = 'error';
    case Warning = 'warning';
}
