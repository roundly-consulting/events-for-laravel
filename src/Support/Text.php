<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

/**
 * Control characters never reach the database: PostgreSQL refuses a NUL inside json(b) (a raw
 * 500), and nothing a person types needs the others. Names and titles are one line; descriptions
 * keep their line breaks and tabs.
 *
 * @internal
 */
final class Text
{
    /**
     * @param  array<string, string>|string  $value
     * @return array<string, string>|string
     */
    public static function line(array|string $value): array|string
    {
        return is_string($value) ? self::clean($value, '/[\x00-\x1F\x7F]+/u') : array_map(static fn (string $line): string => self::clean($line, '/[\x00-\x1F\x7F]+/u'), $value);
    }

    /**
     * @param  array<string, string>|string  $value
     * @return array<string, string>|string
     */
    public static function block(array|string $value): array|string
    {
        return is_string($value) ? self::clean($value, '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u') : array_map(static fn (string $line): string => self::clean($line, '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u'), $value);
    }

    private static function clean(string $value, string $pattern): string
    {
        return trim((string) preg_replace($pattern, $pattern === '/[\x00-\x1F\x7F]+/u' ? ' ' : '', $value));
    }
}
