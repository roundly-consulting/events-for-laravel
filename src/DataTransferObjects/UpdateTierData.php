<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\DataTransferObjects;

use Carbon\CarbonInterface;
use RoundlyConsulting\Events\Support\NotGiven;
use RoundlyConsulting\Money\Money;

/**
 * A ticket type change: only the fields given change (`null` clears). A price change affects
 * later holds only; a lower capacity bumps nobody (new requests wait); a higher one lets the
 * waitlist move up.
 */
final readonly class UpdateTierData
{
    /**
     * @param  array<string, string>|string|NotGiven  $name
     * @param  array<string, string>|string|NotGiven|null  $description
     */
    public function __construct(
        public array|string|NotGiven $name = NotGiven::Value,
        public string|NotGiven $key = NotGiven::Value,
        public Money|string|NotGiven|null $price = NotGiven::Value,
        public int|NotGiven|null $capacity = NotGiven::Value,
        public CarbonInterface|string|NotGiven|null $saleStartsAt = NotGiven::Value,
        public CarbonInterface|string|NotGiven|null $saleEndsAt = NotGiven::Value,
        public int|NotGiven $minParty = NotGiven::Value,
        public int|NotGiven|null $maxParty = NotGiven::Value,
        public bool|NotGiven $hidden = NotGiven::Value,
        public array|string|NotGiven|null $description = NotGiven::Value,
    ) {}
}
