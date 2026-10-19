<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Exceptions;

use RoundlyConsulting\PackageToolkit\Exceptions\PackageToolkitException;

/**
 * A configuration value the package cannot use: a malformed duration or timezone, a table name,
 * or a model whose key does not match the configured key type. Operator-facing, so English, and
 * always naming the config key to fix.
 */
final class InvalidEventsConfigurationException extends PackageToolkitException
{
    public static function invalidDuration(string $key, mixed $value): self
    {
        return new self(sprintf('Configuration value [%s] must be an ISO-8601 duration such as PT2H, [%s] given.', $key, self::describe($value)));
    }

    public static function invalidTimezone(string $key, mixed $value): self
    {
        return new self(sprintf('Configuration value [%s] must be an IANA timezone such as Europe/Bratislava, [%s] given.', $key, self::describe($value)));
    }

    public static function invalidTable(string $key, mixed $value): self
    {
        return new self(sprintf('Configuration value [%s] must be a table name (lowercase letters, digits and underscores, at most 64 characters), [%s] given.', $key, self::describe($value)));
    }

    public static function invalidTime(string $key, mixed $value): self
    {
        return new self(sprintf('Configuration value [%s] must be a time of day such as 09:00, [%s] given.', $key, self::describe($value)));
    }

    public static function invalidUrlTemplate(string $key): self
    {
        return new self(sprintf('Configuration value [%s] must be an http(s) URL template.', $key));
    }

    public static function invalidChoice(string $key, mixed $value, string $choices): self
    {
        return new self(sprintf('Configuration value [%s] must be one of [%s], [%s] given.', $key, $choices, self::describe($value)));
    }

    /**
     * A model whose key cannot be stored in the configured morph columns: caught before SQL, so
     * PostgreSQL never answers with a raw "invalid input syntax for type bigint".
     */
    public static function keyTypeMismatch(string $key, string $model, string $type): self
    {
        return new self(sprintf(
            'The key of the %s model does not match the configured key type [%s = %s]. Set %s to the key type of your %s model before migrating.',
            $model,
            $key,
            $type,
            $key,
            $model,
        ));
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            default => get_debug_type($value),
        };
    }
}
