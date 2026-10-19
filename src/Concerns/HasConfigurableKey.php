<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUniqueStringIds;
use Illuminate\Support\Str;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;

/**
 * Drives the event's primary key from `events.primary_key_type`, matching the column the
 * migrations emitted for the same value. This is the **inbound** key: lifecycle subjects, tags
 * taggables, media owners and slug history point at it, so it must equal `lifecycle.key_type`
 * and `tags.key_type`.
 *
 * Laravel's {@see HasUniqueStringIds} keys `getKeyType()`, `getIncrementing()`, `uniqueIds()` and
 * route-binding validation off the single `$usesUniqueIds` flag, so flipping that flag is the
 * whole seam: on `bigint` the model behaves exactly as if it had never used the trait.
 */
trait HasConfigurableKey
{
    use HasUniqueStringIds;

    public function initializeHasUniqueStringIds(): void
    {
        $this->usesUniqueIds = $this->configuredKeyType() !== KeyType::BigInt;
    }

    /**
     * A time-ordered key of the configured type (`uuid7` or `ulid`), so id-ordered chunks keep
     * creation order. Never called on `bigint` — the database mints those.
     */
    public function newUniqueId(): string
    {
        return $this->configuredKeyType() === KeyType::Ulid
            ? (string) Str::ulid()
            : (string) Str::uuid7();
    }

    public function configuredKeyType(): KeyType
    {
        return KeyType::fromConfig('events.primary_key_type');
    }

    protected function isValidUniqueId(mixed $value): bool
    {
        return $this->configuredKeyType() === KeyType::Ulid
            ? Str::isUlid($value)
            : Str::isUuid($value);
    }
}
