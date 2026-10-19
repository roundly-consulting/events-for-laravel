<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Exceptions;

use RoundlyConsulting\PackageToolkit\Exceptions\PackageToolkitException;

/**
 * `events.lifecycle` names something the package cannot run on: not a lifecycle definition, one
 * that does not implement `Contracts\EventLifecycle`, or one whose states do not implement
 * `Contracts\EventState`. Operator-facing (English), naming the config key.
 */
final class InvalidEventLifecycleException extends PackageToolkitException
{
    public static function notADefinition(mixed $value): self
    {
        return new self(sprintf(
            'Configuration value [events.lifecycle] must name a lifecycle definition implementing the EventLifecycle contract, [%s] given.',
            is_string($value) ? $value : get_debug_type($value),
        ));
    }

    public static function broken(string $definition, string $reason): self
    {
        return new self(sprintf('The lifecycle configured in [events.lifecycle] (%s) cannot run events: %s', class_basename($definition), $reason));
    }
}
