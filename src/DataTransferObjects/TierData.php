<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\DataTransferObjects;

use Carbon\CarbonInterface;
use RoundlyConsulting\Money\Money;

/**
 * A new ticket type. `name` is a locale map or a string; `key` defaults to the slug of the
 * fallback-locale name. `price` null (or zero) is free; a numeric string is read in
 * `events.tiers.default_currency`. Sale-window strings are wall-clock times in the event's
 * timezone. `maxParty` (the attendee plus guests) replaces the event's `max_guests` for this
 * ticket type.
 */
final readonly class TierData
{
    /**
     * @param  array<string, string>|string  $name
     * @param  array<string, string>|string|null  $description
     */
    public function __construct(
        public array|string $name,
        public ?string $key = null,
        public Money|string|null $price = null,
        public ?int $capacity = null,
        public CarbonInterface|string|null $saleStartsAt = null,
        public CarbonInterface|string|null $saleEndsAt = null,
        public int $minParty = 1,
        public ?int $maxParty = null,
        public bool $hidden = false,
        public ?int $position = null,
        public array|string|null $description = null,
    ) {}
}
