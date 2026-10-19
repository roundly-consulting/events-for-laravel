<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RoundlyConsulting\Events\Exceptions\InvalidEventsConfigurationException;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;

/**
 * The fail-fast check before a host model's key reaches a morph column: an organizer, attendee,
 * co-host, invitee, actor or venue owner whose key does not fit `events.key_type` throws naming
 * the key — instead of PostgreSQL's raw `invalid input syntax for type bigint`.
 *
 * @internal
 */
final class KeyTypes
{
    public static function assertMorphable(?Model $model, string $configKey = 'events.key_type'): void
    {
        if ($model === null) {
            return;
        }

        $type = KeyType::fromConfig($configKey);
        $key = $model->getKey();

        $fits = match ($type) {
            KeyType::BigInt => is_int($key) || (is_string($key) && ctype_digit($key)),
            KeyType::Uuid => is_string($key) && Str::isUuid($key),
            KeyType::Ulid => is_string($key) && Str::isUlid($key),
        };

        if (! $fits) {
            throw InvalidEventsConfigurationException::keyTypeMismatch($configKey, class_basename($model), $type->value);
        }
    }
}
