<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Tests\Support;

use RuntimeException;

/**
 * A minimal RFC 5545 reader for the tests: checks the physical layout (CRLF, ≤ 75 octets per
 * line), unfolds, and returns every component's properties with TEXT values unescaped.
 */
final class IcsParser
{
    /**
     * @return array{calendar: array<string, list<string>>, events: list<array<string, list<string>>>}
     */
    public static function parse(string $ics): array
    {
        if (! str_ends_with($ics, "\r\n") || preg_match("/(?<!\r)\n|\r(?!\n)/", $ics) === 1) {
            throw new RuntimeException('Lines must end with CRLF only.');
        }

        foreach (explode("\r\n", rtrim($ics, "\r\n")) as $physical) {
            if (strlen($physical) > 75) {
                throw new RuntimeException('A line is longer than 75 octets: '.$physical);
            }

            if (! mb_check_encoding($physical, 'UTF-8')) {
                throw new RuntimeException('A fold split a UTF-8 character.');
            }
        }

        $lines = explode("\r\n", rtrim(str_replace("\r\n ", '', $ics), "\r\n"));
        $calendar = [];
        $events = [];
        $current = null;
        $stack = [];

        foreach ($lines as $line) {
            [$name, $value] = self::split($line);

            if ($name === 'BEGIN') {
                $stack[] = $value;
                $current = $value === 'VEVENT' ? [] : $current;

                continue;
            }

            if ($name === 'END') {
                if (array_pop($stack) !== $value) {
                    throw new RuntimeException('Unbalanced END:'.$value);
                }

                if ($value === 'VEVENT') {
                    $events[] = $current ?? [];
                    $current = null;
                }

                continue;
            }

            if ($current !== null) {
                $current[$name][] = $value;
            } else {
                $calendar[$name][] = $value;
            }
        }

        if ($stack !== []) {
            throw new RuntimeException('Unclosed component.');
        }

        return ['calendar' => $calendar, 'events' => $events];
    }

    /**
     * Unescape a TEXT value (§3.3.11).
     */
    public static function text(string $value): string
    {
        return (string) preg_replace_callback('/\\\\([\\\;,nN])/', static fn (array $match): string => strtolower($match[1]) === 'n' ? "\n" : $match[1], $value);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function split(string $line): array
    {
        // The name (with parameters) ends at the first colon outside a quoted parameter value.
        $inQuotes = false;

        for ($i = 0, $length = strlen($line); $i < $length; $i++) {
            if ($line[$i] === '"') {
                $inQuotes = ! $inQuotes;
            } elseif ($line[$i] === ':' && ! $inQuotes) {
                return [substr($line, 0, $i), substr($line, $i + 1)];
            }
        }

        throw new RuntimeException('A content line has no value: '.$line);
    }
}
