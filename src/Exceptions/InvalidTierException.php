<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Exceptions;

/**
 * A ticket type write the rules refuse.
 */
final class InvalidTierException extends EventsException
{
    public static function nameRequired(): self
    {
        return self::make('invalid_tier', 422, [], 'tier_name_required');
    }

    public static function invalidKey(string $key): self
    {
        return self::make('invalid_tier', 422, ['key' => $key], 'tier_invalid_key');
    }

    public static function keyTaken(string $key): self
    {
        return self::make('tier_key_taken', 422, ['key' => $key], 'tier_key_taken');
    }

    public static function keyTakenByTrashed(string $key): self
    {
        return self::make('tier_key_taken', 422, ['key' => $key], 'tier_key_taken_by_trashed');
    }

    public static function inUse(): self
    {
        return self::make('tier_in_use', 422, [], 'tier_in_use');
    }

    public static function invalidPartyLimits(): self
    {
        return self::make('invalid_tier', 422, [], 'tier_invalid_party_limits');
    }

    public static function invalidSaleWindow(): self
    {
        return self::make('invalid_tier', 422, [], 'tier_invalid_sale_window');
    }

    public static function negativeCapacity(): self
    {
        return self::make('invalid_tier', 422, [], 'tier_negative_capacity');
    }

    public static function negativePosition(): self
    {
        return self::make('invalid_tier', 422, [], 'tier_negative_position');
    }

    public static function invalidPrice(): self
    {
        return self::make('invalid_tier', 422, [], 'tier_invalid_price');
    }

    public static function reorderMismatch(): self
    {
        return self::make('invalid_tier', 422, [], 'tier_reorder_mismatch');
    }
}
