<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Exceptions\InvalidEventException;
use RoundlyConsulting\Events\Support\Clock;
use Throwable;

/**
 * A datetime column stored as a UTC `Y-m-d H:i:s` string and read back as a UTC
 * `CarbonImmutable`, whatever `app.timezone` says. A local copy of lifecycle-for-laravel's
 * `@internal` cast: a Carbon of any zone is converted to UTC on write, so a host on
 * `America/New_York` stores the same instant as one on UTC.
 *
 * @implements CastsAttributes<CarbonImmutable|null, mixed>
 */
final class UtcDateTime implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }

        $value = (string) $value;

        try {
            // A stored value (`Y-m-d H:i:s`, maybe with fractions) is UTC. During toArray(),
            // Eloquent hands a timestamp column back already serialized (ISO-8601 with its offset).
            $parsed = str_contains($value, 'T')
                ? CarbonImmutable::parse($value)->utc()
                : CarbonImmutable::createFromFormat(Clock::FORMAT, substr($value, 0, 19), 'UTC');
        } catch (Throwable) {
            $parsed = null;
        }

        return $parsed instanceof CarbonImmutable ? $parsed : throw InvalidEventException::invalidDateTime($key);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof DateTimeInterface => Clock::format($value),
            is_string($value) && CarbonImmutable::hasFormat($value, Clock::FORMAT) => $value,
            default => throw InvalidEventException::invalidDateTime($key),
        };
    }
}
