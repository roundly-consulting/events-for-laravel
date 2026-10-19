<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use Illuminate\Support\Facades\Lang;

/**
 * Every user-facing line the package writes comes from `events::messages`, so an exception or
 * a refusal reads in the host's locale (en and sk ship).
 *
 * @internal
 */
final class Messages
{
    /**
     * @param  array<string, string|int|float>  $replace
     */
    public static function get(string $key, array $replace = []): string
    {
        $line = Lang::get('events::messages.'.$key, $replace);

        return is_string($line) ? $line : $key;
    }
}
